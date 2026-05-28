import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';
import '../../auth/data/auth_state_provider.dart';
import '../../auth/data/auth_storage.dart';
import '../../content/data/content_repository.dart';
import '../data/user_repository.dart';

class FollowScreen extends ConsumerWidget {
  const FollowScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Watch the reactive auth state so this screen rebuilds when the
    // user logs in/out — `AuthStorage.isAuthenticated` alone is a
    // static getter that won't trigger a rebuild from inside MainShell's
    // IndexedStack (the cause of Apple's 2.2.1(19) 2.1(a) rejection).
    final isAuthed = ref.watch(authStateProvider);
    if (!isAuthed) {
      return Scaffold(
        appBar: AppBar(title: const Text('متابعتي')),
        body: EmptyView(
          icon: Icons.person_outline,
          message: 'سجّل دخولك لمتابعة المصادر',
          hint: 'تابع مصادرك وأقسامك المفضّلة لتحصل على فيد مخصّص لك.',
          actionLabel: 'تسجيل الدخول',
          onAction: () => context.push('/login'),
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
          ? EmptyView(
              icon: Icons.bookmark_border,
              message: 'لا توجد مقالات محفوظة بعد',
              hint: 'احفظ مقالاً بالضغط على أيقونة 🔖 لتجده هنا لاحقاً.',
              actionLabel: 'تصفّح الأخبار',
              onAction: () => context.go('/'),
            )
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
        return RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(categoriesProvider);
            ref.read(followedIdsProvider.notifier).refresh();
          },
          child: ListView.separated(
            physics: const AlwaysScrollableScrollPhysics(),
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
                  onTap: () => _toggleFollow(context, ref, 'category', cat.id),
                ),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                tileColor: Theme.of(context).cardColor,
              );
            },
          ),
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
        return RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(sourcesProvider);
            ref.read(followedIdsProvider.notifier).refresh();
          },
          child: ListView.separated(
            physics: const AlwaysScrollableScrollPhysics(),
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
                  child: Text(
                      src.logoLetter ??
                          (src.name.isNotEmpty ? src.name.substring(0, 1) : '؟'),
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700,
                          color: AppColors.primary)),
                ),
                title: Text(src.name,
                    style: TextStyle(fontWeight: FontWeight.w700,
                        color: isDark ? Colors.white : AppColors.textLight)),
                trailing: _FollowButton(
                  isFollowed: isFollowed,
                  onTap: () => _toggleFollow(context, ref, 'source', src.id),
                ),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                tileColor: Theme.of(context).cardColor,
              );
            },
          ),
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
        return RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(evolvingStoriesProvider);
            ref.read(followedIdsProvider.notifier).refresh();
          },
          child: ListView.separated(
            physics: const AlwaysScrollableScrollPhysics(),
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
                  onTap: () => _toggleFollow(context, ref, 'story', story.id),
                ),
                onTap: () => context.push('/stories/${story.slug}'),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                tileColor: Theme.of(context).cardColor,
              );
            },
          ),
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

// Toggles a follow and surfaces a message if the request fails, so the
// button never silently does nothing.
Future<void> _toggleFollow(
    BuildContext context, WidgetRef ref, String type, int id) async {
  try {
    await ref.read(followedIdsProvider.notifier).toggle(type, id);
  } catch (e) {
    if (!context.mounted) return;
    // Surface the real reason so the failure isn't a mystery — server
    // responses like "يلزم تسجيل الدخول" or "تم تجاوز الحد المسموح" carry
    // their own Arabic text already.
    final msg = e is ApiException
        ? e.userMessage
        : 'تعذّر تنفيذ العملية، تحقّق من اتصالك وحاول مجدداً';
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
  }
}
