import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/forgot_password_screen.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/auth/presentation/register_screen.dart';
import '../../features/content/presentation/article_screen.dart';
import '../../features/content/presentation/category_screen.dart';
import '../../features/content/presentation/clusters_screen.dart';
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
import '../../features/media/presentation/ask_screen.dart';
import '../../features/media/presentation/gallery_screen.dart';
import '../../features/media/presentation/reels_screen.dart';
import '../../features/media/presentation/telegram_screen.dart';
import '../../features/media/presentation/twitter_screen.dart';
import '../../features/media/presentation/youtube_screen.dart';
import '../../features/media/presentation/platforms_screen.dart';
import '../../features/onboarding/onboarding_screen.dart';
import '../../features/shell/main_shell.dart';
import '../../features/splash/splash_screen.dart';
import '../../features/user/presentation/bookmarks_screen.dart';
import '../../features/user/presentation/notifications_screen.dart';
import '../../features/user/presentation/profile_screen.dart';
import '../../features/user/presentation/settings_screen.dart';

/// Global navigator key so non-widget code (e.g. push notification
/// handlers) can drive navigation through the router.
final rootNavigatorKey = GlobalKey<NavigatorState>();

final appRouterProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/splash',
    // Without this, a malformed path (deep link / push payload) drops
    // the user on go_router's bare-bones default error widget.
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
      GoRoute(path: '/splash', builder: (_, __) => const SplashScreen()),
      GoRoute(path: '/onboarding', builder: (_, __) => const OnboardingScreen()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/register', builder: (_, __) => const RegisterScreen()),
      GoRoute(path: '/forgot-password', builder: (_, __) => const ForgotPasswordScreen()),

      // All in-app routes live inside the shell so the bottom nav stays
      // visible no matter how deep the user navigates — tapping a tab
      // from anywhere always brings them home in one click. Auth /
      // splash / onboarding stay outside (full-screen, no nav bar).
      ShellRoute(
        builder: (ctx, state, child) => MainShell(state: state, child: child),
        routes: [
          // ── Primary tabs ──
          GoRoute(path: '/', builder: (_, __) => const _HomeRoot()),
          GoRoute(path: '/discover', builder: (_, __) => const _DiscoverRoot()),
          GoRoute(path: '/summaries', builder: (_, __) => const _SummariesRoot()),
          GoRoute(path: '/follow', builder: (_, __) => const _FollowRoot()),
          GoRoute(path: '/profile', builder: (_, __) => const ProfileScreen()),

          // ── Inner pages — render below the persistent bottom nav ──
          GoRoute(
            path: '/platforms',
            builder: (_, __) => const PlatformsScreen(),
          ),
          GoRoute(
            path: '/article/:id',
            builder: (_, s) {
              final raw = s.pathParameters['id'];
              final id = int.tryParse(raw ?? '') ?? 0;
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
          GoRoute(path: '/trending', builder: (_, __) => const TrendingScreen()),
          GoRoute(path: '/clusters', builder: (_, __) => const ClustersScreen()),
          GoRoute(path: '/search', builder: (_, state) {
            final q = state.uri.queryParameters['q'] ?? '';
            return SearchScreen(initialQuery: q);
          }),

          // Evolving stories
          GoRoute(path: '/stories', builder: (_, __) => const EvolvingStoriesScreen()),
          GoRoute(
            path: '/stories/:slug',
            builder: (_, s) => EvolvingStoryScreen(slug: s.pathParameters['slug']!),
          ),
          GoRoute(path: '/timelines', builder: (_, __) => const TimelinesScreen()),
          GoRoute(path: '/stories-network', builder: (_, __) => const StoriesNetworkScreen()),
          GoRoute(
            path: '/stories/:slug/quotes',
            builder: (_, s) => QuotesWallScreen(
              slug: s.pathParameters['slug']!,
              storyName: s.uri.queryParameters['name'] ?? '',
            ),
          ),

          // Media
          GoRoute(path: '/telegram', builder: (_, __) => const TelegramScreen()),
          GoRoute(path: '/twitter', builder: (_, __) => const TwitterScreen()),
          GoRoute(path: '/youtube', builder: (_, __) => const YoutubeScreen()),
          GoRoute(path: '/reels', builder: (_, __) => const ReelsScreen()),
          GoRoute(path: '/gallery', builder: (_, __) => const GalleryScreen()),
          GoRoute(path: '/map', builder: (_, __) => const NewsMapScreen()),

          // Daily / weekly briefs
          GoRoute(path: '/sabah', builder: (_, __) => const SabahScreen()),
          GoRoute(path: '/weekly', builder: (_, __) => const WeeklyRewindScreen()),

          // AI Q&A
          GoRoute(path: '/ask', builder: (_, __) => const AskScreen()),

          // User-only
          GoRoute(path: '/bookmarks', builder: (_, __) => const BookmarksScreen()),
          GoRoute(path: '/notifications', builder: (_, __) => const NotificationsScreen()),
          GoRoute(path: '/settings', builder: (_, __) => const SettingsScreen()),
        ],
      ),
    ],
  );
});

// Lightweight wrappers so the shell tabs share the existing screens.
class _HomeRoot extends StatelessWidget {
  const _HomeRoot();
  @override
  Widget build(BuildContext context) =>
      const _LazyImport('home');
}

class _DiscoverRoot extends StatelessWidget {
  const _DiscoverRoot();
  @override
  Widget build(BuildContext context) =>
      const _LazyImport('discover');
}

class _FollowRoot extends StatelessWidget {
  const _FollowRoot();
  @override
  Widget build(BuildContext context) =>
      const _LazyImport('follow');
}

class _SummariesRoot extends StatelessWidget {
  const _SummariesRoot();
  @override
  Widget build(BuildContext context) => const SummariesScreen();
}

class _LazyImport extends StatelessWidget {
  const _LazyImport(this.tab);
  final String tab;

  @override
  Widget build(BuildContext context) {
    // Real screens are imported in features/shell/main_shell.dart, which
    // owns the tab content. This wrapper is unreachable in normal use.
    return const SizedBox.shrink();
  }
}
