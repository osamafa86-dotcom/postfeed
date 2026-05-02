import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart' show Share;

import '../../../core/api/api_client.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';

// ── Model ──

class _WeeklyRewind {
  const _WeeklyRewind({
    required this.title,
    required this.weekStart,
    required this.weekEnd,
    required this.summary,
    required this.topStories,
    this.archive = const [],
  });
  final String title, weekStart, weekEnd, summary;
  final List<_WeeklyStory> topStories;
  final List<_ArchiveEntry> archive;

  factory _WeeklyRewind.fromJson(Map<String, dynamic> j) => _WeeklyRewind(
        title: j['title']?.toString() ?? 'مراجعة الأسبوع',
        weekStart: j['week_start']?.toString() ?? '',
        weekEnd: j['week_end']?.toString() ?? '',
        summary: j['summary']?.toString() ?? '',
        topStories: (j['top_stories'] as List? ?? [])
            .whereType<Map>()
            .map((m) => _WeeklyStory.fromJson(m.cast()))
            .toList(),
        archive: (j['archive'] as List? ?? [])
            .whereType<Map>()
            .map((m) => _ArchiveEntry.fromJson(m.cast()))
            .toList(),
      );
}

class _WeeklyStory {
  const _WeeklyStory({required this.title, required this.summary, this.articleId, this.imageUrl, this.category});
  final String title, summary;
  final int? articleId;
  final String? imageUrl, category;

  factory _WeeklyStory.fromJson(Map<String, dynamic> j) => _WeeklyStory(
        title: j['title']?.toString() ?? '',
        summary: j['summary']?.toString() ?? '',
        articleId: (j['article_id'] as num?)?.toInt(),
        imageUrl: j['image_url'] as String?,
        category: j['category'] as String?,
      );
}

class _ArchiveEntry {
  const _ArchiveEntry({required this.weekKey, required this.title, required this.weekStart, required this.weekEnd});
  final String weekKey, title, weekStart, weekEnd;

  factory _ArchiveEntry.fromJson(Map<String, dynamic> j) => _ArchiveEntry(
        weekKey: j['week_key']?.toString() ?? '',
        title: j['title']?.toString() ?? '',
        weekStart: j['week_start']?.toString() ?? '',
        weekEnd: j['week_end']?.toString() ?? '',
      );
}

// ── Provider ──

final _weeklyProvider = FutureProvider<_WeeklyRewind>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/weekly-rewind',
      decode: (d) => (d as Map).cast<String, dynamic>());
  return _WeeklyRewind.fromJson(res.data!);
});

// ── Screen ──

class WeeklyRewindScreen extends ConsumerWidget {
  const WeeklyRewindScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_weeklyProvider);
    return Scaffold(
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: 'تعذّر تحميل مراجعة الأسبوع\n$e',
          onRetry: () => ref.invalidate(_weeklyProvider),
        ),
        data: (rewind) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(_weeklyProvider),
          child: _WeeklyBody(rewind: rewind),
        ),
      ),
    );
  }
}

class _WeeklyBody extends StatelessWidget {
  const _WeeklyBody({required this.rewind});
  final _WeeklyRewind rewind;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final theme = Theme.of(context);

    return CustomScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [
        // ── Hero Header ──
        SliverToBoxAdapter(
          child: Container(
            padding: EdgeInsets.fromLTRB(20, MediaQuery.of(context).padding.top + 16, 20, 24),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topRight,
                end: Alignment.bottomLeft,
                colors: isDark
                    ? [const Color(0xFF1E1B4B), const Color(0xFF312E81)]
                    : [const Color(0xFF6366F1), const Color(0xFF4338CA)],
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Back + Share row
                Row(children: [
                  GestureDetector(
                    onTap: () => Navigator.of(context).pop(),
                    child: Container(
                      width: 38, height: 38,
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      alignment: Alignment.center,
                      child: const Icon(Icons.arrow_forward_ios, color: Colors.white, size: 16),
                    ),
                  ),
                  const Spacer(),
                  GestureDetector(
                    onTap: () => Share.share('${rewind.title}\n${rewind.summary.substring(0, (rewind.summary.length).clamp(0, 120))}...\nhttps://feedsnews.net/weekly'),
                    child: Container(
                      width: 38, height: 38,
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      alignment: Alignment.center,
                      child: const Icon(Icons.share_outlined, color: Colors.white, size: 18),
                    ),
                  ),
                ]),
                const SizedBox(height: 24),

                // Magazine badge
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.2),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    const Text('📰', style: TextStyle(fontSize: 14)),
                    const SizedBox(width: 6),
                    Text('${rewind.weekStart} — ${rewind.weekEnd}',
                      style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                  ]),
                ),
                const SizedBox(height: 16),

                // Title
                Text(rewind.title,
                  style: const TextStyle(
                    color: Colors.white, fontSize: 28, fontWeight: FontWeight.w900,
                    height: 1.3,
                  )),
                const SizedBox(height: 14),

                // Summary (first paragraph)
                Text(rewind.summary,
                  style: TextStyle(
                    color: Colors.white.withOpacity(0.85), fontSize: 15, height: 1.8),
                  maxLines: 6, overflow: TextOverflow.ellipsis),

                const SizedBox(height: 16),
                // Stats
                Row(children: [
                  _StatPill(icon: Icons.article_outlined, text: '${rewind.topStories.length} قصة'),
                  const SizedBox(width: 8),
                  _StatPill(icon: Icons.auto_awesome, text: 'تلخيص AI'),
                ]),
              ],
            ),
          ),
        ),

        // ── Top Stories ──
        if (rewind.topStories.isNotEmpty)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 24, 16, 12),
              child: Row(children: [
                const Text('🔥', style: TextStyle(fontSize: 18)),
                const SizedBox(width: 8),
                Text('أبرز القصص',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                    color: isDark ? Colors.white : AppColors.textLight)),
              ]),
            ),
          ),

        SliverPadding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          sliver: SliverList.builder(
            itemCount: rewind.topStories.length,
            itemBuilder: (_, i) {
              final s = rewind.topStories[i];
              return _StoryCard(story: s, index: i, isDark: isDark);
            },
          ),
        ),

        // ── Archive ──
        if (rewind.archive.isNotEmpty) ...[
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 28, 16, 12),
              child: Row(children: [
                const Text('📚', style: TextStyle(fontSize: 18)),
                const SizedBox(width: 8),
                Text('الأعداد السابقة',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                    color: isDark ? Colors.white : AppColors.textLight)),
              ]),
            ),
          ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
            sliver: SliverList.separated(
              itemCount: rewind.archive.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (_, i) {
                final a = rewind.archive[i];
                return Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
                  ),
                  child: Row(children: [
                    Container(
                      width: 44, height: 44,
                      decoration: BoxDecoration(
                        color: const Color(0xFF6366F1).withOpacity(0.1),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      alignment: Alignment.center,
                      child: const Text('📰', style: TextStyle(fontSize: 20)),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(a.title,
                            style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700,
                              color: isDark ? Colors.white : AppColors.textLight),
                            maxLines: 1, overflow: TextOverflow.ellipsis),
                          const SizedBox(height: 4),
                          Text('${a.weekStart} — ${a.weekEnd}',
                            style: TextStyle(fontSize: 11,
                              color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                        ],
                      ),
                    ),
                    Icon(Icons.chevron_left, size: 20,
                      color: isDark ? Colors.white38 : AppColors.textMutedLight),
                  ]),
                );
              },
            ),
          ),
        ],

        const SliverToBoxAdapter(child: SizedBox(height: 32)),
      ],
    );
  }
}

class _StoryCard extends StatelessWidget {
  const _StoryCard({required this.story, required this.index, required this.isDark});
  final _WeeklyStory story;
  final int index;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () {
        if (story.articleId != null) context.push('/article/${story.articleId}');
      },
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Number badge
            Container(
              width: 32, height: 32,
              decoration: BoxDecoration(
                gradient: index == 0
                    ? const LinearGradient(colors: [Color(0xFF6366F1), Color(0xFF4338CA)])
                    : null,
                color: index > 0
                    ? (isDark ? Colors.white.withOpacity(0.08) : const Color(0xFFF1F5F9))
                    : null,
                borderRadius: BorderRadius.circular(10),
              ),
              alignment: Alignment.center,
              child: Text('${index + 1}',
                style: TextStyle(
                  color: index == 0 ? Colors.white : (isDark ? Colors.white54 : AppColors.textMutedLight),
                  fontWeight: FontWeight.w800, fontSize: 14)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (story.category != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(
                          color: const Color(0xFF6366F1).withOpacity(0.1),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(story.category!,
                          style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700,
                            color: Color(0xFF6366F1))),
                      ),
                    ),
                  Text(story.title,
                    style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, height: 1.4,
                      color: isDark ? Colors.white : AppColors.textLight),
                    maxLines: 2, overflow: TextOverflow.ellipsis),
                  const SizedBox(height: 8),
                  Text(story.summary,
                    style: TextStyle(fontSize: 13, height: 1.7,
                      color: isDark ? Colors.white54 : AppColors.textMutedLight),
                    maxLines: 3, overflow: TextOverflow.ellipsis),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatPill extends StatelessWidget {
  const _StatPill({required this.icon, required this.text});
  final IconData icon;
  final String text;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 14, color: Colors.white70),
        const SizedBox(width: 4),
        Text(text, style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
      ]),
    );
  }
}
