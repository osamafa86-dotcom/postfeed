import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/models/source.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../../user/data/user_repository.dart';
import '../data/content_repository.dart';

// ── Providers ──

final _sourceProfileProvider =
    FutureProvider.family<_SourceProfile, String>((ref, slug) async {
  final repo = ref.watch(contentRepositoryProvider);
  final results = await Future.wait([
    repo.sources(),
    repo.articles(source: slug, limit: 30),
  ]);
  final allSources = results[0] as List<Source>;
  final articles = results[1] as PaginatedArticles;
  final source = allSources.firstWhere(
    (s) => s.slug == slug,
    orElse: () => Source(id: 0, name: slug, slug: slug),
  );
  return _SourceProfile(source: source, articles: articles);
});

class _SourceProfile {
  const _SourceProfile({required this.source, required this.articles});
  final Source source;
  final PaginatedArticles articles;
}

// ── Screen ──

class SourceScreen extends ConsumerWidget {
  const SourceScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_sourceProfileProvider(slug));
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_sourceProfileProvider(slug)),
        ),
        data: (profile) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(_sourceProfileProvider(slug)),
          child: CustomScrollView(
            slivers: [
              // ── Hero Header ──
              _SourceHeader(source: profile.source, isDark: isDark),

              // ── Stats Bar ──
              SliverToBoxAdapter(
                child: _StatsBar(
                  totalArticles: profile.articles.total,
                  todayCount: profile.source.articlesToday,
                  isDark: isDark,
                ),
              ),

              // ── Follow Button ──
              SliverToBoxAdapter(
                child: _FollowRow(source: profile.source, isDark: isDark),
              ),

              // ── Section Title ──
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 20, 16, 8),
                  child: Row(
                    children: [
                      Container(
                        width: 4,
                        height: 20,
                        decoration: BoxDecoration(
                          color: _colorFromHex(profile.source.logoColor) ?? AppColors.primary,
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        'آخر الأخبار',
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                          color: isDark ? Colors.white : AppColors.textLight,
                        ),
                      ),
                      const Spacer(),
                      Text(
                        '${profile.articles.total} خبر',
                        style: TextStyle(
                          fontSize: 12,
                          color: isDark ? Colors.white38 : AppColors.textMutedLight,
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              // ── Articles List ──
              if (profile.articles.items.isEmpty)
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.all(40),
                    child: Center(
                      child: Text(
                        'لا توجد أخبار حالياً',
                        style: TextStyle(
                          color: isDark ? Colors.white38 : AppColors.textMutedLight,
                        ),
                      ),
                    ),
                  ),
                )
              else
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                  sliver: SliverList.separated(
                    itemCount: profile.articles.items.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (_, i) =>
                        ArticleCard(article: profile.articles.items[i]),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Hero Header (SliverAppBar) ──

class _SourceHeader extends StatelessWidget {
  const _SourceHeader({required this.source, required this.isDark});
  final Source source;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    final color = _colorFromHex(source.logoColor) ?? AppColors.primary;
    final bgColor = _colorFromHex(source.logoBg) ?? color.withOpacity(0.15);

    return SliverAppBar(
      expandedHeight: 200,
      pinned: true,
      backgroundColor: isDark ? AppColors.cardDark : Colors.white,
      leading: IconButton(
        icon: Container(
          padding: const EdgeInsets.all(6),
          decoration: BoxDecoration(
            color: Colors.black26,
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Icon(Icons.arrow_back, color: Colors.white, size: 20),
        ),
        onPressed: () => Navigator.of(context).pop(),
      ),
      flexibleSpace: FlexibleSpaceBar(
        background: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                color,
                color.withOpacity(0.7),
              ],
            ),
          ),
          child: SafeArea(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const SizedBox(height: 20),
                // Logo circle
                Container(
                  width: 72,
                  height: 72,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(0.15),
                        blurRadius: 12,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  alignment: Alignment.center,
                  child: Text(
                    source.logoLetter ?? source.name.characters.first,
                    style: TextStyle(
                      fontSize: 32,
                      fontWeight: FontWeight.w900,
                      color: color,
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                // Name
                Text(
                  source.name,
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w900,
                    color: Colors.white,
                  ),
                ),
                const SizedBox(height: 4),
                // URL
                if (source.url != null)
                  GestureDetector(
                    onTap: () => _openUrl(source.url!),
                    child: Text(
                      _cleanUrl(source.url!),
                      style: TextStyle(
                        fontSize: 13,
                        color: Colors.white.withOpacity(0.7),
                        decoration: TextDecoration.underline,
                        decorationColor: Colors.white.withOpacity(0.5),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
        collapseMode: CollapseMode.parallax,
      ),
    );
  }
}

// ── Stats Bar ──

class _StatsBar extends StatelessWidget {
  const _StatsBar({
    required this.totalArticles,
    required this.todayCount,
    required this.isDark,
  });
  final int totalArticles;
  final int todayCount;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 16, 16, 0),
      padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 20),
      decoration: BoxDecoration(
        color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: isDark ? Colors.white.withOpacity(0.06) : AppColors.borderLight,
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.03),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceEvenly,
        children: [
          _StatItem(
            icon: Icons.article_outlined,
            value: '$totalArticles',
            label: 'إجمالي الأخبار',
            isDark: isDark,
          ),
          Container(
            width: 1,
            height: 32,
            color: isDark ? Colors.white10 : AppColors.borderLight,
          ),
          _StatItem(
            icon: Icons.today,
            value: '$todayCount',
            label: 'أخبار اليوم',
            isDark: isDark,
          ),
        ],
      ),
    );
  }
}

class _StatItem extends StatelessWidget {
  const _StatItem({
    required this.icon,
    required this.value,
    required this.label,
    required this.isDark,
  });
  final IconData icon;
  final String value;
  final String label;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 20, color: isDark ? Colors.white54 : AppColors.textMutedLight),
        const SizedBox(height: 6),
        Text(
          value,
          style: TextStyle(
            fontSize: 20,
            fontWeight: FontWeight.w900,
            color: isDark ? Colors.white : AppColors.textLight,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          label,
          style: TextStyle(
            fontSize: 11,
            color: isDark ? Colors.white38 : AppColors.textMutedLight,
          ),
        ),
      ],
    );
  }
}

// ── Follow Row ──

class _FollowRow extends ConsumerWidget {
  const _FollowRow({required this.source, required this.isDark});
  final Source source;
  final bool isDark;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final followedIds = ref.watch(followedIdsProvider);
    final isFollowing = followedIds['source']?.contains(source.id) ?? false;
    final color = _colorFromHex(source.logoColor) ?? AppColors.primary;

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: Row(
        children: [
          // Follow / Unfollow button
          Expanded(
            child: ElevatedButton.icon(
              onPressed: () =>
                  ref.read(followedIdsProvider.notifier).toggle('source', source.id),
              icon: Icon(
                isFollowing ? Icons.check : Icons.add,
                size: 18,
              ),
              label: Text(isFollowing ? 'متابَع' : 'متابعة'),
              style: ElevatedButton.styleFrom(
                backgroundColor: isFollowing
                    ? (isDark ? Colors.white.withOpacity(0.08) : const Color(0xFFF1F5F9))
                    : color,
                foregroundColor: isFollowing
                    ? (isDark ? Colors.white70 : AppColors.textLight)
                    : Colors.white,
                elevation: 0,
                padding: const EdgeInsets.symmetric(vertical: 12),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                  side: isFollowing
                      ? BorderSide(
                          color: isDark ? Colors.white12 : AppColors.borderLight)
                      : BorderSide.none,
                ),
              ),
            ),
          ),

          // External link button
          if (source.url != null) ...[
            const SizedBox(width: 10),
            SizedBox(
              height: 46,
              width: 46,
              child: OutlinedButton(
                onPressed: () => _openUrl(source.url!),
                style: OutlinedButton.styleFrom(
                  padding: EdgeInsets.zero,
                  side: BorderSide(
                    color: isDark ? Colors.white12 : AppColors.borderLight,
                  ),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
                child: Icon(
                  Icons.open_in_new,
                  size: 18,
                  color: isDark ? Colors.white54 : AppColors.textMutedLight,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ── Helpers ──

Color? _colorFromHex(String? hex) {
  if (hex == null || hex.isEmpty) return null;
  final clean = hex.replaceFirst('#', '');
  if (clean.length != 6) return null;
  final val = int.tryParse(clean, radix: 16);
  if (val == null) return null;
  return Color(0xFF000000 | val);
}

String _cleanUrl(String url) {
  return url
      .replaceFirst(RegExp(r'^https?://'), '')
      .replaceFirst(RegExp(r'^www\.'), '')
      .replaceFirst(RegExp(r'/$'), '');
}

Future<void> _openUrl(String url) async {
  final uri = Uri.parse(url.startsWith('http') ? url : 'https://$url');
  if (await canLaunchUrl(uri)) {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }
}
