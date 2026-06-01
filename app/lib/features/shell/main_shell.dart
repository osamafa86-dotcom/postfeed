import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/theme/app_theme.dart';
import '../content/data/content_repository.dart';
import '../content/presentation/discover_screen.dart';
import '../content/presentation/home_screen.dart';
import '../content/presentation/summaries_screen.dart';
import '../media/data/media_repository.dart';
import '../user/presentation/follow_screen.dart';
import '../user/presentation/profile_screen.dart';

class MainShell extends ConsumerStatefulWidget {
  const MainShell({super.key, required this.state, required this.child});
  final GoRouterState state;
  final Widget child;

  static const _tabs = [
    _TabSpec(path: '/',           icon: Icons.home_outlined,            sel: Icons.home,            label: 'الرئيسية'),
    _TabSpec(path: '/discover',   icon: Icons.explore_outlined,         sel: Icons.explore,         label: 'استكشف'),
    _TabSpec(path: '/summaries',  icon: Icons.auto_awesome_outlined,    sel: Icons.auto_awesome,    label: 'ملخصات'),
    _TabSpec(path: '/follow',     icon: Icons.bookmark_border,          sel: Icons.bookmark,        label: 'متابعتي'),
    _TabSpec(path: '/profile',    icon: Icons.person_outline,           sel: Icons.person,          label: 'حسابي'),
  ];

  @override
  ConsumerState<MainShell> createState() => _MainShellState();
}

class _MainShellState extends ConsumerState<MainShell> {
  // Pages are created once and kept alive via IndexedStack.
  final _pages = const [
    HomeScreen(),
    DiscoverScreen(),
    SummariesScreen(),
    FollowScreen(),
    ProfileScreen(),
  ];

  int _indexFor(String location) {
    for (var i = 0; i < MainShell._tabs.length; i++) {
      if (location == MainShell._tabs[i].path) return i;
    }
    if (location.startsWith('/discover')) return 1;
    if (location.startsWith('/summaries')) return 2;
    if (location.startsWith('/follow')) return 3;
    if (location.startsWith('/profile')) return 4;
    return 0;
  }

  /// Whether the location corresponds to one of the five primary tabs.
  /// The shell shows IndexedStack for tab routes (preserves per-tab scroll
  /// state, AI providers, etc.) and renders the routed `child` directly
  /// for inner pages like /article/123 — so the bottom nav stays put no
  /// matter how deep the user navigates.
  bool _isTabRoute(String loc) {
    for (final t in MainShell._tabs) {
      if (loc == t.path) return true;
    }
    return false;
  }

  @override
  Widget build(BuildContext context) {
    final loc = widget.state.uri.toString();
    final index = _indexFor(loc);
    final isTab = _isTabRoute(loc);

    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      // IndexedStack is ALWAYS in the tree (wrapped in Offstage when an
      // inner page is showing) so each tab's scroll position, providers,
      // and AnimatedSwitcher state survive deep navigation. The inner
      // page renders on top — same persistent bottom nav underneath, so
      // a single tap on any tab takes the user home from any depth.
      body: Stack(
        children: [
          Offstage(
            offstage: !isTab,
            child: TickerMode(
              enabled: isTab,
              child: IndexedStack(index: index, children: _pages),
            ),
          ),
          if (!isTab) Positioned.fill(child: widget.child),
        ],
      ),
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
            // Tapping the home tab while already on home refreshes the
            // feed — matches the standard pattern in Twitter/Instagram.
            // Check the exact location (not index) because sub-routes
            // like /article/123 also map to index 0 and should navigate
            // back to /, not trigger a refresh.
            if (i == 0 && loc == '/') {
              HapticFeedback.mediumImpact();
              ref.invalidate(homeProvider);
              ref.invalidate(forYouProvider);
              ref.invalidate(evolvingStoriesProvider);
              ref.invalidate(youtubeFeedProvider);
              // Brief visible confirmation so the tap doesn't feel like
              // it did nothing while the network round-trip is in flight.
              ScaffoldMessenger.of(context)
                ..hideCurrentSnackBar()
                ..showSnackBar(
                  SnackBar(
                    content: Row(
                      children: const [
                        SizedBox(
                          width: 16, height: 16,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        ),
                        SizedBox(width: 12),
                        Text('جارٍ تحديث الأخبار…'),
                      ],
                    ),
                    duration: const Duration(milliseconds: 1200),
                    behavior: SnackBarBehavior.floating,
                    backgroundColor: AppColors.primary,
                    margin: const EdgeInsets.fromLTRB(16, 0, 16, 90),
                  ),
                );
              return;
            }
            context.go(MainShell._tabs[i].path);
          },
          items: [
            for (final t in MainShell._tabs)
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
  const _TabSpec({required this.path, required this.icon, required this.sel, required this.label});
  final String path;
  final IconData icon;
  final IconData sel;
  final String label;
}
