import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/models/article.dart';
import '../../../core/models/home_payload.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../../../core/widgets/section_header.dart';
import '../data/content_repository.dart';
import 'widgets/breaking_strip.dart';
import 'widgets/category_chips_rail.dart';
import 'widgets/source_chips_rail.dart';
import 'widgets/ticker_bar.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final home = ref.watch(homeProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('فيد نيوز'),
        leading: IconButton(
          icon: const Icon(Icons.search_rounded),
          onPressed: () => context.push('/search'),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications_outlined),
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

        if (payload.hero != null) SliverToBoxAdapter(child: _HeroCard(article: payload.hero!)),

        SliverToBoxAdapter(
          child: SectionHeader(
            title: 'الأقسام',
            icon: Icons.apps,
            onMore: () => context.push('/discover'),
          ),
        ),
        SliverToBoxAdapter(child: CategoryChipsRail(buckets: payload.buckets)),

        if (payload.sources.isNotEmpty) ...[
          SliverToBoxAdapter(
            child: SectionHeader(
              title: 'مصادر الأخبار',
              icon: Icons.public,
              onMore: () => context.push('/discover'),
            ),
          ),
          SliverToBoxAdapter(child: SourceChipsRail(sources: payload.sources)),
        ],

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

        for (final bucket in payload.buckets) ...[
          SliverToBoxAdapter(
            child: SectionHeader(
              title: '${bucket.category.icon ?? ''} ${bucket.category.name}'.trim(),
              onMore: () => context.push('/category/${bucket.category.slug}'),
            ),
          ),
          SliverPadding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            sliver: SliverList.separated(
              itemCount: bucket.articles.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (_, i) => ArticleCard(article: bucket.articles[i], compact: true),
            ),
          ),
        ],

        if (payload.trends.isNotEmpty) ...[
          SliverToBoxAdapter(
            child: SectionHeader(
              title: 'الأكثر تداولاً',
              icon: Icons.trending_up,
              onMore: () => context.push('/trending'),
            ),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final t in payload.trends)
                    ActionChip(
                      label: Text('# ${t.title}'),
                      onPressed: () => context.push('/search?q=${Uri.encodeComponent(t.title)}'),
                    ),
                ],
              ),
            ),
          ),
        ],

        const SliverToBoxAdapter(child: SizedBox(height: 24)),
      ],
    );
  }
}

class _HeroCard extends StatelessWidget {
  const _HeroCard({required this.article});
  final Article article;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
      child: InkWell(
        onTap: () => context.push('/article/${article.id}'),
        borderRadius: BorderRadius.circular(16),
        child: Stack(
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(16),
              child: AspectRatio(
                aspectRatio: 16 / 10,
                child: article.imageUrl != null
                    ? CachedNetworkImage(imageUrl: article.imageUrl!, fit: BoxFit.cover)
                    : Container(color: AppColors.primary.withOpacity(0.2)),
              ),
            ),
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(16),
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [Colors.transparent, Colors.black.withOpacity(0.7)],
                  ),
                ),
              ),
            ),
            Positioned(
              left: 12, right: 12, bottom: 12,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (article.category != null)
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppColors.primary,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        '${article.category!.icon ?? ''} ${article.category!.name}'.trim(),
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
                      ),
                    ),
                  const SizedBox(height: 8),
                  Text(
                    article.title,
                    style: const TextStyle(
                      color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800, height: 1.35,
                    ),
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
