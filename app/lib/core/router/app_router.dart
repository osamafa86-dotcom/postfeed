import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/forgot_password_screen.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/auth/presentation/register_screen.dart';
import '../../features/content/data/content_repository.dart';
import '../../features/content/presentation/article_screen.dart';
import '../../features/content/presentation/category_screen.dart';
import '../../features/content/presentation/cluster_screen.dart';
import '../../features/content/presentation/clusters_screen.dart';
import '../../features/content/presentation/discover_screen.dart';
import '../../features/content/presentation/home_screen.dart';
import '../../features/content/presentation/summaries_screen.dart';
import '../../features/content/presentation/quotes_wall_screen.dart';
import '../../features/content/presentation/stories_network_screen.dart';
import '../../features/content/presentation/evolving_stories_screen.dart';
import '../../features/content/presentation/evolving_story_screen.dart';
import '../../features/content/presentation/news_map_screen.dart';
import '../../features/content/presentation/sabah_screen.dart';
import '../../features/content/presentation/search_screen.dart';
import '../../features/content/presentation/source_screen.dart';
import '../../features/content/presentation/timelines_screen.dart';
import '../../features/content/presentation/topic_screen.dart';
import '../../features/content/presentation/trending_screen.dart';
import '../../features/content/presentation/weekly_rewind_screen.dart';
import '../../features/media/data/media_repository.dart';
import '../../features/media/presentation/ask_screen.dart';
import '../../features/media/presentation/gallery_screen.dart';
import '../../features/media/presentation/reels_screen.dart';
import '../../features/media/presentation/telegram_screen.dart';
import '../../features/media/presentation/twitter_screen.dart';
import '../../features/media/presentation/youtube_screen.dart';
import '../../features/media/presentation/platforms_screen.dart';
import '../../features/onboarding/onboarding_screen.dart';
import '../../features/splash/splash_screen.dart';
import '../../features/user/presentation/bookmarks_screen.dart';
import '../../features/user/presentation/follow_screen.dart';
import '../../features/user/presentation/notifications_screen.dart';
import '../../features/user/presentation/profile_screen.dart';
import '../../features/user/presentation/settings_screen.dart';
import '../theme/app_theme.dart';

/// Global navigator key so non-widget code (e.g. push notification
/// handlers) can drive navigation through the router.
final rootNavigatorKey = GlobalKey<NavigatorState>();

final appRouterProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/splash',
    errorBuilder: (context, state) => Scaffold(
      appBar: AppBar(title: const Text('الصفحة غير موجودة')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.link_off, size: 48, color: Colors.grey),
              const SizedBox(height: 16),
              const Text('تعذّر فتح هذه الصفحة.',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              Text(state.uri.toString(),
                  style: const TextStyle(fontSize: 12, color: Colors.grey)),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: () => context.go('/'),
                child: const Text('الرجوع للرئيسية'),
              ),
            ],
          ),
        ),
      ),
    ),
    routes: [
      // Full-screen pre-shell flows — no bottom nav.
      GoRoute(path: '/splash',          builder: (_, __) => const SplashScreen()),
      GoRoute(path: '/onboarding',      builder: (_, __) => const OnboardingScreen()),
      GoRoute(path: '/login',           builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/register',        builder: (_, __) => const RegisterScreen()),
      GoRoute(path: '/forgot-password', builder: (_, __) => const ForgotPasswordScreen()),

      // ── The 5-tab shell ──────────────────────────────────────────────
      // StatefulShellRoute.indexedStack gives every branch its own
      // Navigator stack with its own state, swapped via an internal
      // IndexedStack. Each branch keeps its scroll position, providers,
      // and navigation stack alive when the user switches tabs, AND the
      // bottom-nav stays visible on every screen inside any branch —
      // including deep sub-routes like /article/123 — because the
      // builder's Scaffold wraps the whole `navigationShell`.
      //
      // Why this instead of ShellRoute + a manual Stack overlay:
      // the manual overlay we tried (2.7.x – 2.8.3) hit a framework
      // crash inside StatefulElement.activate every time go_router's
      // internal _CustomNavigator transitioned between two sub-routes
      // of the same type (/article/A → /article/B). StatefulShellRoute
      // is go_router's official solution for this exact problem.
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) =>
            _ShellChrome(navigationShell: navigationShell),
        branches: [
          // ── Branch 0: HOME + every deep route launched from home ──
          //   Article, category, source, search, AI screens (ask/sabah/
          //   weekly), evolving stories, media (telegram/twitter/yt/
          //   reels/gallery/map), trending, clusters, platforms. They
          //   all live under the home branch so a single "back" pops
          //   straight to /, and tapping the home tab from anywhere
          //   resets the branch to /.
          StatefulShellBranch(
            routes: [
              GoRoute(path: '/', builder: (_, __) => const HomeScreen()),
              GoRoute(
                path: '/article/:id',
                builder: (_, s) {
                  final id = int.tryParse(s.pathParameters['id'] ?? '') ?? 0;
                  return ArticleScreen(id: id);
                },
              ),
              GoRoute(
                path: '/topic/:slug',
                builder: (_, s) => TopicScreen(slug: s.pathParameters['slug']!),
              ),
              GoRoute(
                path: '/category/:slug',
                builder: (_, s) => CategoryScreen(slug: s.pathParameters['slug']!),
              ),
              GoRoute(
                path: '/source/:slug',
                builder: (_, s) => SourceScreen(slug: s.pathParameters['slug']!),
              ),
              GoRoute(path: '/search', builder: (_, s) {
                final q = s.uri.queryParameters['q'] ?? '';
                return SearchScreen(initialQuery: q);
              }),
              GoRoute(path: '/trending', builder: (_, __) => const TrendingScreen()),
              GoRoute(path: '/clusters', builder: (_, __) => const ClustersScreen()),
              GoRoute(
                path: '/cluster/:key',
                builder: (_, s) => ClusterScreen(clusterKey: s.pathParameters['key']!),
              ),
              GoRoute(path: '/stories', builder: (_, __) => const EvolvingStoriesScreen()),
              GoRoute(
                path: '/stories/:slug',
                builder: (_, s) => EvolvingStoryScreen(slug: s.pathParameters['slug']!),
              ),
              GoRoute(
                path: '/stories/:slug/quotes',
                builder: (_, s) => QuotesWallScreen(
                  slug: s.pathParameters['slug']!,
                  storyName: s.uri.queryParameters['name'] ?? '',
                ),
              ),
              GoRoute(path: '/timelines',        builder: (_, __) => const TimelinesScreen()),
              GoRoute(path: '/stories-network',  builder: (_, __) => const StoriesNetworkScreen()),
              GoRoute(path: '/platforms',        builder: (_, __) => const PlatformsScreen()),
              GoRoute(path: '/telegram',         builder: (_, __) => const TelegramScreen()),
              GoRoute(path: '/twitter',          builder: (_, __) => const TwitterScreen()),
              GoRoute(path: '/youtube',          builder: (_, __) => const YoutubeScreen()),
              GoRoute(path: '/reels',            builder: (_, __) => const ReelsScreen()),
              GoRoute(path: '/gallery',          builder: (_, __) => const GalleryScreen()),
              GoRoute(path: '/map',              builder: (_, __) => const NewsMapScreen()),
              GoRoute(path: '/sabah',            builder: (_, __) => const SabahScreen()),
              GoRoute(path: '/weekly',           builder: (_, __) => const WeeklyRewindScreen()),
              GoRoute(path: '/ask',              builder: (_, __) => const AskScreen()),
            ],
          ),

          // ── Branch 1: DISCOVER ──
          StatefulShellBranch(
            routes: [
              GoRoute(path: '/discover', builder: (_, __) => const DiscoverScreen()),
            ],
          ),

          // ── Branch 2: SUMMARIES ──
          StatefulShellBranch(
            routes: [
              GoRoute(path: '/summaries', builder: (_, __) => const SummariesScreen()),
            ],
          ),

          // ── Branch 3: FOLLOW (+ bookmarks shares the same branch) ──
          StatefulShellBranch(
            routes: [
              GoRoute(path: '/follow',    builder: (_, __) => const FollowScreen()),
              GoRoute(path: '/bookmarks', builder: (_, __) => const BookmarksScreen()),
            ],
          ),

          // ── Branch 4: PROFILE (+ notifications + settings) ──
          StatefulShellBranch(
            routes: [
              GoRoute(path: '/profile',       builder: (_, __) => const ProfileScreen()),
              GoRoute(path: '/notifications', builder: (_, __) => const NotificationsScreen()),
              GoRoute(path: '/settings',      builder: (_, __) => const SettingsScreen()),
            ],
          ),
        ],
      ),
    ],
  );
});

/// The 5-tab Scaffold that wraps every in-app route via StatefulShellRoute.
/// `navigationShell` is the branched navigator widget go_router builds for us
/// — putting it in the body keeps each branch's state across tab switches.
class _ShellChrome extends ConsumerWidget {
  const _ShellChrome({required this.navigationShell});
  final StatefulNavigationShell navigationShell;

  static const _tabs = [
    _TabSpec(icon: Icons.home_outlined,         sel: Icons.home,         label: 'الرئيسية'),
    _TabSpec(icon: Icons.explore_outlined,      sel: Icons.explore,      label: 'استكشف'),
    _TabSpec(icon: Icons.auto_awesome_outlined, sel: Icons.auto_awesome, label: 'ملخصات'),
    _TabSpec(icon: Icons.bookmark_border,       sel: Icons.bookmark,     label: 'متابعتي'),
    _TabSpec(icon: Icons.person_outline,        sel: Icons.person,       label: 'حسابي'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final index = navigationShell.currentIndex;
    final loc = GoRouterState.of(context).uri.toString();
    return Scaffold(
      body: navigationShell,
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: isDark ? AppColors.neoDarkSurface : AppColors.neoSurface,
          boxShadow: isDark
              ? [
                  BoxShadow(color: AppColors.neoDarkShadow.withOpacity(0.5),
                    offset: const Offset(0, -3), blurRadius: 10),
                ]
              : [
                  BoxShadow(color: AppColors.neoShadowDark.withOpacity(0.25),
                    offset: const Offset(0, -3), blurRadius: 10),
                  BoxShadow(color: AppColors.neoShadowLight.withOpacity(0.7),
                    offset: const Offset(0, -1), blurRadius: 4),
                ],
        ),
        child: BottomNavigationBar(
          currentIndex: index,
          onTap: (i) {
            // Tap the active tab while at its root → refresh + scroll
            // to top (Twitter/Instagram pattern). Tap the active tab
            // while inside a sub-route → pop back to the branch root.
            // Tap a different tab → switch to that branch (preserves
            // its stack via the indexedStack).
            if (i == index) {
              if (i == 0 && loc == '/') {
                HapticFeedback.mediumImpact();
                ref.read(homeScrollToTopSignalProvider.notifier).state++;
                ref.invalidate(homeProvider);
                ref.invalidate(forYouProvider);
                ref.invalidate(evolvingStoriesProvider);
                ref.invalidate(youtubeFeedProvider);
                ScaffoldMessenger.of(context)
                  ..hideCurrentSnackBar()
                  ..showSnackBar(
                    SnackBar(
                      content: Row(children: const [
                        SizedBox(
                          width: 16, height: 16,
                          child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                        ),
                        SizedBox(width: 12),
                        Text('جارٍ تحديث الأخبار…'),
                      ]),
                      duration: const Duration(milliseconds: 1200),
                      behavior: SnackBarBehavior.floating,
                      backgroundColor: AppColors.primary,
                      margin: const EdgeInsets.fromLTRB(16, 0, 16, 90),
                    ),
                  );
                return;
              }
              // Same tab, but currently on a sub-route — pop back to
              // the branch root by re-entering the branch with
              // initialLocation=true.
              navigationShell.goBranch(i, initialLocation: true);
              return;
            }
            navigationShell.goBranch(i);
          },
          items: [
            for (final t in _tabs)
              BottomNavigationBarItem(
                icon: Icon(t.icon),
                activeIcon: Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppColors.primary.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(t.sel, color: AppColors.primary),
                ),
                label: t.label,
              ),
          ],
        ),
      ),
    );
  }
}

class _TabSpec {
  const _TabSpec({required this.icon, required this.sel, required this.label});
  final IconData icon;
  final IconData sel;
  final String label;
}
