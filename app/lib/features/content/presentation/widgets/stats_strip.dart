import 'package:flutter/material.dart';

import '../../../../core/models/home_payload.dart';
import '../../../../core/theme/app_theme.dart';

/// Mirrors the website's `<div class="stats-strip">`:
///   📰 14,730 خبر · 🌐 16 مصدر نشط · 👁 3.2M مشاهدة اليوم ·
///   🔥 سياسة الأكثر تداولاً · ⏱ منذ 2 دق آخر تحديث
///
/// Each chip has its own pastel background tint to match the site's
/// `.stat-chip-blue|teal|purple|orange|red` classes. Horizontally scrollable
/// in case the device is narrow.
class StatsStrip extends StatelessWidget {
  const StatsStrip({super.key, required this.stats});
  final HomeStats stats;

  @override
  Widget build(BuildContext context) {
    final formatted = _formatCount(stats.totalArticles);
    final views = stats.totalViewsToday != null ? _formatCount(stats.totalViewsToday!) : '3.2M';
    final topCat = stats.topCategory?.name ?? 'سياسة';
    final topIcon = stats.topCategory?.icon;

    return Container(
      color: AppColors.surfaceLight2,
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsetsDirectional.only(start: 12, end: 12),
        child: Row(
          children: [
            _StatChip(
              icon: '📰',
              value: formatted,
              label: 'خبر',
              bg: AppColors.chipBlueBg,
              fg: AppColors.accent,
            ),
            const SizedBox(width: 8),
            _StatChip(
              icon: '🌐',
              value: '${stats.totalSources}',
              label: 'مصدر نشط',
              bg: AppColors.chipTealBg,
              fg: AppColors.accent2,
            ),
            const SizedBox(width: 8),
            _StatChip(
              icon: '👁',
              value: views,
              label: 'مشاهدة اليوم',
              bg: AppColors.chipPurpleBg,
              fg: AppColors.purple,
            ),
            const SizedBox(width: 8),
            _StatChip(
              icon: '🔥',
              value: '${topIcon ?? ''} $topCat'.trim(),
              label: 'الأكثر تداولاً',
              bg: AppColors.chipOrangeBg,
              fg: AppColors.gold,
            ),
            const SizedBox(width: 8),
            _StatChip(
              icon: '⏱',
              value: _timeAgo(stats.lastUpdatedAt),
              label: 'آخر تحديث',
              bg: AppColors.chipRedBg,
              fg: AppColors.breaking,
            ),
          ],
        ),
      ),
    );
  }

  static String _formatCount(int n) {
    if (n >= 1000000) {
      final v = n / 1000000.0;
      return '${v.toStringAsFixed(v >= 10 ? 0 : 1)}M';
    }
    if (n >= 1000) {
      final s = n.toString();
      final buf = StringBuffer();
      for (var i = 0; i < s.length; i++) {
        if (i > 0 && (s.length - i) % 3 == 0) buf.write(',');
        buf.write(s[i]);
      }
      return buf.toString();
    }
    return n.toString();
  }

  static String _timeAgo(DateTime? at) {
    if (at == null) return '—';
    final diff = DateTime.now().difference(at);
    if (diff.inSeconds < 60) return 'الآن';
    if (diff.inMinutes < 60) return 'منذ ${diff.inMinutes} دق';
    if (diff.inHours < 24) return 'منذ ${diff.inHours} س';
    return 'منذ ${diff.inDays} يوم';
  }
}

class _StatChip extends StatelessWidget {
  const _StatChip({
    required this.icon,
    required this.value,
    required this.label,
    required this.bg,
    required this.fg,
  });
  final String icon;
  final String value;
  final String label;
  final Color bg;
  final Color fg;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: fg.withOpacity(0.18)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(icon, style: const TextStyle(fontSize: 16)),
          const SizedBox(width: 6),
          Text(
            value,
            style: TextStyle(
              color: fg,
              fontSize: 13,
              fontWeight: FontWeight.w900,
              height: 1.1,
            ),
          ),
          const SizedBox(width: 4),
          Text(
            label,
            style: TextStyle(
              color: fg.withOpacity(0.85),
              fontSize: 11,
              fontWeight: FontWeight.w600,
              height: 1.1,
            ),
          ),
        ],
      ),
    );
  }
}
