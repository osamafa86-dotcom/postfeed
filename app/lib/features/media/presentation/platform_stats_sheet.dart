import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/loading_state.dart';
import '../data/media_repository.dart';

/// Bottom-sheet analytics dashboard for a single platform. Opened from
/// the platforms AppBar. Charts are built from plain widgets (no chart
/// dependency) so the bundle stays lean.
class PlatformStatsSheet extends ConsumerStatefulWidget {
  const PlatformStatsSheet({super.key, required this.platform, required this.accent, required this.title});
  final String platform;
  final Color accent;
  final String title;

  static Future<void> show(BuildContext context,
      {required String platform, required Color accent, required String title}) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => PlatformStatsSheet(platform: platform, accent: accent, title: title),
    );
  }

  @override
  ConsumerState<PlatformStatsSheet> createState() => _PlatformStatsSheetState();
}

class _PlatformStatsSheetState extends ConsumerState<PlatformStatsSheet> {
  String _range = '24h';

  static const _rangeLabels = {'24h': '٢٤ ساعة', '7d': '٧ أيام', '30d': '٣٠ يوم'};

  @override
  Widget build(BuildContext context) {
    final asy = ref.watch(platformStatsProvider((platform: widget.platform, range: _range)));
    return DraggableScrollableSheet(
      initialChildSize: 0.85,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, scrollCtl) => Column(
        children: [
          const SizedBox(height: 10),
          Container(width: 40, height: 4, decoration: BoxDecoration(
              color: Colors.grey.shade400, borderRadius: BorderRadius.circular(2))),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Row(children: [
              Icon(Icons.bar_chart_rounded, color: widget.accent),
              const SizedBox(width: 8),
              Expanded(child: Text('إحصاءات ${widget.title}',
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17))),
            ]),
          ),
          // Range selector.
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              children: _rangeLabels.entries.map((e) {
                final selected = e.key == _range;
                return Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    child: GestureDetector(
                      onTap: () => setState(() => _range = e.key),
                      child: Container(
                        height: 38,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: selected ? widget.accent : Theme.of(context).cardColor,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: widget.accent.withOpacity(0.4)),
                        ),
                        child: Text(e.value,
                            style: TextStyle(
                              fontWeight: FontWeight.w700,
                              fontSize: 13,
                              color: selected ? Colors.white : null,
                            )),
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: asy.when(
              loading: () => const LoadingShimmerList(),
              error: (e, _) => ErrorRetryView(
                message: '$e',
                onRetry: () => ref.invalidate(
                    platformStatsProvider((platform: widget.platform, range: _range))),
              ),
              data: (s) => _body(context, s, scrollCtl),
            ),
          ),
        ],
      ),
    );
  }

  Widget _body(BuildContext context, PlatformStats s, ScrollController ctl) {
    if (s.total == 0) {
      return const EmptyView(message: 'لا توجد بيانات كافية في هذه الفترة');
    }
    return ListView(
      controller: ctl,
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      children: [
        // KPI cards.
        Row(children: [
          _kpi('إجمالي المنشورات', '${s.total}', Icons.article_outlined),
          const SizedBox(width: 10),
          _kpi('مصادر نشطة', '${s.activeSources}', Icons.account_balance_outlined),
        ]),
        const SizedBox(height: 10),
        Row(children: [
          _kpi('نسبة المحتوى الفلسطيني', '${(s.palestineShare * 100).round()}%', Icons.flag_outlined),
          const SizedBox(width: 10),
          _kpi('ذروة النشاط', s.peak ?? '—', Icons.schedule),
        ]),
        const SizedBox(height: 20),

        // Activity timeline.
        if (s.timeline.isNotEmpty) ...[
          const _SectionTitle('النشاط عبر الوقت'),
          const SizedBox(height: 8),
          _BarChart(buckets: s.timeline, accent: widget.accent),
          const SizedBox(height: 20),
        ],

        // Top sources.
        if (s.topSources.isNotEmpty) ...[
          const _SectionTitle('أكثر المصادر نشاطاً'),
          const SizedBox(height: 8),
          ...s.topSources.map((src) => _sourceRow(src.name, src.count,
              s.topSources.first.count)),
          const SizedBox(height: 20),
        ],

        // Top topics.
        if (s.topTopics.isNotEmpty) ...[
          const _SectionTitle('أكثر المواضيع تداولاً'),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: s.topTopics
                .map((t) => Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                      decoration: BoxDecoration(
                        color: widget.accent.withOpacity(0.10),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text('#${t.tag} · ${t.count}',
                          style: TextStyle(
                              fontSize: 12.5, color: widget.accent, fontWeight: FontWeight.w600)),
                    ))
                .toList(),
          ),
        ],
      ],
    );
  }

  Widget _kpi(String label, String value, IconData icon) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Theme.of(context).cardColor,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Theme.of(context).dividerColor),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: widget.accent),
            const SizedBox(height: 8),
            Text(value, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 20)),
            const SizedBox(height: 2),
            Text(label, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontSize: 11.5)),
          ],
        ),
      ),
    );
  }

  Widget _sourceRow(String name, int count, int maxCount) {
    final frac = maxCount > 0 ? (count / maxCount).clamp(0.05, 1.0) : 0.0;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Expanded(child: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13))),
            Text('$count', style: TextStyle(fontWeight: FontWeight.w800, color: widget.accent)),
          ]),
          const SizedBox(height: 5),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: frac,
              minHeight: 7,
              backgroundColor: widget.accent.withOpacity(0.10),
              valueColor: AlwaysStoppedAnimation(widget.accent),
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);
  final String text;
  @override
  Widget build(BuildContext context) =>
      Text(text, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15));
}

/// Simple vertical bar chart from plain widgets — no chart package.
class _BarChart extends StatelessWidget {
  const _BarChart({required this.buckets, required this.accent});
  final List<({String label, int count})> buckets;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final maxCount = buckets.fold<int>(1, (m, b) => b.count > m ? b.count : m);
    // For dense ranges (30d) only label every Nth bucket to avoid clutter.
    final step = (buckets.length / 8).ceil().clamp(1, 999);
    return SizedBox(
      height: 130,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: List.generate(buckets.length, (i) {
          final b = buckets[i];
          final frac = (b.count / maxCount).clamp(0.0, 1.0);
          return Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 1.5),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  Container(
                    height: (88 * frac) + (b.count > 0 ? 3 : 0),
                    decoration: BoxDecoration(
                      color: accent.withOpacity(b.count > 0 ? 0.85 : 0.15),
                      borderRadius: const BorderRadius.vertical(top: Radius.circular(3)),
                    ),
                  ),
                  const SizedBox(height: 4),
                  SizedBox(
                    height: 22,
                    child: i % step == 0
                        ? Text(b.label,
                            style: const TextStyle(fontSize: 8),
                            textAlign: TextAlign.center,
                            maxLines: 1,
                            overflow: TextOverflow.clip)
                        : const SizedBox.shrink(),
                  ),
                ],
              ),
            ),
          );
        }),
      ),
    );
  }
}
