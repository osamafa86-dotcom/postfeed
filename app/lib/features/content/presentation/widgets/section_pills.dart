import 'package:flutter/material.dart';

import '../../../../core/theme/app_theme.dart';

/// One section-pill descriptor. `kind=link` means tapping opens a route,
/// `kind=anchor` means it scrolls to a section in the home feed.
enum HomeSectionKind { anchor, link }

class HomeSectionPill {
  const HomeSectionPill({
    required this.id,
    required this.label,
    required this.icon,
    this.kind = HomeSectionKind.anchor,
    this.route,
    this.accent,
  });
  final String id;
  final String label;
  final String icon;
  final HomeSectionKind kind;
  final String? route;
  final Color? accent;
}

/// Mirrors the website's `<div class="sections-nav">` row of `.sec-pill`
/// chips:
///   📰 الكل · 🤖 اسأل الأخبار · 🔴 عاجل · ⏱ آخر الأخبار ·
///   🇵🇸 فلسطين · 🔥 الأكثر تداولاً · 🎬 ريلز
///
/// Active pill gets a filled blue background; the rest get a card surface
/// with a subtle border. Order matches the website exactly.
class SectionPills extends StatelessWidget {
  const SectionPills({
    super.key,
    required this.pills,
    required this.activeId,
    required this.onSelect,
  });

  final List<HomeSectionPill> pills;
  final String activeId;
  final ValueChanged<HomeSectionPill> onSelect;

  static const List<HomeSectionPill> defaultPills = [
    HomeSectionPill(id: 'all',       label: 'الكل',          icon: '📰'),
    HomeSectionPill(
      id: 'ask',
      label: 'اسأل الأخبار',
      icon: '🤖',
      kind: HomeSectionKind.link,
      route: '/ask',
      accent: AppColors.purple,
    ),
    HomeSectionPill(id: 'breaking',  label: 'عاجل',          icon: '🔴', accent: AppColors.breaking),
    HomeSectionPill(id: 'latest',    label: 'آخر الأخبار',   icon: '⏱'),
    HomeSectionPill(id: 'palestine', label: 'فلسطين',        icon: '🇵🇸', accent: AppColors.accent3),
    HomeSectionPill(id: 'trending',  label: 'الأكثر تداولاً', icon: '🔥', accent: AppColors.gold),
    HomeSectionPill(
      id: 'reels',
      label: 'ريلز',
      icon: '🎬',
      kind: HomeSectionKind.link,
      route: '/reels',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.surfaceLight,
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsetsDirectional.only(start: 12, end: 12),
        child: Row(
          children: [
            for (final pill in pills) ...[
              _Pill(
                pill: pill,
                active: pill.id == activeId,
                onTap: () => onSelect(pill),
              ),
              const SizedBox(width: 8),
            ],
          ],
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.pill, required this.active, required this.onTap});
  final HomeSectionPill pill;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final accent = pill.accent ?? AppColors.accent;
    final bg = active ? accent : AppColors.cardLight;
    final fg = active ? Colors.white : AppColors.textLight;
    final border = active ? accent : AppColors.borderLight;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(22),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          decoration: BoxDecoration(
            color: bg,
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: border),
            boxShadow: active
                ? [
                    BoxShadow(
                      color: accent.withOpacity(0.25),
                      blurRadius: 8,
                      offset: const Offset(0, 2),
                    ),
                  ]
                : null,
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(pill.icon, style: const TextStyle(fontSize: 14)),
              const SizedBox(width: 6),
              Text(
                pill.label,
                style: TextStyle(
                  color: fg,
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  height: 1.1,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
