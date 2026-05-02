import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/login_screen.dart';
import '../../features/auth/presentation/register_screen.dart';
import '../../features/content/presentation/article_screen.dart';
import '../../features/content/presentation/category_screen.dart';
import '../../features/content/presentation/clusters_screen.dart';
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
import '../../features/podcast/presentation/podcast_screen.dart';
import '../../features/shell/main_shell.dart';
import '../../features/splash/splash_screen.dart';
import '../../features/user/presentation/bookmarks_screen.dart';
import '../../features/user/presentation/notifications_screen.dart';
import '../../features/user/presentation/profile_screen.dart';
import '../../features/user/presentation/settings_screen.dart';

final appRouterProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    initialLocation: '/splash',
    routes: [
      GoRoute(path: '/splash', builder: (_, __) => const SplashScreen()),
      GoRoute(path: '/onboarding', builder: (_, __) => const OnboardingScreen()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/register', builder: (_, __) => const RegisterScreen()),

      ShellRoute(
        builder: (ctx, state, child) => MainShell(state: state, child: child),
        routes: [
          GoRoute(path: '/', builder: (_, __) => const _HomeRoot()),
          GoRoute(path: '/discover', builder: (_, __) => const _DiscoverRoot()),
          GoRoute(path: '/platforms', builder: (_, __) => const PlatformsScreen()),
          GoRoute(path: '/follow', builder: (_, __) => const _FollowRoot()),
          GoRoute(path: '/profile', builder: (_, __) => const ProfileScreen()),
        ],
      ),

      GoRoute(
        path: '/article/:id',
        builder: (_, s) => ArticleScreen(id: int.parse(s.pathParameters['id']!)),
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
      GoRoute(path: '/search', builder: (_, __) => const SearchScreen()),

      // Evolving stories
      GoRoute(path: '/stories', builder: (_, __) => const EvolvingStoriesScreen()),
      GoRoute(
        path: '/stories/:slug',
        builder: (_, s) => EvolvingStoryScreen(slug: s.pathParameters['slug']!),
      ),
      GoRoute(path: '/timelines', builder: (_, __) => const TimelinesScreen()),

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

      // Podcast (standalone)
      GoRoute(path: '/podcast', builder: (_, __) => const PodcastScreen()),

      // AI Q&A
      GoRoute(path: '/ask', builder: (_, __) => const AskScreen()),

      // User-only
      GoRoute(path: '/bookmarks', builder: (_, __) => const BookmarksScreen()),
      GoRoute(path: '/notifications', builder: (_, __) => const NotificationsScreen()),
      GoRoute(path: '/settings', builder: (_, __) => const SettingsScreen()),
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
