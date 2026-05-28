import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

class DiscoverScreen extends ConsumerWidget {
  const DiscoverScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cats = ref.watch(categoriesProvider);
    final sources = ref.watch(sourcesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('استكشف')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _gridCard('🔥 الأكثر تداولاً', '/trending', AppColors.primary),
          const SizedBox(height: 10),
          _gridCard('🕒 الموجز الصباحي', '/sabah', const Color(0xFFD97706)),
          const SizedBox(height: 10),
          _gridCard('📅 مراجعة الأسبوع', '/weekly', const Color(0xFF7C3AED)),
          const SizedBox(height: 10),
          _gridCard('📰 القصص المتطورة', '/stories', const Color(0xFF0891B2)),
          const SizedBox(height: 10),
          _gridCard('🗓 الجداول الزمنية', '/timelines', const Color(0xFF059669)),
          const SizedBox(height: 10),
          _gridCard('🗺️ خريطة الأخبار', '/map', const Color(0xFFDC2626)),
          const SizedBox(height: 10),
          _gridCard('📸 معرض الصور', '/gallery', const Color(0xFFA21CAF)),
          const SizedBox(height: 10),
          _gridCard('🎬 ريلز', '/reels', const Color(0xFFEC4899)),
          const SizedBox(height: 10),
          _gridCard('💬 تيليجرام', '/telegram', const Color(0xFF0EA5E9)),
          const SizedBox(height: 10),
          _gridCard('🐦 تويتر / X', '/twitter', const Color(0xFF1F2937)),
          const SizedBox(height: 10),
          _gridCard('🎥 يوتيوب', '/youtube', const Color(0xFFB91C1C)),
          const SizedBox(height: 10),
          _gridCard('🤖 اسأل الذكاء', '/ask', const Color(0xFF6366F1)),
          const SizedBox(height: 24),

          Text('الأقسام', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 10),
          cats.when(
            loading: () => const LoadingShimmerList(itemCount: 3),
            error: (e, _) => ErrorRetryView(
              message: '$e',
              onRetry: () => ref.invalidate(categoriesProvider),
            ),
            data: (list) => Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final c in list)
                  _StyledChip(
                    label: '${c.icon ?? ''} ${c.name}'.trim(),
                    onTap: () => context.push('/category/${c.slug}'),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 24),
          Text('المصادر', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 10),
          sources.when(
            loading: () => const LoadingShimmerList(itemCount: 3),
            error: (e, _) => ErrorRetryView(
              message: '$e',
              onRetry: () => ref.invalidate(sourcesProvider),
            ),
            data: (list) => Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final s in list)
                  _StyledChip(
                    label: s.name,
                    onTap: () => context.push('/source/${s.slug}'),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _gridCard(String label, String path, Color color) {
    return Builder(builder: (context) {
      final isDark = Theme.of(context).brightness == Brightness.dark;
      return InkWell(
        onTap: () => context.push(path),
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: NeoDecoration.soft(isDark: isDark, radius: 16),
          child: Row(
            children: [
              Container(
                width: 36, height: 36,
                decoration: BoxDecoration(
                  color: color.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                alignment: Alignment.center,
                child: Text(label.substring(0, label.indexOf(' ')),
                  style: const TextStyle(fontSize: 16)),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  label.substring(label.indexOf(' ') + 1),
                  style: TextStyle(
                    color: isDark ? Colors.white : AppColors.textLight,
                    fontSize: 15, fontWeight: FontWeight.w700),
                ),
              ),
              Container(
                width: 28, height: 28,
                decoration: BoxDecoration(
                  color: color.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(8),
                ),
                alignment: Alignment.center,
                child: Icon(Icons.chevron_left, color: color, size: 18),
              ),
            ],
          ),
        ),
      );
    });
  }
}

/// A high-contrast chip used in the category and source rails on the
/// Discover screen. Material 3's `ActionChip` was applying a near-
/// transparent surface tint on iPad, which made the chip text on light
/// backgrounds nearly invisible — see the screenshot in the
/// 2.2.1(20) review prep. This widget bypasses the M3 token system
/// and uses explicit theme-aware colors.
class _StyledChip extends StatelessWidget {
  const _StyledChip({required this.label, required this.onTap});
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Material(
      color: isDark
          ? Colors.white.withOpacity(0.08)
          : AppColors.primary.withOpacity(0.08),
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          child: Text(
            label,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              fontSize: 13,
              color: isDark ? AppColors.textDark : AppColors.textLight,
            ),
          ),
        ),
      ),
    );
  }
}
