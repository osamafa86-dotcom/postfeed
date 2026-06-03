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

  String? _lastLoc;

  @override
  void didUpdateWidget(covariant MainShell oldWidget) {
    super.didUpdateWidget(oldWidget);
    final loc = widget.state.uri.toString();
    final prev = _lastLoc;
    if (prev != null && prev != loc) {
      DebugTrace.log('shell.nav', '$prev → $loc');
      // Route changed — drop any sticky focus / keyboard left behind by
      // the previous screen. AskScreen's TextField in particular kept the
      // soft keyboard's input connection alive across navigation, which
      // skewed the next screen's MediaQuery and showed up as a blank
      // gray article view after returning from Ask/Sabah/Weekly.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        try {
          final focus = FocusManager.instance.primaryFocus;
          if (focus != null && focus.hasFocus) {
            DebugTrace.log('shell', 'unfocus on route change');
            focus.unfocus();
          }
        } catch (e) {
          DebugTrace.log('shell', 'unfocus failed: $e', level: DebugLevel.warn);
        }
      });
    }
    _lastLoc = loc;
  }

  @override
  void initState() {
    super.initState();
    _lastLoc = widget.state.uri.toString();
  }

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

    // Trace overlay probes — overwritten each rebuild so the panel
    // always shows the latest shell state.
    DebugTrace.probe('shell.loc', loc);
    DebugTrace.probe('shell.isTab', isTab.toString());
    DebugTrace.probe('shell.child', widget.child.runtimeType.toString());
    final mq = MediaQuery.of(context);
    DebugTrace.probe('shell.viewport',
      '${mq.size.width.toStringAsFixed(0)}×${mq.size.height.toStringAsFixed(0)} '
      'inset.bottom=${mq.viewInsets.bottom.toStringAsFixed(0)}');

    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      // Root-cause fix for the gray/red error widget on /article (and
      // other sub-routes) after returning from Ask/Sabah/Weekly:
      //
      // The previous if-else swapped between IndexedStack(_pages) for
      // tabs and widget.child for sub-routes. Each switch tore the
      // entire IndexedStack out of the tree, which disposed every
      // _pages State (HomeScreen, DiscoverScreen, …). When the user
      // navigated back to a tab and we re-mounted the same
      // `const HomeScreen()` instance, Flutter called
      // StatefulElement.activate on it, found `_state == null` (it had
      // been disposed), and crashed:
      //   _TypeError: Null check operator used on a null value
      //   #0 StatefulElement.state (framework.dart:5938)
      //   #1 StatefulElement.activate
      //
      // Fix: keep IndexedStack in the tree at all times. When a sub-
      // route is active we render its widget on top of the same Stack
      // — the sub-route's own Scaffold has an opaque background so the
      // user doesn't see the tabs underneath, but the tab States stay
      // alive across deep navigation.
      body: Stack(
        children: [
          // Base layer — always mounted, preserves tab state. TickerMode
          // pauses animations on hidden tabs (story carousel, pulse tag,
          // ...) while a sub-route is on screen so they don't burn CPU.
          TickerMode(
            enabled: isTab,
            child: IndexedStack(index: index, children: _pages),
          ),
          // Overlay layer — only rendered for sub-routes. The sub-route
          // Scaffold's solid background covers the IndexedStack visually.
          if (!isTab) widget.child,
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
              // Jump the home feed back to offset 0 first — visible
              // motion confirms the tap before the network refresh
              // returns. The signal-provider bump triggers an animate-
              // to-top inside _HomeBody.
              ref.read(homeScrollToTopSignalProvider.notifier).state++;
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
