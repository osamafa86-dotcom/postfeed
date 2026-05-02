import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';

// ── Providers ──

final _telegramSummaryProvider = FutureProvider<String>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/media/social-summary',
      query: {'platform': 'telegram'},
      decode: (d) => (d as Map).cast<String, dynamic>());
  return res.data?['summary']?.toString() ?? '';
});

final _twitterSummaryProvider = FutureProvider<String>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/media/social-summary',
      query: {'platform': 'twitter'},
      decode: (d) => (d as Map).cast<String, dynamic>());
  return res.data?['summary']?.toString() ?? '';
});

final _dailySummaryProvider = FutureProvider<String>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/daily-summary',
      decode: (d) => (d as Map).cast<String, dynamic>());
  return res.data?['summary']?.toString() ?? '';
});

final _weeklySummaryProvider = FutureProvider<String>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/weekly-rewind',
      decode: (d) => (d as Map).cast<String, dynamic>());
  return res.data?['summary']?.toString() ?? '';
});

// ── Screen ──

class SummariesScreen extends ConsumerWidget {
  const SummariesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: AppBar(
        title: const Text('الملخصات'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () {
              ref.invalidate(_telegramSummaryProvider);
              ref.invalidate(_twitterSummaryProvider);
              ref.invalidate(_dailySummaryProvider);
              ref.invalidate(_weeklySummaryProvider);
            },
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(_telegramSummaryProvider);
          ref.invalidate(_twitterSummaryProvider);
          ref.invalidate(_dailySummaryProvider);
          ref.invalidate(_weeklySummaryProvider);
        },
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // Header
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: isDark
                      ? [const Color(0xFF1E1B4B), const Color(0xFF312E81)]
                      : [const Color(0xFF6366F1), const Color(0xFF4338CA)],
                ),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Row(
                children: [
                  Container(
                    width: 44, height: 44,
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(0.2),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    alignment: Alignment.center,
                    child: const Icon(Icons.auto_awesome, color: Colors.white, size: 22),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('ملخصات الذكاء الاصطناعي',
                          style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w900)),
                        const SizedBox(height: 4),
                        Text('كل ما يحصل، بجملة واحدة',
                          style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 12)),
                      ],
                    ),
                  ),
                ],
              ),
            ),

            const SizedBox(height: 20),

            // ── Daily Summary ──
            _SummaryCard(
              title: 'ملخص أخبار اليوم',
              subtitle: 'أهم الأخبار في الموقع اليوم',
              icon: Icons.today,
              gradient: const [Color(0xFFF59E0B), Color(0xFFB45309)],
              provider: _dailySummaryProvider,
              isDark: isDark,
              ref: ref,
            ),

            const SizedBox(height: 12),

            // ── Weekly Summary ──
            _SummaryCard(
              title: 'ملخص الأسبوع',
              subtitle: 'مراجعة أسبوعية شاملة',
              icon: Icons.date_range,
              gradient: const [Color(0xFF6366F1), Color(0xFF4338CA)],
              provider: _weeklySummaryProvider,
              isDark: isDark,
              ref: ref,
            ),

            const SizedBox(height: 12),

            // ── Telegram Summary ──
            _SummaryCard(
              title: 'ملخص أخبار تلغرام',
              subtitle: 'آخر ما نُشر في قنوات تلغرام',
              icon: Icons.send_rounded,
              gradient: const [Color(0xFF0EA5E9), Color(0xFF0284C7)],
              provider: _telegramSummaryProvider,
              isDark: isDark,
              ref: ref,
            ),

            const SizedBox(height: 12),

            // ── Twitter Summary ──
            _SummaryCard(
              title: 'ملخص تويتر',
              subtitle: 'أبرز ما تداوله الناس على X',
              icon: Icons.tag,
              gradient: const [Color(0xFF374151), Color(0xFF111827)],
              provider: _twitterSummaryProvider,
              isDark: isDark,
              ref: ref,
            ),

            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.gradient,
    required this.provider,
    required this.isDark,
    required this.ref,
  });
  final String title, subtitle;
  final IconData icon;
  final List<Color> gradient;
  final FutureProvider<String> provider;
  final bool isDark;
  final WidgetRef ref;

  @override
  Widget build(BuildContext context) {
    final asy = ref.watch(provider);

    return Container(
      decoration: BoxDecoration(
        color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
        boxShadow: [
          BoxShadow(color: Colors.black.withOpacity(0.03), blurRadius: 8, offset: const Offset(0, 2)),
        ],
      ),
      child: Column(
        children: [
          // Header
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 0),
            child: Row(
              children: [
                Container(
                  width: 36, height: 36,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(colors: gradient),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  alignment: Alignment.center,
                  child: Icon(icon, color: Colors.white, size: 18),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title,
                        style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800,
                          color: isDark ? Colors.white : AppColors.textLight)),
                      Text(subtitle,
                        style: TextStyle(fontSize: 11,
                          color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: gradient[0].withOpacity(0.1),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(Icons.auto_awesome, size: 10, color: gradient[0]),
                    const SizedBox(width: 3),
                    Text('AI', style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: gradient[0])),
                  ]),
                ),
              ],
            ),
          ),

          // Content
          Padding(
            padding: const EdgeInsets.all(16),
            child: asy.when(
              loading: () => Row(
                children: [
                  SizedBox(width: 16, height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2, color: gradient[0])),
                  const SizedBox(width: 10),
                  Text('جارٍ التلخيص...',
                    style: TextStyle(fontSize: 13, color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                ],
              ),
              error: (_, __) => Row(
                children: [
                  Icon(Icons.error_outline, size: 16, color: isDark ? Colors.white30 : AppColors.textMutedLight),
                  const SizedBox(width: 8),
                  Text('تعذّر تحميل الملخص',
                    style: TextStyle(fontSize: 13, color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                ],
              ),
              data: (summary) {
                if (summary.isEmpty) {
                  return Text('لا يوجد ملخص متاح حالياً',
                    style: TextStyle(fontSize: 13, color: isDark ? Colors.white30 : AppColors.textMutedLight));
                }
                return Text(summary,
                  style: TextStyle(fontSize: 14, height: 1.8,
                    color: isDark ? Colors.white70 : AppColors.textLight));
              },
            ),
          ),
        ],
      ),
    );
  }
}
