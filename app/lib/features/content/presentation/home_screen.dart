import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/models/article.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';
import 'widgets/red_breaking_ticker.dart';
import 'widgets/rich_article_card.dart';
import 'widgets/section_pills.dart';
import 'widgets/site_header.dart';
import 'widgets/stats_strip.dart';
import 'widgets/weekly_rewind_banner.dart';

/// Home feed — pixel-faithful to the desktop site index.php on phones:
///   1. Dark navy site header (SliverAppBar)
///   2. Red breaking ticker
///   3. Pastel stats strip (5 chips)
///   4. Weekly rewind banner (Sundays)
///   5. Section pills (filter chips with scroll-to-section)
///   6. Hero feature card
///   7. Latest news list (rich cards)
///   8. Per-section buckets (breaking, palestine, trending, …)
class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  String _activeSection = 'all';

  // Anchor keys so tapping a section pill scrolls the matching block into view.
  final Map<String, GlobalKey> _anchors = {
    'breaking':  GlobalKey(),
    'latest':    GlobalKey(),
    'palestine': GlobalKey(),
    'trending':  GlobalKey(),
  };

  void _onPillTap(HomeSectionPill pill) {
    if (pill.kind == HomeSectionKind.link && pill.route != null) {
      context.push(pill.route!);
      return;
    }
    setState(() => _activeSection = pill.id);
    final key = _anchors[pill.id];
    if (key?.currentContext != null) {
      Scrollable.ensureVisible(
        key!.currentContext!,
        duration: const Duration(milliseconds: 350),
        curve: Curves.easeInOut,
        alignment: 0,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final home = ref.watch(homeProvider);
    return Scaffold(
      backgroundColor: AppColors.surfaceLight,
      body: home.when(
        loading: () => const _HomeLoading(),
        error: (e, _) => ErrorRetryView(
          message: 'تعذّر تحميل الرئيسية\n$e',
          onRetry: () => ref.invalidate(homeProvider),
        ),
        data: (data) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(homeProvider);
            await ref.read(homeProvider.future);
          },
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              const SiteHeader(),
              if (data.ticker.isNotEmpty)
                SliverToBoxAdapter(child: RedBreakingTicker(items: data.ticker)),
              if (data.stats != null)
                SliverToBoxAdapter(child: StatsStrip(stats: data.stats!)),
              if (data.weeklyRewind != null)
                SliverToBoxAdapter(child: WeeklyRewindBanner(cover: data.weeklyRewind!)),
              SliverToBoxAdapter(
                child: SectionPills(
                  pills: SectionPills.defaultPills,
                  activeId: _activeSection,
                  onSelect: _onPillTap,
                ),
              ),
              const SliverToBoxAdapter(child: SizedBox(height: 4)),

              if (data.hero != null)
                SliverToBoxAdapter(child: _HeroFeature(article: data.hero!)),

              // Breaking section anchor
              SliverToBoxAdapter(
                key: _anchors['breaking'],
                child: _AnchoredSectionHeader(
                  icon: '🔴',
                  label: 'أخبار عاجلة',
                  accent: AppColors.breaking,
                  trailing: TextButton(
                    onPressed: () => context.push('/category/breaking'),
                    child: const Text('عرض الكل ›'),
                  ),
                ),
              ),
              if (data.breaking.isNotEmpty)
                _articleListSliver(data.breaking.take(4).toList())
              else
                const _EmptyHint(text: 'لا توجد أخبار عاجلة الآن'),

              // Latest section anchor
              SliverToBoxAdapter(
                key: _anchors['latest'],
                child: _AnchoredSectionHeader(
                  icon: '⏱',
                  label: 'آخر الأخبار',
                  accent: AppColors.accent,
                  trailing: TextButton(
                    onPressed: () => context.push('/category/latest'),
                    child: const Text('عرض الكل ›'),
                  ),
                ),
              ),
              _articleListSliver(data.latest),

              // Per-category buckets — surface the website's category rails.
              for (final bucket in data.buckets) ...[
                SliverToBoxAdapter(
                  child: _AnchoredSectionHeader(
                    icon: bucket.category.icon ?? '📰',
                    label: bucket.category.name,
                    accent: AppColors.categoryColors[bucket.category.color] ??
                        AppColors.accent,
                    trailing: TextButton(
                      onPressed: () =>
                          context.push('/category/${bucket.category.slug}'),
                      child: const Text('عرض الكل ›'),
                    ),
                  ),
                ),
                _articleListSliver(bucket.articles),
              ],

              // Trending tags
              if (data.trends.isNotEmpty) ...[
                SliverToBoxAdapter(
                  key: _anchors['trending'],
                  child: _AnchoredSectionHeader(
                    icon: '🔥',
                    label: 'الأكثر تداولاً',
                    accent: AppColors.gold,
                    trailing: TextButton(
                      onPressed: () => context.push('/trending'),
                      child: const Text('عرض الكل ›'),
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                    child: Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        for (final t in data.trends)
                          ActionChip(
                            label: Text('# ${t.title}'),
                            onPressed: () => context.push(
                                '/search?q=${Uri.encodeComponent(t.title)}'),
                            backgroundColor: AppColors.cardLight,
                            side: const BorderSide(color: AppColors.borderLight),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(20),
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              ],

              const SliverToBoxAdapter(child: SizedBox(height: 32)),
            ],
          ),
        ),
      ),
    );
  }

  /// Helper that turns a list of articles into a vertically-spaced sliver
  /// of `RichArticleCard`s with the standard 12px horizontal padding.
  SliverPadding _articleListSliver(List<Article> items) {
    return SliverPadding(
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
      sliver: SliverList.separated(
        itemCount: items.length,
        separatorBuilder: (_, __) => const SizedBox(height: 12),
        itemBuilder: (_, i) => RichArticleCard(article: items[i]),
      ),
    );
  }
}

/// Section header that mirrors `.section-header` on the website:
///   ▮ <icon> <label>                                    عرض الكل ›
class _AnchoredSectionHeader extends StatelessWidget {
  const _AnchoredSectionHeader({
    required this.icon,
    required this.label,
    required this.accent,
    this.trailing,
  });

  final String icon;
  final String label;
  final Color accent;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 14, 12, 8),
      child: Row(
        children: [
          Container(
            width: 4,
            height: 22,
            decoration: BoxDecoration(
              color: accent,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(width: 8),
          Text(icon, style: const TextStyle(fontSize: 16)),
          const SizedBox(width: 6),
          Text(
            label,
            style: const TextStyle(
              color: AppColors.textLight,
              fontSize: 16,
              fontWeight: FontWeight.w900,
            ),
          ),
          const Spacer(),
          if (trailing != null) trailing!,
        ],
      ),
    );
  }
}

class _EmptyHint extends StatelessWidget {
  const _EmptyHint({required this.text});
  final String text;

  @override
  Widget build(BuildContext context) {
    return SliverToBoxAdapter(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 18),
          decoration: BoxDecoration(
            color: AppColors.cardLight,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppColors.borderLight),
          ),
          child: Text(
            text,
            textAlign: TextAlign.center,
            style: const TextStyle(
              color: AppColors.textMutedLight,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ),
    );
  }
}

/// Big hero card at the top of the feed, mirroring the website's
/// `.nf-feature-main` block: image + dark gradient + huge headline.
class _HeroFeature extends StatelessWidget {
  const _HeroFeature({required this.article});
  final Article article;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: () => context.push('/article/${article.id}'),
          child: Stack(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(16),
                child: AspectRatio(
                  aspectRatio: 16 / 10,
                  child: article.imageUrl != null
                      ? CachedNetworkImage(
                          imageUrl: article.imageUrl!,
                          fit: BoxFit.cover,
                          placeholder: (_, __) => Container(color: const Color(0xFFE5E7EB)),
                          errorWidget: (_, __, ___) =>
                              Container(color: const Color(0xFFE5E7EB)),
                        )
                      : Container(color: const Color(0xFFE5E7EB)),
                ),
              ),
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(16),
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [Colors.transparent, Colors.black.withOpacity(0.78)],
                    ),
                  ),
                ),
              ),
              PositionedDirectional(
                start: 14,
                end: 14,
                bottom: 14,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (article.category != null)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: AppColors.categoryColors[article.category!.color] ??
                              AppColors.accent,
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          '${article.category!.icon ?? ''} ${article.category!.name}'.trim(),
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            fontSize: 11,
                          ),
                        ),
                      ),
                    const SizedBox(height: 10),
                    Text(
                      article.title,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                        height: 1.4,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Loading skeleton — dark header pinned + a few placeholder cards.
class _HomeLoading extends StatelessWidget {
  const _HomeLoading();

  @override
  Widget build(BuildContext context) {
    return CustomScrollView(
      slivers: [
        const SiteHeader(),
        const SliverToBoxAdapter(child: SizedBox(height: 12)),
        SliverList.builder(
          itemCount: 4,
          itemBuilder: (_, __) => Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            child: Container(
              height: 280,
              decoration: BoxDecoration(
                color: AppColors.cardLight,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: AppColors.borderLight),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
