import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../content/presentation/discover_screen.dart';
import '../content/presentation/home_screen.dart';
import '../content/presentation/summaries_screen.dart';
import '../user/presentation/follow_screen.dart';
import '../user/presentation/profile_screen.dart';

class MainShell extends StatelessWidget {
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

  int _indexFor(String location) {
    for (var i = 0; i < _tabs.length; i++) {
      if (location == _tabs[i].path) return i;
    }
    if (location.startsWith('/discover')) return 1;
    if (location.startsWith('/summaries')) return 2;
    if (location.startsWith('/follow')) return 3;
    if (location.startsWith('/profile')) return 4;
    return 0;
  }

  Widget _bodyFor(int index) {
    return switch (index) {
      0 => const HomeScreen(),
      1 => const DiscoverScreen(),
      2 => const SummariesScreen(),
      3 => const FollowScreen(),
      4 => const ProfileScreen(),
      _ => const HomeScreen(),
    };
  }

  @override
  Widget build(BuildContext context) {
    final loc = state.uri.toString();
    final index = _indexFor(loc);

    return Scaffold(
      body: _bodyFor(index),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: index,
        onTap: (i) => context.go(_tabs[i].path),
        items: [
          for (final t in _tabs)
            BottomNavigationBarItem(
              icon: Icon(t.icon),
              activeIcon: Icon(t.sel),
              label: t.label,
            ),
        ],
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
