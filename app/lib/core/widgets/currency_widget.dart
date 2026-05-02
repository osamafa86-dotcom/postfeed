import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../theme/app_theme.dart';

class _CurrencyRate {
  const _CurrencyRate(this.code, this.name, this.flag, this.rate);
  final String code;
  final String name;
  final String flag;
  final double rate;
}

final _currencyProvider = FutureProvider<List<_CurrencyRate>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.raw.get('https://feedsnews.net/api/currency.php');
  final data = res.data as Map<String, dynamic>;
  final rates = (data['rates'] as Map?)?.cast<String, dynamic>() ?? {};

  const meta = {
    'ILS': ('شيكل', '🇮🇱'),
    'JOD': ('دينار أردني', '🇯🇴'),
    'EUR': ('يورو', '🇪🇺'),
    'GBP': ('جنيه إسترليني', '🇬🇧'),
    'SAR': ('ريال سعودي', '🇸🇦'),
    'EGP': ('جنيه مصري', '🇪🇬'),
    'TRY': ('ليرة تركية', '🇹🇷'),
    'AED': ('درهم إماراتي', '🇦🇪'),
    'KWD': ('دينار كويتي', '🇰🇼'),
  };

  return rates.entries
      .where((e) => meta.containsKey(e.key))
      .map((e) {
        final m = meta[e.key]!;
        return _CurrencyRate(e.key, m.$1, m.$2, (e.value as num).toDouble());
      })
      .toList();
});

class CurrencyWidget extends ConsumerWidget {
  const CurrencyWidget({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_currencyProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return asy.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (rates) {
        if (rates.isEmpty) return const SizedBox.shrink();
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Header
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 10),
              child: Row(children: [
                const Text('💱', style: TextStyle(fontSize: 18)),
                const SizedBox(width: 8),
                Text('أسعار العملات',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                    color: isDark ? Colors.white : AppColors.textLight)),
                const Spacer(),
                Text('مقابل الدولار',
                  style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600,
                    color: isDark ? Colors.white38 : AppColors.textMutedLight)),
              ]),
            ),

            // Scrollable ticker
            SizedBox(
              height: 80,
              child: ListView.builder(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                itemCount: rates.length,
                itemBuilder: (_, i) {
                  final r = rates[i];
                  return Container(
                    width: 120,
                    margin: const EdgeInsets.symmetric(horizontal: 4),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0),
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Row(children: [
                          Text(r.flag, style: const TextStyle(fontSize: 16)),
                          const SizedBox(width: 6),
                          Text(r.code,
                            style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800,
                              color: isDark ? Colors.white : AppColors.textLight)),
                        ]),
                        const SizedBox(height: 6),
                        Text(r.rate.toStringAsFixed(r.rate < 1 ? 4 : 2),
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w900,
                            color: const Color(0xFF38BDF8))),
                        Text(r.name,
                          style: TextStyle(fontSize: 9,
                            color: isDark ? Colors.white38 : AppColors.textMutedLight),
                          overflow: TextOverflow.ellipsis),
                      ],
                    ),
                  );
                },
              ),
            ),
          ],
        );
      },
    );
  }
}
