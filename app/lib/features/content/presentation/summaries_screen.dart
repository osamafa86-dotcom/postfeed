import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart' show Share;

import '../../../core/api/api_client.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';

// ── Data model for rich summaries ──

class SummaryData {
  const SummaryData({
    required this.summary,
    this.headline,
    this.sections = const [],
    this.topics = const [],
    this.generatedAt,
    this.title,
    this.date,
  });
  final String summary;
  final String? headline;
  final List<Map<String, dynamic>> sections;
  final List<String> topics;
  final String? generatedAt;
  final String? title;
  final String? date;

  /// Combine all content into shareable text
  String toFullText(String cardTitle) {
    final buf = StringBuffer();
    buf.writeln('═══ $cardTitle ═══');
    buf.writeln();
    if (headline != null && headline!.isNotEmpty) {
      buf.writeln('📌 $headline');
      buf.writeln();
    }
    if (summary.isNotEmpty) {
      buf.writeln(summary);
      buf.writeln();
    }
    for (final sec in sections) {
      final secTitle = sec['title'] as String? ?? '';
      final secBody = sec['body'] as String? ?? sec['content'] as String? ?? '';
      final items = sec['items'] as List? ?? [];
      if (secTitle.isNotEmpty) buf.writeln('── $secTitle ──');
      if (secBody.isNotEmpty) buf.writeln(secBody);
      for (final item in items) {
        buf.writeln('• $item');
      }
      buf.writeln();
    }
    if (topics.isNotEmpty) {
      buf.writeln('الموضوعات: ${topics.join(' | ')}');
    }
    if (generatedAt != null) {
      buf.writeln('\n⏱ $generatedAt');
    }
    buf.writeln('\nفيد نيوز — feedsnews.net');
    return buf.toString();
  }
}

// ── Providers ──

final _telegramSummaryProvider = FutureProvider<SummaryData>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/media/social-summary',
      query: {'platform': 'telegram'},
      decode: (d) => (d as Map).cast<String, dynamic>());
  final data = res.data ?? {};
  return SummaryData(
    summary: data['summary']?.toString() ?? '',
    headline: data['headline']?.toString(),
    sections: (data['sections'] as List? ?? [])
        .whereType<Map>().map((m) => m.cast<String, dynamic>()).toList(),
    topics: (data['topics'] as List? ?? []).map((t) => t.toString()).toList(),
    generatedAt: data['generated_at']?.toString(),
  );
});

final _twitterSummaryProvider = FutureProvider<SummaryData>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/media/social-summary',
      query: {'platform': 'twitter'},
      decode: (d) => (d as Map).cast<String, dynamic>());
  final data = res.data ?? {};
  return SummaryData(
    summary: data['summary']?.toString() ?? '',
  );
});

final _dailySummaryProvider = FutureProvider<SummaryData>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/daily-summary',
      decode: (d) => (d as Map).cast<String, dynamic>());
  final data = res.data ?? {};
  return SummaryData(
    summary: data['summary']?.toString() ?? '',
    title: data['title']?.toString(),
    date: data['date']?.toString(),
  );
});

final _weeklySummaryProvider = FutureProvider<SummaryData>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/weekly-rewind',
      decode: (d) => (d as Map).cast<String, dynamic>());
  final data = res.data ?? {};
  return SummaryData(
    summary: data['summary']?.toString() ?? '',
    title: data['title']?.toString(),
    sections: (data['sections'] as List? ?? [])
        .whereType<Map>().map((m) => m.cast<String, dynamic>()).toList(),
  );
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

            // ── Telegram Summary (rich) ──
            _RichSummaryCard(
              title: 'ملخص أخبار تلغرام',
              subtitle: 'آخر ما نُشر في قنوات تلغرام',
              icon: Icons.send_rounded,
              gradient: const [Color(0xFF0EA5E9), Color(0xFF0284C7)],
              provider: _telegramSummaryProvider,
              isDark: isDark,
              ref: ref,
            ),

            const SizedBox(height: 12),

            // ── Daily Summary ──
            _RichSummaryCard(
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
            _RichSummaryCard(
              title: 'ملخص الأسبوع',
              subtitle: 'مراجعة أسبوعية شاملة',
              icon: Icons.date_range,
              gradient: const [Color(0xFF6366F1), Color(0xFF4338CA)],
              provider: _weeklySummaryProvider,
              isDark: isDark,
              ref: ref,
            ),

            const SizedBox(height: 12),

            // ── Twitter Summary ──
            _RichSummaryCard(
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

// ═══════════════════════════════════════════════════════════════
// RICH SUMMARY CARD — expandable with sections + share/copy
// ═══════════════════════════════════════════════════════════════

class _RichSummaryCard extends StatefulWidget {
  const _RichSummaryCard({
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
  final FutureProvider<SummaryData> provider;
  final bool isDark;
  final WidgetRef ref;

  @override
  State<_RichSummaryCard> createState() => _RichSummaryCardState();
}

class _RichSummaryCardState extends State<_RichSummaryCard> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final asy = widget.ref.watch(widget.provider);

    return Container(
      decoration: BoxDecoration(
        color: widget.isDark ? Colors.white.withOpacity(0.04) : Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: widget.isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
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
                    gradient: LinearGradient(colors: widget.gradient),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  alignment: Alignment.center,
                  child: Icon(widget.icon, color: Colors.white, size: 18),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(widget.title,
                        style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800,
                          color: widget.isDark ? Colors.white : AppColors.textLight)),
                      Text(widget.subtitle,
                        style: TextStyle(fontSize: 11,
                          color: widget.isDark ? Colors.white38 : AppColors.textMutedLight)),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: widget.gradient[0].withOpacity(0.1),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(Icons.auto_awesome, size: 10, color: widget.gradient[0]),
                    const SizedBox(width: 3),
                    Text('AI', style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: widget.gradient[0])),
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
                    child: CircularProgressIndicator(strokeWidth: 2, color: widget.gradient[0])),
                  const SizedBox(width: 10),
                  Text('جارٍ التلخيص...',
                    style: TextStyle(fontSize: 13, color: widget.isDark ? Colors.white38 : AppColors.textMutedLight)),
                ],
              ),
              error: (e, __) => Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(Icons.error_outline, size: 16,
                        color: widget.isDark ? Colors.white30 : AppColors.textMutedLight),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text('تعذّر تحميل الملخص',
                          style: TextStyle(fontSize: 13,
                            color: widget.isDark ? Colors.white38 : AppColors.textMutedLight)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    height: 30,
                    child: TextButton.icon(
                      onPressed: () => widget.ref.invalidate(widget.provider),
                      icon: Icon(Icons.refresh, size: 14, color: widget.gradient[0]),
                      label: Text('إعادة المحاولة',
                        style: TextStyle(fontSize: 12, color: widget.gradient[0])),
                      style: TextButton.styleFrom(
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        minimumSize: Size.zero,
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
                    ),
                  ),
                ],
              ),
              data: (data) {
                if (data.summary.isEmpty && data.sections.isEmpty) {
                  return Text('لا يوجد ملخص متاح حالياً',
                    style: TextStyle(fontSize: 13,
                      color: widget.isDark ? Colors.white30 : AppColors.textMutedLight));
                }
                return _SummaryContent(
                  data: data,
                  cardTitle: widget.title,
                  isDark: widget.isDark,
                  gradient: widget.gradient,
                  expanded: _expanded,
                  onToggle: () => setState(() => _expanded = !_expanded),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// SUMMARY CONTENT — sections, topics, share/copy/expand
// ═══════════════════════════════════════════════════════════════

class _SummaryContent extends StatelessWidget {
  const _SummaryContent({
    required this.data,
    required this.cardTitle,
    required this.isDark,
    required this.gradient,
    required this.expanded,
    required this.onToggle,
  });
  final SummaryData data;
  final String cardTitle;
  final bool isDark;
  final List<Color> gradient;
  final bool expanded;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final hasSections = data.sections.isNotEmpty;
    final hasTopics = data.topics.isNotEmpty;
    final hasExtra = hasSections || hasTopics;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Headline
        if (data.headline != null && data.headline!.isNotEmpty) ...[
          Text(data.headline!,
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, height: 1.6,
              color: isDark ? Colors.white : AppColors.textLight)),
          const SizedBox(height: 10),
        ],

        // Summary text
        Text(data.summary,
          style: TextStyle(fontSize: 14, height: 1.8,
            color: isDark ? Colors.white70 : AppColors.textLight),
          maxLines: expanded ? null : 6,
          overflow: expanded ? null : TextOverflow.ellipsis,
        ),

        // Sections (shown when expanded)
        if (expanded && hasSections) ...[
          const SizedBox(height: 16),
          ...data.sections.map((sec) => _SectionTile(section: sec, isDark: isDark, accent: gradient[0])),
        ],

        // Topics chips
        if (expanded && hasTopics) ...[
          const SizedBox(height: 12),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: data.topics.map((t) => Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
              decoration: BoxDecoration(
                color: gradient[0].withOpacity(0.08),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Text('#$t', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600,
                color: gradient[0])),
            )).toList(),
          ),
        ],

        // Generated at timestamp
        if (expanded && data.generatedAt != null) ...[
          const SizedBox(height: 10),
          Row(
            children: [
              Icon(Icons.schedule, size: 12,
                color: isDark ? Colors.white24 : AppColors.textMutedLight),
              const SizedBox(width: 4),
              Text(data.generatedAt!,
                style: TextStyle(fontSize: 10,
                  color: isDark ? Colors.white24 : AppColors.textMutedLight)),
            ],
          ),
        ],

        const SizedBox(height: 12),

        // Action row: expand/collapse + share + copy
        Row(
          children: [
            if (hasExtra || data.summary.length > 200)
              GestureDetector(
                onTap: onToggle,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(
                    color: gradient[0].withOpacity(0.08),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(expanded ? Icons.expand_less : Icons.expand_more, size: 16, color: gradient[0]),
                    const SizedBox(width: 4),
                    Text(expanded ? 'عرض أقل' : 'عرض الكل',
                      style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: gradient[0])),
                  ]),
                ),
              ),
            const Spacer(),
            // Copy
            _MiniAction(
              icon: Icons.copy_rounded,
              color: isDark ? Colors.white38 : AppColors.textMutedLight,
              onTap: () {
                Clipboard.setData(ClipboardData(text: data.toFullText(cardTitle)));
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('تم النسخ'), duration: Duration(seconds: 1)));
              },
            ),
            const SizedBox(width: 8),
            // Share
            _MiniAction(
              icon: Icons.ios_share_rounded,
              color: isDark ? Colors.white38 : AppColors.textMutedLight,
              onTap: () => Share.share(data.toFullText(cardTitle)),
            ),
          ],
        ),
      ],
    );
  }
}

class _SectionTile extends StatelessWidget {
  const _SectionTile({required this.section, required this.isDark, required this.accent});
  final Map<String, dynamic> section;
  final bool isDark;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final title = section['title'] as String? ?? '';
    final body = section['body'] as String? ?? section['content'] as String? ?? '';
    final items = (section['items'] as List? ?? []).map((e) => e.toString()).toList();

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isDark ? Colors.white.withOpacity(0.03) : accent.withOpacity(0.04),
        borderRadius: BorderRadius.circular(12),
        border: Border(right: BorderSide(color: accent, width: 3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (title.isNotEmpty)
            Text(title, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800,
              color: isDark ? Colors.white : AppColors.textLight)),
          if (body.isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(body, style: TextStyle(fontSize: 13, height: 1.7,
              color: isDark ? Colors.white60 : AppColors.textLight)),
          ],
          if (items.isNotEmpty) ...[
            const SizedBox(height: 6),
            ...items.map((item) => Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Container(width: 5, height: 5,
                      decoration: BoxDecoration(color: accent, shape: BoxShape.circle)),
                  ),
                  const SizedBox(width: 8),
                  Expanded(child: Text(item,
                    style: TextStyle(fontSize: 13, height: 1.6,
                      color: isDark ? Colors.white60 : AppColors.textLight))),
                ],
              ),
            )),
          ],
        ],
      ),
    );
  }
}

class _MiniAction extends StatelessWidget {
  const _MiniAction({required this.icon, required this.color, required this.onTap});
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Padding(
        padding: const EdgeInsets.all(6),
        child: Icon(icon, size: 18, color: color),
      ),
    );
  }
}
