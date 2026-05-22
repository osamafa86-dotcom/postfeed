import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:timeago/timeago.dart' as timeago;
import 'package:url_launcher/url_launcher.dart';

import '../../../core/models/evolving_story.dart';
import '../../../core/utils/safe_launch.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

final _storyDetailProvider = FutureProvider.family((ref, String slug) {
  return ref.watch(contentRepositoryProvider).evolvingStory(slug);
});

final _storyQuotesProvider = FutureProvider.family((ref, String slug) {
  return ref.watch(contentRepositoryProvider).storyQuotes(slug);
});

class EvolvingStoryScreen extends ConsumerWidget {
  const EvolvingStoryScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(_storyDetailProvider(slug));
    final quotes = ref.watch(_storyQuotesProvider(slug));

    return Scaffold(
      body: detail.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_storyDetailProvider(slug)),
        ),
        data: (d) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(_storyDetailProvider(slug));
            ref.invalidate(_storyQuotesProvider(slug));
          },
          child: _StoryBody(
            story: d.story,
            articles: d.articles,
            quotes: quotes.maybeWhen(data: (q) => q, orElse: () => const []),
          ),
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// STORY BODY
// ═══════════════════════════════════════════════════════════════

class _StoryBody extends StatelessWidget {
  const _StoryBody({required this.story, required this.articles, required this.quotes});
  final EvolvingStory story;
  final List articles;
  final List<StoryQuote> quotes;

  Color get _accent {
    try {
      return Color(int.parse(story.accentColor.replaceAll('#', '0xFF')));
    } catch (_) {
      return const Color(0xFF0D9488);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final accent = _accent;

    // Count distinct sources
    final sourceSet = <String>{};
    for (final a in articles) {
      if (a.source?.name != null) sourceSet.add(a.source!.name);
    }

    return CustomScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [
        // ── Hero Section ──
        SliverToBoxAdapter(
          child: SizedBox(
            height: 320,
            child: Stack(
              fit: StackFit.expand,
              children: [
                // Cover image
                if (story.coverImage != null)
                  CachedNetworkImage(
                    imageUrl: story.coverImage!,
                    fit: BoxFit.cover,
                    color: Colors.black.withOpacity(0.5),
                    colorBlendMode: BlendMode.darken,
                  )
                else if (articles.isNotEmpty && articles.first.imageUrl != null)
                  CachedNetworkImage(
                    imageUrl: articles.first.imageUrl!,
                    fit: BoxFit.cover,
                    color: Colors.black.withOpacity(0.5),
                    colorBlendMode: BlendMode.darken,
                  )
                else
                  Container(color: const Color(0xFF2C2416)),

                // Shade overlay
                DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      stops: const [0.0, 0.3, 1.0],
                      colors: [
                        Colors.black.withOpacity(0.4),
                        Colors.transparent,
                        Colors.black.withOpacity(0.85),
                      ],
                    ),
                  ),
                ),

                // Accent bar at top
                Positioned(top: 0, left: 0, right: 0,
                  child: Container(height: 6, color: accent)),

                // Back button
                Positioned(
                  top: MediaQuery.of(context).padding.top + 8,
                  right: 12,
                  child: CircleAvatar(
                    backgroundColor: Colors.black.withOpacity(0.3),
                    child: IconButton(
                      icon: const Icon(Icons.arrow_forward_ios, color: Colors.white, size: 18),
                      onPressed: () => Navigator.of(context).pop(),
                    ),
                  ),
                ),

                // Content
                Positioned(
                  bottom: 24, right: 20, left: 20,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Icon + LIVE badge row
                      Row(
                        children: [
                          Container(
                            width: 60, height: 60,
                            decoration: BoxDecoration(
                              color: Colors.white.withOpacity(0.95),
                              borderRadius: BorderRadius.circular(16),
                              boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.3), blurRadius: 16)],
                            ),
                            alignment: Alignment.center,
                            child: Text(story.icon.isNotEmpty ? story.icon : '📅',
                              style: const TextStyle(fontSize: 32)),
                          ),
                          const SizedBox(width: 12),
                          if (story.isLive)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                              decoration: BoxDecoration(
                                color: const Color(0xFFDC2626),
                                borderRadius: BorderRadius.circular(999),
                              ),
                              child: Row(mainAxisSize: MainAxisSize.min, children: [
                                Container(width: 8, height: 8,
                                  decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle)),
                                const SizedBox(width: 6),
                                const Text('متابعة حيّة',
                                  style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w800)),
                              ]),
                            ),
                        ],
                      ),
                      const SizedBox(height: 14),

                      // Title
                      Text(story.name,
                        style: const TextStyle(
                          color: Colors.white, fontSize: 28, fontWeight: FontWeight.w900,
                          height: 1.3,
                          shadows: [Shadow(color: Colors.black54, blurRadius: 10)],
                        )),
                      const SizedBox(height: 10),

                      // Description
                      if (story.description != null && story.description!.isNotEmpty)
                        Text(story.description!,
                          style: TextStyle(
                            color: Colors.white.withOpacity(0.85), fontSize: 14, height: 1.7),
                          maxLines: 3, overflow: TextOverflow.ellipsis),
                      const SizedBox(height: 14),

                      // Stats row
                      Row(children: [
                        _StatChip(emoji: '📰', value: '${story.articleCount}', label: 'تقرير'),
                        const SizedBox(width: 12),
                        if (sourceSet.isNotEmpty)
                          _StatChip(emoji: '🌐', value: '${sourceSet.length}', label: 'مصدر'),
                        if (sourceSet.isNotEmpty) const SizedBox(width: 12),
                        if (story.lastMatchedAt != null)
                          Text('↻ ${timeago.format(story.lastMatchedAt!, locale: 'ar')}',
                            style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 12, fontWeight: FontWeight.w600)),
                      ]),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),

        // ── Action Buttons Row ──
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
            child: Column(children: [
              Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => context.push('/stories/${story.slug}/quotes?name=${Uri.encodeComponent(story.name)}'),
                    icon: const Text('💬', style: TextStyle(fontSize: 14)),
                    label: const Text('جدار الاقتباسات', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: accent,
                      side: BorderSide(color: accent.withOpacity(0.3)),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () {
                      final url = 'https://feedsnews.net/evolving-story/${story.slug}/book?print=1';
                      safeLaunch(context, url, mode: LaunchMode.externalApplication);
                    },
                    icon: const Text('📖', style: TextStyle(fontSize: 14)),
                    label: const Text('صدِّر ككتاب', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: accent,
                      side: BorderSide(color: accent.withOpacity(0.3)),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
              ]),
              const SizedBox(height: 8),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: () => context.push('/stories-network'),
                  icon: const Icon(Icons.hub, size: 16),
                  label: const Text('شبكة القصص المترابطة', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: isDark ? Colors.white54 : AppColors.textMutedLight,
                    side: BorderSide(color: isDark ? Colors.white.withOpacity(0.1) : const Color(0xFFE2E8F0)),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                ),
              ),
            ]),
          ),
        ),

        // ── Quotes Section ──
        if (quotes.isNotEmpty) ...[
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 24, 16, 12),
              child: Row(children: [
                Text('💬', style: const TextStyle(fontSize: 18)),
                const SizedBox(width: 8),
                Text('من أبرز الأقوال',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                    color: isDark ? Colors.white : AppColors.textLight)),
              ]),
            ),
          ),
          SliverToBoxAdapter(
            child: SizedBox(
              height: 180,
              child: ListView.builder(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                itemCount: quotes.length,
                itemBuilder: (_, i) {
                  final q = quotes[i];
                  final sp = q.speaker ?? '';
                  final initial = sp.isNotEmpty ? sp.substring(0, 1) : '؟';
                  return Container(
                    width: 280,
                    margin: const EdgeInsets.only(left: 10),
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: isDark ? const Color(0xFF111827) : Colors.white,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE0E3E8)),
                      boxShadow: [BoxShadow(
                        color: Colors.black.withOpacity(0.05),
                        blurRadius: 10, offset: const Offset(0, 3))],
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        // Quote mark
                        Text('"', style: TextStyle(
                          fontSize: 40, fontWeight: FontWeight.w900,
                          color: accent.withOpacity(0.6), height: 0.5)),
                        const SizedBox(height: 8),
                        Expanded(
                          child: Text(q.quote,
                            style: TextStyle(
                              fontSize: 13, height: 1.7, fontWeight: FontWeight.w500,
                              color: isDark ? Colors.white : AppColors.textLight),
                            maxLines: 4, overflow: TextOverflow.ellipsis),
                        ),
                        const SizedBox(height: 8),
                        // Attribution
                        Row(children: [
                          Container(
                            width: 30, height: 30,
                            decoration: BoxDecoration(
                              gradient: LinearGradient(colors: [accent, const Color(0xFFF59E0B)]),
                              shape: BoxShape.circle,
                            ),
                            alignment: Alignment.center,
                            child: Text(initial,
                              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 13)),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(q.speaker ?? '—',
                                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w900,
                                    color: isDark ? Colors.white : AppColors.textLight),
                                  overflow: TextOverflow.ellipsis),
                                if (q.context != null)
                                  Text(q.context!,
                                    style: TextStyle(fontSize: 10,
                                      color: isDark ? Colors.white38 : AppColors.textMutedLight),
                                    overflow: TextOverflow.ellipsis),
                              ],
                            ),
                          ),
                        ]),
                      ],
                    ),
                  );
                },
              ),
            ),
          ),
        ],

        // ── Timeline header ──
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 24, 16, 12),
            child: Row(children: [
              Text('🕰️', style: const TextStyle(fontSize: 18)),
              const SizedBox(width: 8),
              Text('تطوّر القصة',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                  color: isDark ? Colors.white : AppColors.textLight)),
              const Spacer(),
              Text('${articles.length} تقرير',
                style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700,
                  color: isDark ? Colors.white38 : AppColors.textMutedLight)),
            ]),
          ),
        ),

        // ── Articles, grouped by date as a vertical timeline ──
        if (articles.isEmpty)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Center(
                child: Column(children: [
                  const Text('📭', style: TextStyle(fontSize: 48)),
                  const SizedBox(height: 12),
                  Text('لا توجد تقارير مرتبطة بعد',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700,
                      color: isDark ? Colors.white : AppColors.textLight)),
                  const SizedBox(height: 6),
                  Text('سيبدأ النظام تلقائياً بتغذية هذه القصة.',
                    style: TextStyle(color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                ]),
              ),
            ),
          )
        else
          SliverToBoxAdapter(
            child: _ArticlesTimeline(articles: articles, accent: accent),
          ),

        const SliverToBoxAdapter(child: SizedBox(height: 32)),
      ],
    );
  }
}

/// Vertical timeline of articles grouped by day. RTL-aware: the line
/// runs on the right side, dots mark each article, day headers float
/// across as section breaks. Newest day first.
class _ArticlesTimeline extends StatelessWidget {
  const _ArticlesTimeline({required this.articles, required this.accent});
  final List articles;
  final Color accent;

  String _dayLabel(DateTime when) {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final day = DateTime(when.year, when.month, when.day);
    final diff = today.difference(day).inDays;
    if (diff == 0) return 'اليوم';
    if (diff == 1) return 'أمس';
    if (diff < 7) return 'قبل $diff أيام';
    const months = [
      '', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
      'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'
    ];
    final monthName = (when.month >= 1 && when.month <= 12) ? months[when.month] : '';
    return '${when.day} $monthName';
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    // Group by day. Articles without a date fall under "بدون تاريخ".
    final groups = <String, List>{};
    final order = <String>[];
    for (final a in articles) {
      final dt = a.publishedAt as DateTime?;
      final key = dt == null
          ? 'بدون تاريخ'
          : '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';
      if (!groups.containsKey(key)) {
        groups[key] = [];
        order.add(key);
      }
      groups[key]!.add(a);
    }

    final children = <Widget>[];
    for (var g = 0; g < order.length; g++) {
      final key = order[g];
      final dayArticles = groups[key]!;
      final firstDt = dayArticles.first.publishedAt as DateTime?;
      final label = firstDt != null ? _dayLabel(firstDt) : key;

      children.add(_DayHeader(label: label, count: dayArticles.length, accent: accent));

      for (var i = 0; i < dayArticles.length; i++) {
        final isLastInDay = i == dayArticles.length - 1;
        final isVeryLast = g == order.length - 1 && isLastInDay;
        children.add(_TimelineRow(
          accent: accent,
          isDark: isDark,
          showLine: !isVeryLast,
          child: ArticleCard(article: dayArticles[i]),
        ));
      }
    }

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Column(children: children),
    );
  }
}

class _DayHeader extends StatelessWidget {
  const _DayHeader({required this.label, required this.count, required this.accent});
  final String label;
  final int count;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Padding(
      padding: const EdgeInsets.only(top: 18, bottom: 10),
      child: Row(
        children: [
          Container(
            width: 14,
            height: 14,
            decoration: BoxDecoration(
              color: accent,
              shape: BoxShape.circle,
              border: Border.all(
                color: isDark ? const Color(0xFF1A1A1A) : Colors.white,
                width: 3,
              ),
              boxShadow: [
                BoxShadow(color: accent.withOpacity(0.35), blurRadius: 10, spreadRadius: 1),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Text(
            label,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w900,
              color: isDark ? Colors.white : AppColors.textLight,
            ),
          ),
          const SizedBox(width: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
            decoration: BoxDecoration(
              color: accent.withOpacity(isDark ? 0.20 : 0.12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Text(
              '$count',
              style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: accent),
            ),
          ),
          const Spacer(),
          Expanded(
            child: Container(
              height: 1,
              color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE7E9EE),
            ),
          ),
        ],
      ),
    );
  }
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow({
    required this.accent,
    required this.isDark,
    required this.showLine,
    required this.child,
  });
  final Color accent;
  final bool isDark;
  final bool showLine;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 30,
            child: Column(
              children: [
                const SizedBox(height: 10),
                Container(
                  width: 9,
                  height: 9,
                  decoration: BoxDecoration(
                    color: accent.withOpacity(0.85),
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: isDark ? const Color(0xFF1A1A1A) : Colors.white,
                      width: 2,
                    ),
                  ),
                ),
                if (showLine)
                  Expanded(
                    child: Container(
                      width: 2,
                      color: isDark
                          ? Colors.white.withOpacity(0.08)
                          : const Color(0xFFE7E9EE),
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: child,
            ),
          ),
        ],
      ),
    );
  }
}

// ── Small stat chip for hero section ──
class _StatChip extends StatelessWidget {
  const _StatChip({required this.emoji, required this.value, required this.label});
  final String emoji;
  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: Colors.white.withOpacity(0.2)),
      ),
      child: Text('$emoji $value $label',
        style: const TextStyle(
          color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
    );
  }
}
