import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/theme/app_theme.dart';

/// Mirrors the website's dark navy `<header class="site-header">`:
/// ┌──────────────────────────────────────────────────────────┐
/// │ [N] نيوز فيد   ●مباشر 13:42   🌓 🔔 [انضم مجاناً]  ☰      │
/// └──────────────────────────────────────────────────────────┘
///
/// Designed as a SliverAppBar so it pins above the StatsStrip while the rest
/// of the home feed scrolls underneath it. RTL is handled by the surrounding
/// Directionality — `actions` end up on the visual left in Arabic.
class SiteHeader extends StatelessWidget {
  const SiteHeader({super.key});

  @override
  Widget build(BuildContext context) {
    return SliverAppBar(
      pinned: true,
      floating: false,
      titleSpacing: 12,
      toolbarHeight: 64,
      backgroundColor: AppColors.headerLight,
      foregroundColor: AppColors.headerText,
      automaticallyImplyLeading: false,
      title: const _Logo(),
      actions: [
        const _LivePill(),
        const SizedBox(width: 4),
        _IconBtn(
          emoji: '🌓',
          tooltip: 'تبديل الثيم',
          onTap: () {/* hooked up in phase 5 */},
        ),
        _IconBtn(
          emoji: '🔔',
          tooltip: 'الإشعارات',
          onTap: () => context.push('/notifications'),
        ),
        _IconBtn(
          emoji: '🔍',
          tooltip: 'بحث',
          onTap: () => context.push('/search'),
        ),
        const SizedBox(width: 4),
        _JoinButton(onTap: () => context.push('/profile')),
        const SizedBox(width: 8),
      ],
    );
  }
}

class _Logo extends StatelessWidget {
  const _Logo();

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 38,
          height: 38,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [AppColors.gold, AppColors.goldBright],
            ),
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Text(
            'N',
            style: TextStyle(
              color: Color(0xFF1A1A2E),
              fontWeight: FontWeight.w900,
              fontSize: 20,
              height: 1,
            ),
          ),
        ),
        const SizedBox(width: 10),
        const Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'نيوز فيد',
              style: TextStyle(
                color: AppColors.headerText,
                fontWeight: FontWeight.w800,
                fontSize: 17,
                height: 1.1,
              ),
            ),
            SizedBox(height: 2),
            Text(
              'أخبار فلسطين',
              style: TextStyle(
                color: Color(0xFFB7BBC8),
                fontWeight: FontWeight.w500,
                fontSize: 11,
                height: 1,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _LivePill extends StatefulWidget {
  const _LivePill();

  @override
  State<_LivePill> createState() => _LivePillState();
}

class _LivePillState extends State<_LivePill> {
  Timer? _tick;
  late String _time;

  @override
  void initState() {
    super.initState();
    _time = _format(DateTime.now());
    _tick = Timer.periodic(const Duration(seconds: 30), (_) {
      if (!mounted) return;
      setState(() => _time = _format(DateTime.now()));
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  static String _format(DateTime d) {
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(d.hour)}:${two(d.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 4),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.08),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.white.withOpacity(0.12)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: const BoxDecoration(
              color: AppColors.breaking,
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 6),
          const Text(
            'مباشر',
            style: TextStyle(
              color: AppColors.headerText,
              fontWeight: FontWeight.w700,
              fontSize: 11,
            ),
          ),
          const SizedBox(width: 6),
          Text(
            _time,
            style: const TextStyle(
              color: Color(0xFFB7BBC8),
              fontWeight: FontWeight.w600,
              fontSize: 11,
              fontFeatures: [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}

class _IconBtn extends StatelessWidget {
  const _IconBtn({required this.emoji, required this.tooltip, required this.onTap});
  final String emoji;
  final String tooltip;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: tooltip,
      onPressed: onTap,
      iconSize: 20,
      visualDensity: VisualDensity.compact,
      padding: const EdgeInsets.all(6),
      icon: Text(emoji, style: const TextStyle(fontSize: 18)),
    );
  }
}

class _JoinButton extends StatelessWidget {
  const _JoinButton({required this.onTap});
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [AppColors.gold, AppColors.goldBright],
            ),
            borderRadius: BorderRadius.circular(20),
          ),
          child: const Text(
            'انضم مجاناً',
            style: TextStyle(
              color: Color(0xFF1A1A2E),
              fontWeight: FontWeight.w800,
              fontSize: 12,
            ),
          ),
        ),
      ),
    );
  }
}
