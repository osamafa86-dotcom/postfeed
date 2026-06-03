import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/debug/debug_state.dart';
import '../../core/theme/app_theme.dart';
import '../content/data/content_repository.dart';
import '../content/presentation/discover_screen.dart';
import '../content/presentation/home_screen.dart';
import '../content/presentation/summaries_screen.dart';
import '../media/data/media_repository.dart';
import '../user/presentation/follow_screen.dart';
import '../user/presentation/profile_screen.dart';

/// The 5-tab shell. Only handles tab routes (/, /discover, /summaries,
/// /follow, /profile) — every other route in the app is OUTSIDE the
/// ShellRoute and pushed full-screen on top of this widget.
///
/// History: we briefly tried to keep the bottom nav visible on every
/// sub-route by moving them inside the ShellRoute and overlaying
/// widget.child on top of IndexedStack. That combo crashed inside
/// StatefulElement.activate (framework.dart:5938) on the second
/// sub-route navigation in a session — Flutter ended up reactivating
/// elements whose State had already been disposed. Reverted to the
/// same routing topology as the App Store-approved 2.2.1+18 build:
/// sub-routes are pushed full-screen, losing the bottom nav for those
/// pages but gaining rock-solid navigation.
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

  @override
  Widget build(BuildContext context) {
    final loc = widget.state.uri.toString();
    final index = _indexFor(loc);

    // Trace overlay probes — overwritten each rebuild so the panel
    // always shows the latest shell state.
    DebugTrace.probe('shell.loc', loc);
    DebugTrace.probe('shell.tab', MainShell._tabs[index].path);

    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      body: IndexedStack(
        index: index,
        children: _pages,
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
            // feed AND animates the home scroll view back to the top —
            // matches Twitter/Instagram. Sub-routes can no longer map
            // to a tab index since they live outside the shell, so the
            // loc==tab.path check is enough.
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
