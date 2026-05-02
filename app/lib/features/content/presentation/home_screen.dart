import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:timeago/timeago.dart' as timeago;
import 'package:url_launcher/url_launcher.dart';

import '../../../core/models/article.dart';
import '../../../core/models/evolving_story.dart';
import '../../../core/models/home_payload.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../../../core/widgets/section_header.dart';
import '../../auth/data/auth_storage.dart';
import '../../media/data/media_repository.dart';
import '../data/content_repository.dart';
import 'widgets/breaking_strip.dart';
import 'widgets/source_chips_rail.dart';
import '../../../core/widgets/currency_widget.dart';
import 'widgets/ticker_bar.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final home = ref.watch(homeProvider);
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        title: const Text(
          'فيد نيوز',
          style: TextStyle(
            color: Colors.white, fontWeight: FontWeight.w900, fontSize: 20,
            shadows: [Shadow(color: Colors.black38, blurRadius: 6)],
          ),
        ),
        leading: IconButton(
          icon: const Icon(Icons.search_rounded, color: Colors.white,
            shadows: [Shadow(color: Colors.black38, blurRadius: 6)]),
          onPressed: () => context.push('/search'),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications_outlined, color: Colors.white,
              shadows: [Shadow(color: Colors.black38, blurRadius: 6)]),
            onPressed: () => context.push('/notifications'),
          ),
        ],
      ),
      body: home.when(
        loading: () => const LoadingShimmerList(itemCount: 6),
        error: (e, _) => ErrorRetryView(
          message: 'تعذّر تحميل الرئيسية\n$e',
          onRetry: () => ref.invalidate(homeProvider),
        ),
        data: (data) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(homeProvider),
          child: _HomeBody(payload: data),
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// HOME BODY
// ═══════════════════════════════════════════════════════════════

class _HomeBody extends ConsumerWidget {
  const _HomeBody({required this.payload});
  final HomePayload payload;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return CustomScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [
        if (payload.ticker.isNotEmpty)
          SliverToBoxAdapter(child: TickerBar(items: payload.ticker)),

        if (payload.breaking.isNotEmpty)
          SliverToBoxAdapter(child: BreakingStrip(items: payload.breaking)),

        // ── Stats Strip ──
        SliverToBoxAdapter(child: _StatsStrip(payload: payload)),

        // ── For You — personalized feed ──
        if (AuthStorage.isAuthenticated)
          SliverToBoxAdapter(child: _ForYouSection()),

        // ── Hero Card ──
        if (payload.hero != null)
          SliverToBoxAdapter(child: _HeroCard(article: payload.hero!)),

        // ── Categories Box — premium tabbed design ──
        if (payload.buckets.isNotEmpty)
          SliverToBoxAdapter(child: _CategoriesBox(buckets: payload.buckets)),

        // ── Quick Access — AI + Morning Briefing ──
        SliverToBoxAdapter(child: _QuickAccessRow()),

        // ── Evolving Stories — قصص متطورة ──
        SliverToBoxAdapter(child: _EvolvingStoriesSection()),

        // ── Platforms Box — Telegram / X / YouTube ──
        SliverToBoxAdapter(child: _PlatformsBox()),

        // ── Currency Rates ──
        const SliverToBoxAdapter(child: CurrencyWidget()),

        // ── Latest News ──
        SliverToBoxAdapter(
          child: SectionHeader(title: 'آخر الأخبار', icon: Icons.fiber_new),
        ),
        SliverPadding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          sliver: SliverList.separated(
            itemCount: payload.latest.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (_, i) => ArticleCard(article: payload.latest[i]),
          ),
        ),

        // ── Sources ──
        if (payload.sources.isNotEmpty) ...[
          SliverToBoxAdapter(
            child: SectionHeader(title: 'مصادر الأخبار', icon: Icons.public,
              onMore: () => context.push('/discover')),
          ),
          SliverToBoxAdapter(child: SourceChipsRail(sources: payload.sources)),
        ],

        // ── Trending ──
        if (payload.trends.isNotEmpty) ...[
          SliverToBoxAdapter(
            child: SectionHeader(title: 'الأكثر تداولاً', icon: Icons.trending_up,
              onMore: () => context.push('/trending')),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
              child: Wrap(spacing: 8, runSpacing: 8, children: [
                for (final t in payload.trends)
                  ActionChip(
                    label: Text('# ${t.title}'),
                    onPressed: () => context.push('/search?q=${Uri.encodeComponent(t.title)}'),
                  ),
              ]),
            ),
          ),
        ],

        const SliverToBoxAdapter(child: SizedBox(height: 24)),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// HERO CARD — full-bleed with triple gradient
// ═══════════════════════════════════════════════════════════════

class _HeroCard extends StatelessWidget {
  const _HeroCard({required this.article});
  final Article article;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => context.push('/article/${article.id}'),
      child: SizedBox(
        height: 340,
        child: Stack(fit: StackFit.expand, children: [
          if (article.imageUrl != null)
            CachedNetworkImage(imageUrl: article.imageUrl!, fit: BoxFit.cover)
          else
            Container(color: AppColors.primary.withOpacity(0.2)),
          // Triple gradient overlay
          DecoratedBox(decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter, end: Alignment.bottomCenter,
              stops: const [0.0, 0.35, 1.0],
              colors: [Colors.black.withOpacity(0.3), Colors.transparent, Colors.black.withOpacity(0.92)],
            ),
          )),
          Positioned(left: 20, right: 20, bottom: 24,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (article.category != null)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                  decoration: BoxDecoration(color: AppColors.breaking, borderRadius: BorderRadius.circular(6)),
                  child: Text('${article.category!.icon ?? ''} ${article.category!.name}'.trim(),
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12)),
                ),
              const SizedBox(height: 12),
              Text(article.title,
                style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800, height: 1.5,
                  shadows: [Shadow(color: Colors.black54, blurRadius: 8, offset: Offset(0, 2))]),
                maxLines: 3, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 12),
              if (article.source != null)
                Row(children: [
                  Container(width: 28, height: 28,
                    decoration: BoxDecoration(color: Colors.white.withOpacity(0.2), borderRadius: BorderRadius.circular(8)),
                    alignment: Alignment.center,
                    child: Text(article.source!.logoLetter ?? '',
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13)),
                  ),
                  const SizedBox(width: 8),
                  Text(article.source!.name,
                    style: const TextStyle(color: Color(0xFF38BDF8), fontSize: 13, fontWeight: FontWeight.w600)),
                  Container(margin: const EdgeInsets.symmetric(horizontal: 8), width: 4, height: 4,
                    decoration: BoxDecoration(color: Colors.white.withOpacity(0.5), shape: BoxShape.circle)),
                  if (article.publishedAt != null)
                    Text(timeago.format(article.publishedAt!, locale: 'ar'),
                      style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 12)),
                ]),
            ]),
          ),
        ]),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// CATEGORIES BOX — Premium glassmorphic tabbed design
// ═══════════════════════════════════════════════════════════════

class _CategoriesBox extends StatefulWidget {
  const _CategoriesBox({required this.buckets});
  final List<CategoryBucket> buckets;

  @override
  State<_CategoriesBox> createState() => _CategoriesBoxState();
}

class _CategoriesBoxState extends State<_CategoriesBox> {
  int _selected = 0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final bucket = widget.buckets[_selected];
    final articles = bucket.articles.take(5).toList();
    final color = AppColors.categoryColors[bucket.category.color] ?? AppColors.primary;

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 20, 16, 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: LinearGradient(
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
          colors: isDark
              ? [const Color(0xFF1A1A2E), const Color(0xFF16213E)]
              : [Colors.white, const Color(0xFFF8FAFC)],
        ),
        boxShadow: [
          BoxShadow(color: color.withOpacity(0.15), blurRadius: 24, offset: const Offset(0, 8)),
          BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 8, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(
        children: [
          // ── Header with gradient accent ──
          Container(
            padding: const EdgeInsets.fromLTRB(18, 16, 18, 0),
            child: Row(
              children: [
                Container(
                  width: 36, height: 36,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(colors: [color, color.withOpacity(0.7)]),
                    borderRadius: BorderRadius.circular(10),
                    boxShadow: [BoxShadow(color: color.withOpacity(0.3), blurRadius: 8, offset: const Offset(0, 3))],
                  ),
                  alignment: Alignment.center,
                  child: const Icon(Icons.grid_view_rounded, color: Colors.white, size: 18),
                ),
                const SizedBox(width: 10),
                Text('الأقسام', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17,
                  color: isDark ? Colors.white : AppColors.textLight)),
                const Spacer(),
                // Live dot indicator
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.green.withOpacity(0.1),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Container(width: 6, height: 6,
                      decoration: const BoxDecoration(color: Colors.green, shape: BoxShape.circle)),
                    const SizedBox(width: 4),
                    Text('مباشر', style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700,
                      color: Colors.green.shade700)),
                  ]),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),

          // ── Scrollable category pills ──
          SizedBox(
            height: 40,
            child: ListView.builder(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 14),
              itemCount: widget.buckets.length,
              itemBuilder: (_, i) {
                final c = widget.buckets[i].category;
                final catColor = AppColors.categoryColors[c.color] ?? AppColors.primary;
                final isActive = i == _selected;
                return Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 3),
                  child: GestureDetector(
                    onTap: () => setState(() => _selected = i),
                    child: AnimatedContainer(
                      duration: const Duration(milliseconds: 250),
                      curve: Curves.easeOutCubic,
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                      decoration: BoxDecoration(
                        gradient: isActive
                            ? LinearGradient(colors: [catColor, catColor.withOpacity(0.8)])
                            : null,
                        color: isActive ? null : (isDark ? Colors.white.withOpacity(0.06) : Colors.grey.withOpacity(0.08)),
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: isActive
                            ? [BoxShadow(color: catColor.withOpacity(0.35), blurRadius: 10, offset: const Offset(0, 3))]
                            : [],
                      ),
                      child: Text(
                        '${c.icon ?? ''} ${c.name}'.trim(),
                        style: TextStyle(
                          color: isActive ? Colors.white : (isDark ? Colors.white70 : AppColors.textMutedLight),
                          fontWeight: isActive ? FontWeight.w800 : FontWeight.w500,
                          fontSize: 13,
                        ),
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
          const SizedBox(height: 6),

          // ── Animated color bar ──
          AnimatedContainer(
            duration: const Duration(milliseconds: 300),
            margin: const EdgeInsets.symmetric(horizontal: 18),
            height: 3,
            decoration: BoxDecoration(
              gradient: LinearGradient(colors: [color, color.withOpacity(0.2), Colors.transparent]),
              borderRadius: BorderRadius.circular(2),
            ),
          ),

          // ── Articles list ──
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 250),
            child: ListView.builder(
              key: ValueKey(_selected),
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
              itemCount: articles.length,
              itemBuilder: (_, i) {
                final a = articles[i];
                return InkWell(
                  onTap: () => context.push('/article/${a.id}'),
                  borderRadius: BorderRadius.circular(12),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    child: Row(
                      children: [
                        // Numbered badge
                        Container(
                          width: 28, height: 28,
                          decoration: BoxDecoration(
                            gradient: i == 0
                                ? LinearGradient(colors: [color, color.withOpacity(0.7)])
                                : null,
                            color: i > 0 ? (isDark ? Colors.white.withOpacity(0.08) : Colors.grey.withOpacity(0.08)) : null,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          alignment: Alignment.center,
                          child: Text('${i + 1}',
                            style: TextStyle(
                              color: i == 0 ? Colors.white : (isDark ? Colors.white54 : AppColors.textMutedLight),
                              fontWeight: FontWeight.w800, fontSize: 13)),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(a.title,
                                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14, height: 1.4,
                                  color: isDark ? Colors.white : AppColors.textLight),
                                maxLines: 2, overflow: TextOverflow.ellipsis),
                              const SizedBox(height: 4),
                              Row(children: [
                                if (a.source != null)
                                  Text(a.source!.name, style: const TextStyle(fontSize: 11,
                                    color: Color(0xFF38BDF8), fontWeight: FontWeight.w600)),
                                if (a.source != null && a.publishedAt != null)
                                  Text(' • ', style: TextStyle(fontSize: 11,
                                    color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                                if (a.publishedAt != null)
                                  Text(timeago.format(a.publishedAt!, locale: 'ar'),
                                    style: TextStyle(fontSize: 11,
                                      color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                              ]),
                            ],
                          ),
                        ),
                        if (a.imageUrl != null) ...[
                          const SizedBox(width: 10),
                          ClipRRect(
                            borderRadius: BorderRadius.circular(10),
                            child: CachedNetworkImage(
                              imageUrl: a.imageUrl!, width: 64, height: 50, fit: BoxFit.cover),
                          ),
                        ],
                      ],
                    ),
                  ),
                );
              },
            ),
          ),

          // ── View all button ──
          InkWell(
            onTap: () => context.push('/category/${bucket.category.slug}'),
            borderRadius: const BorderRadius.vertical(bottom: Radius.circular(24)),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 14),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [color.withOpacity(0.08), color.withOpacity(0.03)]),
                borderRadius: const BorderRadius.vertical(bottom: Radius.circular(24)),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text('عرض كل أخبار ${bucket.category.name}',
                    style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 13)),
                  const SizedBox(width: 4),
                  Icon(Icons.arrow_back_ios_new, size: 12, color: color),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// PLATFORMS BOX — Dark premium with live feeds
// ═══════════════════════════════════════════════════════════════

class _PlatformsBox extends ConsumerStatefulWidget {
  @override
  ConsumerState<_PlatformsBox> createState() => _PlatformsBoxState();
}

class _PlatformsBoxState extends ConsumerState<_PlatformsBox> {
  int _selected = 0;

  static const _platforms = [
    ('تلغرام', Icons.send_rounded, Color(0xFF0EA5E9), Color(0xFF0284C7)),
    ('منصة X', Icons.tag, Color(0xFF374151), Color(0xFF111827)),
    ('يوتيوب', Icons.play_circle_filled, Color(0xFFDC2626), Color(0xFF991B1B)),
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final (label, icon, color1, color2) = _platforms[_selected];

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 8, 16, 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: LinearGradient(
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
          colors: [const Color(0xFF0F172A), const Color(0xFF1E293B)],
        ),
        boxShadow: [
          BoxShadow(color: color1.withOpacity(0.2), blurRadius: 24, offset: const Offset(0, 8)),
          BoxShadow(color: Colors.black.withOpacity(0.15), blurRadius: 8, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(
        children: [
          // ── Header ──
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 16, 18, 0),
            child: Row(
              children: [
                Container(
                  width: 36, height: 36,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(colors: [color1, color2]),
                    borderRadius: BorderRadius.circular(10),
                    boxShadow: [BoxShadow(color: color1.withOpacity(0.4), blurRadius: 8, offset: const Offset(0, 3))],
                  ),
                  alignment: Alignment.center,
                  child: const Icon(Icons.cell_tower, color: Colors.white, size: 18),
                ),
                const SizedBox(width: 10),
                const Text('المنصات', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17, color: Colors.white)),
                const Spacer(),
                // Pulse indicator
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.red.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: Colors.red.withOpacity(0.2)),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Container(width: 6, height: 6,
                      decoration: const BoxDecoration(color: Colors.red, shape: BoxShape.circle)),
                    const SizedBox(width: 4),
                    const Text('LIVE', style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800,
                      color: Colors.red, letterSpacing: 1)),
                  ]),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // ── Platform tabs — full width with icons ──
          Container(
            margin: const EdgeInsets.symmetric(horizontal: 14),
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.06),
              borderRadius: BorderRadius.circular(16),
            ),
            child: Row(
              children: List.generate(_platforms.length, (i) {
                final isActive = i == _selected;
                final (pLabel, pIcon, pColor1, pColor2) = _platforms[i];
                return Expanded(
                  child: GestureDetector(
                    onTap: () => setState(() => _selected = i),
                    child: AnimatedContainer(
                      duration: const Duration(milliseconds: 250),
                      curve: Curves.easeOutCubic,
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      decoration: BoxDecoration(
                        gradient: isActive
                            ? LinearGradient(colors: [pColor1, pColor2])
                            : null,
                        borderRadius: BorderRadius.circular(12),
                        boxShadow: isActive
                            ? [BoxShadow(color: pColor1.withOpacity(0.4), blurRadius: 10, offset: const Offset(0, 3))]
                            : [],
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(pIcon, size: 15,
                            color: isActive ? Colors.white : Colors.white38),
                          const SizedBox(width: 6),
                          Text(pLabel, style: TextStyle(
                            color: isActive ? Colors.white : Colors.white38,
                            fontWeight: isActive ? FontWeight.w800 : FontWeight.w500,
                            fontSize: 12,
                          )),
                        ],
                      ),
                    ),
                  ),
                );
              }),
            ),
          ),
          const SizedBox(height: 4),

          // ── Animated color bar ──
          AnimatedContainer(
            duration: const Duration(milliseconds: 300),
            margin: const EdgeInsets.symmetric(horizontal: 18),
            height: 2,
            decoration: BoxDecoration(
              gradient: LinearGradient(colors: [color1, color1.withOpacity(0.2), Colors.transparent]),
              borderRadius: BorderRadius.circular(2),
            ),
          ),

          // ── AI Summary card ──
          if (_selected < 2)
            _SocialAiSummary(platform: _selected == 0 ? 'telegram' : 'twitter'),

          // ── Feed content ──
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 250),
            child: _selected == 0
                ? _TelegramPreview(key: const ValueKey(0))
                : _selected == 1
                    ? _TwitterPreview(key: const ValueKey(1))
                    : _YoutubePreview(key: const ValueKey(2)),
          ),

          // ── View all button ──
          InkWell(
            onTap: () => context.push('/platforms'),
            borderRadius: const BorderRadius.vertical(bottom: Radius.circular(24)),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 14),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [color1.withOpacity(0.12), color1.withOpacity(0.04)]),
                borderRadius: const BorderRadius.vertical(bottom: Radius.circular(24)),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text('عرض الكل', style: TextStyle(
                    color: color1, fontWeight: FontWeight.w800, fontSize: 13)),
                  const SizedBox(width: 4),
                  Icon(Icons.arrow_back_ios_new, size: 12, color: color1),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Telegram Preview ──
class _TelegramPreview extends ConsumerWidget {
  const _TelegramPreview({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(telegramFeedProvider);
    return feed.when(
      loading: () => const SizedBox(height: 200, child: Center(
        child: CircularProgressIndicator(color: Color(0xFF0EA5E9), strokeWidth: 2))),
      error: (_, __) => const Padding(padding: EdgeInsets.all(24),
        child: Text('تعذّر التحميل', style: TextStyle(color: Colors.white54))),
      data: (msgs) => _MessagesList(
        msgs: msgs.take(5).toList(),
        platformColor: const Color(0xFF0EA5E9),
        platformName: 'تلغرام',
        platformIcon: Icons.send_rounded,
      ),
    );
  }
}

// ── Twitter Preview ──
class _TwitterPreview extends ConsumerWidget {
  const _TwitterPreview({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(twitterFeedProvider);
    return feed.when(
      loading: () => const SizedBox(height: 200, child: Center(
        child: CircularProgressIndicator(color: Color(0xFF374151), strokeWidth: 2))),
      error: (_, __) => const Padding(padding: EdgeInsets.all(24),
        child: Text('تعذّر التحميل', style: TextStyle(color: Colors.white54))),
      data: (msgs) => _MessagesList(
        msgs: msgs.take(5).toList(),
        platformColor: const Color(0xFF374151),
        platformName: 'X',
        platformIcon: Icons.tag,
      ),
    );
  }
}

// ── YouTube Preview ──
class _YoutubePreview extends ConsumerWidget {
  const _YoutubePreview({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(youtubeFeedProvider);
    return feed.when(
      loading: () => const SizedBox(height: 200, child: Center(
        child: CircularProgressIndicator(color: Color(0xFFDC2626), strokeWidth: 2))),
      error: (_, __) => const Padding(padding: EdgeInsets.all(24),
        child: Text('تعذّر التحميل', style: TextStyle(color: Colors.white54))),
      data: (videos) {
        final preview = videos.take(5).toList();
        if (preview.isEmpty) return const Padding(padding: EdgeInsets.all(24),
          child: Text('لا توجد فيديوهات', style: TextStyle(color: Colors.white54)));
        return ListView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(14, 8, 14, 4),
          itemCount: preview.length,
          itemBuilder: (_, i) {
            final v = preview[i];
            return InkWell(
              onTap: () => launchUrl(Uri.parse(v.videoUrl)),
              borderRadius: BorderRadius.circular(12),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Row(
                  children: [
                    // Thumbnail with play overlay
                    if (v.thumbnailUrl != null)
                      ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: SizedBox(
                          width: 90, height: 56,
                          child: Stack(children: [
                            CachedNetworkImage(imageUrl: v.thumbnailUrl!,
                              width: 90, height: 56, fit: BoxFit.cover),
                            Positioned.fill(child: Container(
                              decoration: BoxDecoration(color: Colors.black.withOpacity(0.3)),
                              child: const Center(child: Icon(Icons.play_circle_fill,
                                color: Colors.white, size: 26)),
                            )),
                          ]),
                        ),
                      ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(v.title, style: const TextStyle(
                          fontWeight: FontWeight.w700, fontSize: 13, height: 1.3, color: Colors.white),
                          maxLines: 2, overflow: TextOverflow.ellipsis),
                        const SizedBox(height: 4),
                        Text(v.sourceName, style: const TextStyle(fontSize: 11, color: Colors.white38)),
                      ]),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }
}

// ── Social AI Summary Card ──
class _SocialAiSummary extends ConsumerWidget {
  const _SocialAiSummary({required this.platform});
  final String platform;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final provider = platform == 'telegram' ? telegramSummaryProvider : twitterSummaryProvider;
    final asy = ref.watch(provider);

    return asy.maybeWhen(
      data: (summary) {
        if (summary.isEmpty) return const SizedBox.shrink();
        return Container(
          margin: const EdgeInsets.fromLTRB(14, 8, 14, 4),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [Colors.white.withOpacity(0.06), Colors.white.withOpacity(0.02)],
            ),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: Colors.white.withOpacity(0.08)),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 28, height: 28,
                decoration: BoxDecoration(
                  gradient: const LinearGradient(colors: [Color(0xFF6366F1), Color(0xFF4338CA)]),
                  borderRadius: BorderRadius.circular(8),
                ),
                alignment: Alignment.center,
                child: const Icon(Icons.auto_awesome, size: 14, color: Colors.white),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('ملخص AI',
                      style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: Color(0xFF818CF8))),
                    const SizedBox(height: 4),
                    Text(summary,
                      style: TextStyle(fontSize: 12, height: 1.6, color: Colors.white.withOpacity(0.75)),
                      maxLines: 4, overflow: TextOverflow.ellipsis),
                  ],
                ),
              ),
            ],
          ),
        );
      },
      orElse: () => const SizedBox.shrink(),
    );
  }
}

// ── Messages List (Telegram & Twitter) ──
class _MessagesList extends StatelessWidget {
  const _MessagesList({
    required this.msgs, required this.platformColor,
    required this.platformName, required this.platformIcon,
  });
  final List<TelegramMessage> msgs;
  final Color platformColor;
  final String platformName;
  final IconData platformIcon;

  @override
  Widget build(BuildContext context) {
    if (msgs.isEmpty) return const Padding(padding: EdgeInsets.all(24),
      child: Text('لا توجد منشورات', style: TextStyle(color: Colors.white54)));
    return ListView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(14, 8, 14, 4),
      itemCount: msgs.length,
      itemBuilder: (_, i) {
        final m = msgs[i];
        return Container(
          margin: const EdgeInsets.only(bottom: 8),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(0.04),
            borderRadius: BorderRadius.circular(14),
            border: Border(right: BorderSide(color: platformColor, width: 3)),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              // Platform badge
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                decoration: BoxDecoration(
                  gradient: LinearGradient(colors: [platformColor, platformColor.withOpacity(0.7)]),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(platformIcon, size: 10, color: Colors.white),
                  const SizedBox(width: 3),
                  Text(platformName, style: const TextStyle(
                    color: Colors.white, fontSize: 9, fontWeight: FontWeight.w700)),
                ]),
              ),
              const SizedBox(width: 8),
              Expanded(child: Text(
                '@${m.sourceUsername ?? m.sourceName}',
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 11, color: Colors.white70),
                overflow: TextOverflow.ellipsis,
              )),
              if (m.postedAt != null)
                Text(timeago.format(m.postedAt!, locale: 'ar'),
                  style: const TextStyle(fontSize: 10, color: Colors.white30)),
            ]),
            const SizedBox(height: 6),
            Text(m.text, style: const TextStyle(fontSize: 13, height: 1.4, color: Colors.white),
              maxLines: 2, overflow: TextOverflow.ellipsis),
          ]),
        );
      },
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// EVOLVING STORIES BOX — صندوق القصص المتطورة (tabbed like categories)
// ═══════════════════════════════════════════════════════════════

Color _storyAccent(String hex) {
  try {
    return Color(int.parse(hex.replaceAll('#', '0xFF')));
  } catch (_) {
    return const Color(0xFF0D9488);
  }
}

class _EvolvingStoriesSection extends ConsumerStatefulWidget {
  @override
  ConsumerState<_EvolvingStoriesSection> createState() => _EvolvingStoriesSectionState();
}

class _EvolvingStoriesSectionState extends ConsumerState<_EvolvingStoriesSection> {
  int _selected = 0;

  @override
  Widget build(BuildContext context) {
    final stories = ref.watch(evolvingStoriesProvider);
    return stories.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (list) {
        if (list.isEmpty) return const SizedBox.shrink();
        final theme = Theme.of(context);
        final isDark = theme.brightness == Brightness.dark;
        if (_selected >= list.length) _selected = 0;
        final story = list[_selected];
        final accent = _storyAccent(story.accentColor);

        // Total articles across all stories
        int totalArticles = 0;
        for (final s in list) totalArticles += s.articleCount;

        return Container(
          margin: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(24),
            gradient: LinearGradient(
              begin: Alignment.topRight,
              end: Alignment.bottomLeft,
              colors: isDark
                  ? [const Color(0xFF1A1A2E), const Color(0xFF16213E)]
                  : [Colors.white, const Color(0xFFFEF3C7)],
            ),
            boxShadow: [
              BoxShadow(color: accent.withOpacity(0.12), blurRadius: 24, offset: const Offset(0, 8)),
              BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 8, offset: const Offset(0, 2)),
            ],
          ),
          child: Column(
            children: [
              // ── Header ──
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 16, 18, 0),
                child: Row(
                  children: [
                    Container(
                      width: 36, height: 36,
                      decoration: BoxDecoration(
                        gradient: const LinearGradient(colors: [Color(0xFF0D9488), Color(0xFF14B8A6)]),
                        borderRadius: BorderRadius.circular(10),
                        boxShadow: [BoxShadow(color: const Color(0xFF0D9488).withOpacity(0.4), blurRadius: 8, offset: const Offset(0, 3))],
                      ),
                      alignment: Alignment.center,
                      child: const Icon(Icons.auto_stories, color: Colors.white, size: 18),
                    ),
                    const SizedBox(width: 10),
                    Text('القصص المتطوّرة',
                      style: TextStyle(fontWeight: FontWeight.w900, fontSize: 17,
                        color: isDark ? Colors.white : AppColors.textLight)),
                    const Spacer(),
                    // Eyebrow badge
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: const Color(0xFFEF4444).withOpacity(0.1),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: const Color(0xFFEF4444).withOpacity(0.2)),
                      ),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        Container(width: 6, height: 6,
                          decoration: const BoxDecoration(color: Color(0xFFEF4444), shape: BoxShape.circle)),
                        const SizedBox(width: 4),
                        Text('متابعة دائمة', style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800,
                          color: isDark ? const Color(0xFFFCA5A5) : const Color(0xFFDC2626))),
                      ]),
                    ),
                  ],
                ),
              ),

              // ── Stats row ──
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 10, 18, 0),
                child: Row(children: [
                  Text('📅 ${list.length} قصة',
                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700,
                      color: isDark ? Colors.white54 : AppColors.textMutedLight)),
                  const SizedBox(width: 14),
                  Text('📰 $totalArticles تقرير',
                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700,
                      color: isDark ? Colors.white54 : AppColors.textMutedLight)),
                  const SizedBox(width: 14),
                  Text('⚙️ تُحدَّث تلقائياً',
                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600,
                      color: isDark ? Colors.white30 : AppColors.textMutedLight)),
                ]),
              ),
              const SizedBox(height: 14),

              // ── Scrollable story tabs ──
              SizedBox(
                height: 40,
                child: ListView.builder(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  itemCount: list.length,
                  itemBuilder: (_, i) {
                    final s = list[i];
                    final sAccent = _storyAccent(s.accentColor);
                    final isActive = i == _selected;
                    return Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 3),
                      child: GestureDetector(
                        onTap: () => setState(() => _selected = i),
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 250),
                          curve: Curves.easeOutCubic,
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                          decoration: BoxDecoration(
                            gradient: isActive
                                ? LinearGradient(colors: [sAccent, sAccent.withOpacity(0.8)])
                                : null,
                            color: isActive ? null : (isDark ? Colors.white.withOpacity(0.06) : Colors.grey.withOpacity(0.08)),
                            borderRadius: BorderRadius.circular(20),
                            boxShadow: isActive
                                ? [BoxShadow(color: sAccent.withOpacity(0.35), blurRadius: 10, offset: const Offset(0, 3))]
                                : [],
                          ),
                          child: Row(mainAxisSize: MainAxisSize.min, children: [
                            if (s.icon.isNotEmpty) ...[
                              Text(s.icon, style: const TextStyle(fontSize: 14)),
                              const SizedBox(width: 5),
                            ],
                            Text(s.name,
                              style: TextStyle(
                                color: isActive ? Colors.white : (isDark ? Colors.white70 : AppColors.textMutedLight),
                                fontWeight: isActive ? FontWeight.w800 : FontWeight.w500,
                                fontSize: 12,
                              )),
                          ]),
                        ),
                      ),
                    );
                  },
                ),
              ),
              const SizedBox(height: 6),

              // ── Animated accent bar ──
              AnimatedContainer(
                duration: const Duration(milliseconds: 300),
                margin: const EdgeInsets.symmetric(horizontal: 18),
                height: 3,
                decoration: BoxDecoration(
                  gradient: LinearGradient(colors: [accent, accent.withOpacity(0.2), Colors.transparent]),
                  borderRadius: BorderRadius.circular(2),
                ),
              ),

              // ── Selected story content ──
              AnimatedSwitcher(
                duration: const Duration(milliseconds: 250),
                child: Padding(
                  key: ValueKey(_selected),
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Cover image preview
                      if (story.coverImage != null)
                        GestureDetector(
                          onTap: () => context.push('/stories/${story.slug}'),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(14),
                            child: SizedBox(
                              height: 140,
                              width: double.infinity,
                              child: Stack(
                                fit: StackFit.expand,
                                children: [
                                  CachedNetworkImage(
                                    imageUrl: story.coverImage!,
                                    fit: BoxFit.cover,
                                    placeholder: (_, __) => Container(color: accent.withOpacity(0.1)),
                                    errorWidget: (_, __, ___) => Container(color: accent.withOpacity(0.1)),
                                  ),
                                  // Gradient overlay
                                  DecoratedBox(decoration: BoxDecoration(
                                    gradient: LinearGradient(
                                      begin: Alignment.topCenter,
                                      end: Alignment.bottomCenter,
                                      stops: const [0.4, 1.0],
                                      colors: [Colors.transparent, Colors.black.withOpacity(0.7)],
                                    ),
                                  )),
                                  // Accent bar
                                  Positioned(top: 0, left: 0, right: 0,
                                    child: Container(height: 4, color: accent)),
                                  // Badge + LIVE
                                  Positioned(top: 10, left: 10,
                                    child: Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                      decoration: BoxDecoration(
                                        color: Colors.white.withOpacity(0.92),
                                        borderRadius: BorderRadius.circular(999),
                                      ),
                                      child: Text('📰 ${story.articleCount} تقرير',
                                        style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800)),
                                    ),
                                  ),
                                  if (story.isLive)
                                    Positioned(top: 10, right: 10,
                                      child: Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                        decoration: BoxDecoration(
                                          color: const Color(0xFFDC2626),
                                          borderRadius: BorderRadius.circular(999),
                                        ),
                                        child: Row(mainAxisSize: MainAxisSize.min, children: [
                                          Container(width: 6, height: 6,
                                            decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle)),
                                          const SizedBox(width: 4),
                                          const Text('مباشر', style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w800)),
                                        ]),
                                      ),
                                    ),
                                  // Icon + Name
                                  Positioned(bottom: 10, right: 10, left: 10,
                                    child: Row(children: [
                                      Container(
                                        width: 36, height: 36,
                                        decoration: BoxDecoration(
                                          color: Colors.white.withOpacity(0.95),
                                          borderRadius: BorderRadius.circular(10),
                                        ),
                                        alignment: Alignment.center,
                                        child: Text(story.icon.isNotEmpty ? story.icon : '📅',
                                          style: const TextStyle(fontSize: 18)),
                                      ),
                                      const SizedBox(width: 8),
                                      Expanded(child: Text(story.name,
                                        style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w900,
                                          shadows: [Shadow(color: Colors.black54, blurRadius: 6)]),
                                        maxLines: 1, overflow: TextOverflow.ellipsis)),
                                    ]),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),

                      // Description
                      if (story.description != null && story.description!.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 10),
                          child: Text(story.description!,
                            style: TextStyle(fontSize: 12.5, height: 1.7,
                              color: isDark ? Colors.white54 : AppColors.textMutedLight),
                            maxLines: 2, overflow: TextOverflow.ellipsis),
                        ),

                      // ── Latest 3 articles with colored bullets ──
                      if (story.latest.isNotEmpty) ...[
                        const SizedBox(height: 10),
                        ...story.latest.map((article) => InkWell(
                          onTap: () => context.push('/article/${article.id}'),
                          borderRadius: BorderRadius.circular(8),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(vertical: 8),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Padding(
                                  padding: const EdgeInsets.only(top: 7),
                                  child: Container(width: 6, height: 6,
                                    decoration: BoxDecoration(color: accent, shape: BoxShape.circle)),
                                ),
                                const SizedBox(width: 10),
                                Expanded(child: Text(
                                  article.title.length > 80 ? '${article.title.substring(0, 80)}…' : article.title,
                                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, height: 1.5,
                                    color: isDark ? Colors.white : AppColors.textLight),
                                )),
                                if (article.publishedAt != null) ...[
                                  const SizedBox(width: 8),
                                  Padding(
                                    padding: const EdgeInsets.only(top: 3),
                                    child: Text(timeago.format(article.publishedAt!, locale: 'ar'),
                                      style: TextStyle(fontSize: 10,
                                        color: isDark ? Colors.white30 : AppColors.textMutedLight)),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        )),
                      ],
                    ],
                  ),
                ),
              ),

              // ── View all button ──
              InkWell(
                onTap: () => context.push('/stories/${story.slug}'),
                borderRadius: const BorderRadius.vertical(bottom: Radius.circular(24)),
                child: Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(colors: [accent.withOpacity(0.08), accent.withOpacity(0.03)]),
                    borderRadius: const BorderRadius.vertical(bottom: Radius.circular(24)),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text('متابعة ${story.name}',
                        style: TextStyle(color: accent, fontWeight: FontWeight.w800, fontSize: 13)),
                      const SizedBox(width: 4),
                      Icon(Icons.arrow_back_ios_new, size: 12, color: accent),
                    ],
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// QUICK ACCESS ROW — AI + Morning Briefing
// ═══════════════════════════════════════════════════════════════

class _QuickAccessRow extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: Row(
        children: [
          // AI Card
          Expanded(
            child: GestureDetector(
              onTap: () => context.push('/ask'),
              child: Container(
                height: 110,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: Alignment.topRight, end: Alignment.bottomLeft,
                    colors: [Color(0xFF6366F1), Color(0xFF4338CA)],
                  ),
                  borderRadius: BorderRadius.circular(20),
                  boxShadow: [
                    BoxShadow(color: const Color(0xFF6366F1).withOpacity(0.3), blurRadius: 14, offset: const Offset(0, 6)),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 36, height: 36,
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      alignment: Alignment.center,
                      child: const Icon(Icons.auto_awesome, color: Colors.white, size: 18),
                    ),
                    const Spacer(),
                    const Text('اسأل الأخبار', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14)),
                    const SizedBox(height: 2),
                    Text('بالذكاء الاصطناعي', style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 11)),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(width: 12),
          // Morning Briefing Card
          Expanded(
            child: GestureDetector(
              onTap: () => context.push('/sabah'),
              child: Container(
                height: 110,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: Alignment.topRight, end: Alignment.bottomLeft,
                    colors: [Color(0xFFF59E0B), Color(0xFFB45309)],
                  ),
                  borderRadius: BorderRadius.circular(20),
                  boxShadow: [
                    BoxShadow(color: const Color(0xFFD97706).withOpacity(0.3), blurRadius: 14, offset: const Offset(0, 6)),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 36, height: 36,
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      alignment: Alignment.center,
                      child: const Icon(Icons.wb_sunny, color: Colors.white, size: 18),
                    ),
                    const Spacer(),
                    const Text('بريفينغ الصباح', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14)),
                    const SizedBox(height: 2),
                    Text('ملخص يومك الإخباري', style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 11)),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// STATS STRIP — أرقام سريعة
// ═══════════════════════════════════════════════════════════════

class _StatsStrip extends StatelessWidget {
  const _StatsStrip({required this.payload});
  final HomePayload payload;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final totalArticles = payload.latest.length + payload.breaking.length;
    final totalSources = payload.sources.length;

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: isDark ? Colors.white.withOpacity(0.04) : const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceAround,
        children: [
          _StatItem(value: '$totalArticles+', label: 'خبر', icon: Icons.article_outlined, isDark: isDark),
          Container(width: 1, height: 28,
            color: isDark ? Colors.white.withOpacity(0.08) : const Color(0xFFE2E8F0)),
          _StatItem(value: '$totalSources', label: 'مصدر', icon: Icons.public, isDark: isDark),
          Container(width: 1, height: 28,
            color: isDark ? Colors.white.withOpacity(0.08) : const Color(0xFFE2E8F0)),
          _StatItem(value: '${payload.buckets.length}', label: 'قسم', icon: Icons.grid_view, isDark: isDark),
          Container(width: 1, height: 28,
            color: isDark ? Colors.white.withOpacity(0.08) : const Color(0xFFE2E8F0)),
          _StatItem(value: '${payload.trends.length}', label: 'ترند', icon: Icons.trending_up, isDark: isDark),
        ],
      ),
    );
  }
}

class _StatItem extends StatelessWidget {
  const _StatItem({required this.value, required this.label, required this.icon, required this.isDark});
  final String value, label;
  final IconData icon;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Icon(icon, size: 16, color: const Color(0xFF38BDF8)),
        const SizedBox(height: 4),
        Text(value, style: TextStyle(fontSize: 14, fontWeight: FontWeight.w900,
          color: isDark ? Colors.white : AppColors.textLight)),
        Text(label, style: TextStyle(fontSize: 10,
          color: isDark ? Colors.white38 : AppColors.textMutedLight)),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// FOR YOU — خاص بك (personalized feed)
// ═══════════════════════════════════════════════════════════════

class _ForYouSection extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(forYouProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return asy.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (articles) {
        if (articles.isEmpty) return const SizedBox.shrink();
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 10),
              child: Row(children: [
                Container(
                  width: 32, height: 32,
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(colors: [Color(0xFFF59E0B), Color(0xFFEF4444)]),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  alignment: Alignment.center,
                  child: const Icon(Icons.person, color: Colors.white, size: 16),
                ),
                const SizedBox(width: 8),
                Text('خاص بك',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                    color: isDark ? Colors.white : AppColors.textLight)),
                const Spacer(),
                GestureDetector(
                  onTap: () => context.push('/follow'),
                  child: Text('تعديل',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700,
                      color: const Color(0xFF38BDF8))),
                ),
              ]),
            ),
            SizedBox(
              height: 220,
              child: ListView.builder(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                itemCount: articles.length.clamp(0, 8),
                itemBuilder: (_, i) {
                  final a = articles[i];
                  return GestureDetector(
                    onTap: () => context.push('/article/${a.id}'),
                    child: Container(
                      width: 200,
                      margin: const EdgeInsets.symmetric(horizontal: 4),
                      decoration: BoxDecoration(
                        color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(
                          color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (a.imageUrl != null)
                            ClipRRect(
                              borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
                              child: CachedNetworkImage(
                                imageUrl: a.imageUrl!, width: 200, height: 110, fit: BoxFit.cover),
                            )
                          else
                            Container(
                              height: 110,
                              decoration: BoxDecoration(
                                color: AppColors.primary.withOpacity(0.1),
                                borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
                              ),
                              alignment: Alignment.center,
                              child: Icon(Icons.article, size: 32, color: AppColors.primary.withOpacity(0.3)),
                            ),
                          Padding(
                            padding: const EdgeInsets.all(10),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(a.title,
                                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, height: 1.4,
                                    color: isDark ? Colors.white : AppColors.textLight),
                                  maxLines: 3, overflow: TextOverflow.ellipsis),
                                const SizedBox(height: 6),
                                if (a.source != null)
                                  Text(a.source!.name,
                                    style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w600,
                                      color: Color(0xFF38BDF8)),
                                    overflow: TextOverflow.ellipsis),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
            ),
          ],
        );
      },
    );
  }
}
