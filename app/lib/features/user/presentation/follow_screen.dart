import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';
import '../../auth/data/auth_storage.dart';
import '../../content/data/content_repository.dart';
import '../data/user_repository.dart';

class FollowScreen extends ConsumerWidget {
  const FollowScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!AuthStorage.isAuthenticated) {
      return Scaffold(
        appBar: AppBar(title: const Text('متابعتي')),
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.person_outline, size: 64, color: Colors.grey),
              const SizedBox(height: 12),
              const Text('سجّل دخولك لمتابعة المصادر والأقسام'),
              const SizedBox(height: 12),
              ElevatedButton(
                onPressed: () => context.push('/login'),
                child: const Text('دخول'),
              ),
            ],
          ),
        ),
      );
    }

    return DefaultTabController(
      length: 4,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('متابعتي'),
          bottom: const TabBar(
            isScrollable: true,
            tabs: [
              Tab(text: 'المحفوظات'),
              Tab(text: 'الأقسام'),
              Tab(text: 'المصادر'),
              Tab(text: 'القصص'),
            ],
          ),
        ),
        body: const TabBarView(
          children: [
            _BookmarksTab(),
            _FollowedCategoriesTab(),
            _FollowedSourcesTab(),
            _FollowedStoriesTab(),
          ],
        ),
      ),
    );
  }
}

// ── Bookmarks Tab ──
class _BookmarksTab extends ConsumerWidget {
  const _BookmarksTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bookmarks = ref.watch(_userBookmarksProvider);
    return bookmarks.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(
        message: '$e',
        onRetry: () => ref.invalidate(_userBookmarksProvider),
      ),
      data: (list) => list.isEmpty
          ? const EmptyView(message: 'لا توجد مقالات محفوظة بعد', icon: Icons.bookmark_border)
          : RefreshIndicator(
              onRefresh: () async => ref.invalidate(_userBookmarksProvider),
              child: ListView.separated(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                itemCount: list.length,
                separatorBuilder: (_, __) => const SizedBox(height: 10),
                itemBuilder: (_, i) {
                  final a = list[i];
                  return ListTile(
                    title: Text(a.title, maxLines: 2, overflow: TextOverflow.ellipsis),
                    subtitle: a.source != null ? Text(a.source!.name) : null,
                    onTap: () => context.push('/article/${a.id}'),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    tileColor: Theme.of(context).cardColor,
                  );
                },
              ),
            ),
    );
  }
}

final _userBookmarksProvider = FutureProvider((ref) {
  return ref.watch(userRepositoryProvider).bookmarks();
});

// ── Followed Categories Tab ──
class _FollowedCategoriesTab extends ConsumerWidget {
  const _FollowedCategoriesTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final followedIds = ref.watch(followedIdsProvider);
    final categories = ref.watch(categoriesProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final categoryIds = followedIds['category'] ?? {};

    return categories.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(categoriesProvider)),
      data: (allCats) {
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: allCats.length,
          separatorBuilder: (_, __) => const SizedBox(height: 8),
          itemBuilder: (_, i) {
            final cat = allCats[i];
            final isFollowed = categoryIds.contains(cat.id);
            final color = AppColors.categoryColors[cat.color] ?? AppColors.primary;
            return ListTile(
              leading: Container(
                width: 40, height: 40,
                decoration: BoxDecoration(
                  color: color.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                alignment: Alignment.center,
                child: Text(cat.icon ?? '', style: const TextStyle(fontSize: 20)),
              ),
              title: Text(cat.name,
                  style: TextStyle(fontWeight: FontWeight.w700,
                      color: isDark ? Colors.white : AppColors.textLight)),
              trailing: _FollowButton(
                isFollowed: isFollowed,
                onTap: () => ref.read(followedIdsProvider.notifier).toggle('category', cat.id),
              ),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              tileColor: Theme.of(context).cardColor,
            );
          },
        );
      },
    );
  }
}

// ── Followed Sources Tab ──
class _FollowedSourcesTab extends ConsumerWidget {
  const _FollowedSourcesTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final followedIds = ref.watch(followedIdsProvider);
    final sources = ref.watch(sourcesProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final sourceIds = followedIds['source'] ?? {};

    return sources.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(sourcesProvider)),
      data: (allSources) {
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: allSources.length,
          separatorBuilder: (_, __) => const SizedBox(height: 8),
          itemBuilder: (_, i) {
            final src = allSources[i];
            final isFollowed = sourceIds.contains(src.id);
            return ListTile(
              leading: Container(
                width: 40, height: 40,
                decoration: BoxDecoration(
                  color: AppColors.primary.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                alignment: Alignment.center,
                child: Text(src.logoLetter ?? src.name.substring(0, 1),
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700,
                        color: AppColors.primary)),
              ),
              title: Text(src.name,
                  style: TextStyle(fontWeight: FontWeight.w700,
                      color: isDark ? Colors.white : AppColors.textLight)),
              trailing: _FollowButton(
                isFollowed: isFollowed,
                onTap: () => ref.read(followedIdsProvider.notifier).toggle('source', src.id),
              ),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              tileColor: Theme.of(context).cardColor,
            );
          },
        );
      },
    );
  }
}

// ── Followed Stories Tab ──
class _FollowedStoriesTab extends ConsumerWidget {
  const _FollowedStoriesTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final followedIds = ref.watch(followedIdsProvider);
    final stories = ref.watch(evolvingStoriesProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final storyIds = followedIds['story'] ?? {};

    return stories.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(evolvingStoriesProvider)),
      data: (allStories) {
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: allStories.length,
          separatorBuilder: (_, __) => const SizedBox(height: 8),
          itemBuilder: (_, i) {
            final story = allStories[i];
            final isFollowed = storyIds.contains(story.id);
            return ListTile(
              leading: Container(
                width: 40, height: 40,
                decoration: BoxDecoration(
                  color: Colors.teal.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                alignment: Alignment.center,
                child: Text(story.icon.isNotEmpty ? story.icon : '📅',
                    style: const TextStyle(fontSize: 20)),
              ),
              title: Text(story.name,
                  style: TextStyle(fontWeight: FontWeight.w700,
                      color: isDark ? Colors.white : AppColors.textLight)),
              subtitle: Text('${story.articleCount} خبر',
                  style: Theme.of(context).textTheme.bodySmall),
              trailing: _FollowButton(
                isFollowed: isFollowed,
                onTap: () => ref.read(followedIdsProvider.notifier).toggle('story', story.id),
              ),
              onTap: () => context.push('/stories/${story.slug}'),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              tileColor: Theme.of(context).cardColor,
            );
          },
        );
      },
    );
  }
}

// ── Follow Button Widget ──
class _FollowButton extends StatelessWidget {
  const _FollowButton({required this.isFollowed, required this.onTap});
  final bool isFollowed;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return TextButton(
      onPressed: onTap,
      style: TextButton.styleFrom(
        backgroundColor: isFollowed ? AppColors.primary.withOpacity(0.1) : Colors.grey.withOpacity(0.1),
        foregroundColor: isFollowed ? AppColors.primary : Colors.grey,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      ),
      child: Text(isFollowed ? 'متابَع' : 'متابعة',
          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
    );
  }
}
