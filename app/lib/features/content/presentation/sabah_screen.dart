import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart' as intl;
import 'package:pdf/pdf.dart' as pw_color;
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';

final _sabahProvider = FutureProvider<Map<String, dynamic>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/sabah',
      decode: (d) => (d as Map).cast<String, dynamic>());
  return res.data!;
});

class SabahScreen extends ConsumerWidget {
  const SabahScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_sabahProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('صباح فيد نيوز'),
        actions: [
          asy.maybeWhen(
            data: (d) => IconButton(
              tooltip: 'تصدير PDF',
              icon: const Icon(Icons.picture_as_pdf_outlined),
              onPressed: () => _exportPdf(context, d),
            ),
            orElse: () => const SizedBox.shrink(),
          ),
        ],
      ),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => (e is ApiException && e.status == 404)
            ? const EmptyView(
                icon: Icons.wb_sunny_outlined,
                message: 'لا يوجد موجز صباحي اليوم',
                hint: 'يصدر الموجز الصباحي يومياً — تابعنا غداً.',
              )
            : ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(_sabahProvider)),
        data: (d) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(_sabahProvider),
          child: _BriefingView(data: d),
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// MAIN VIEW
// ═══════════════════════════════════════════════════════════════

class _BriefingView extends StatelessWidget {
  const _BriefingView({required this.data});
  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final title = (data['title'] ?? 'صباح فيد نيوز').toString();
    final subtitle = (data['subtitle'] ?? '').toString();
    final summary = (data['summary'] ?? '').toString();
    final sections = (data['sections'] as List? ?? []);
    final keyNumbers = (data['key_numbers'] as List? ?? []);
    final regions = (data['regions'] as List? ?? []);
    final quote = data['quote_of_day'] as Map?;
    final closing = (data['closing_question'] ?? '').toString();
    final articleCount = (data['article_count'] as num?)?.toInt() ?? 0;
    final dateStr = _formatDate(data['date']?.toString());

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsets.zero,
      children: [
        // ── 1. Hero / Header ──
        _HeroHeader(
          title: title,
          subtitle: subtitle,
          dateStr: dateStr,
          articleCount: articleCount,
          regions: regions.cast<dynamic>().map((r) => r.toString()).toList(),
        ),
        // ── 2. Editorial intro (hook) ──
        if (summary.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 4),
            child: Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: AppColors.primary.withOpacity(0.06),
                borderRadius: BorderRadius.circular(16),
                border: Border(
                  right: BorderSide(color: AppColors.primary, width: 4),
                ),
              ),
              child: Text(
                summary,
                textAlign: TextAlign.justify,
                style: TextStyle(
                  fontSize: 16,
                  height: 1.85,
                  fontWeight: FontWeight.w500,
                  color: isDark ? Colors.white.withOpacity(0.9) : AppColors.textLight,
                ),
              ),
            ),
          ),
        // ── 3. Key numbers ──
        if (keyNumbers.isNotEmpty)
          _KeyNumbersStrip(items: keyNumbers.cast<dynamic>().toList()),
        // ── 4. Sections ──
        ...sections.cast<dynamic>().where((s) => s is Map).map(
              (s) => _SectionCard(section: (s as Map).cast<String, dynamic>()),
            ),
        // ── 5. Quote of the day ──
        if (quote != null) _QuoteCard(quote: quote.cast<String, dynamic>()),
        // ── 6. Closing question ──
        if (closing.isNotEmpty) _ClosingQuestion(text: closing),
        const SizedBox(height: 32),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// HERO HEADER — gradient + title + meta
// ═══════════════════════════════════════════════════════════════

class _HeroHeader extends StatelessWidget {
  const _HeroHeader({
    required this.title,
    required this.subtitle,
    required this.dateStr,
    required this.articleCount,
    required this.regions,
  });
  final String title;
  final String subtitle;
  final String dateStr;
  final int articleCount;
  final List<String> regions;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 28, 20, 24),
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
          colors: [
            Color(0xFFD97706), // sunrise amber
            Color(0xFFEA580C),
            Color(0xFFB91C1C),
          ],
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.wb_sunny_rounded, color: Colors.white, size: 22),
              const SizedBox(width: 8),
              Text(
                'موجز الصباح',
                style: TextStyle(
                  color: Colors.white.withOpacity(0.95),
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 0.5,
                ),
              ),
              const Spacer(),
              if (dateStr.isNotEmpty)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.18),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    dateStr,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 18),
          Text(
            title,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 26,
              fontWeight: FontWeight.w900,
              height: 1.45,
            ),
          ),
          if (subtitle.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(
              subtitle,
              style: TextStyle(
                color: Colors.white.withOpacity(0.92),
                fontSize: 14.5,
                fontWeight: FontWeight.w500,
                height: 1.6,
              ),
            ),
          ],
          const SizedBox(height: 16),
          Row(
            children: [
              Icon(Icons.article_outlined, color: Colors.white.withOpacity(0.85), size: 14),
              const SizedBox(width: 4),
              Text(
                'من $articleCount خبراً',
                style: TextStyle(
                  color: Colors.white.withOpacity(0.85),
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                ),
              ),
              if (regions.isNotEmpty) ...[
                const SizedBox(width: 12),
                Icon(Icons.place_outlined, color: Colors.white.withOpacity(0.85), size: 14),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    regions.join(' • '),
                    style: TextStyle(
                      color: Colors.white.withOpacity(0.85),
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// KEY NUMBERS — horizontal scrollable stat cards
// ═══════════════════════════════════════════════════════════════

class _KeyNumbersStrip extends StatelessWidget {
  const _KeyNumbersStrip({required this.items});
  final List<dynamic> items;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Padding(
      padding: const EdgeInsets.fromLTRB(0, 20, 0, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Row(
              children: [
                Container(
                  width: 30, height: 30,
                  decoration: BoxDecoration(
                    color: AppColors.primary,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  alignment: Alignment.center,
                  child: const Icon(Icons.tag, color: Colors.white, size: 16),
                ),
                const SizedBox(width: 10),
                Text(
                  'أرقام اليوم',
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: isDark ? Colors.white : AppColors.textLight,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          SizedBox(
            height: 110,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              itemCount: items.length,
              separatorBuilder: (_, __) => const SizedBox(width: 10),
              itemBuilder: (_, i) {
                final n = (items[i] as Map).cast<String, dynamic>();
                return Container(
                  width: 190,
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: isDark ? Colors.white.withOpacity(0.05) : Colors.white,
                    borderRadius: BorderRadius.circular(14),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(isDark ? 0.3 : 0.06),
                        blurRadius: 10,
                        offset: const Offset(0, 3),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        (n['value'] ?? '').toString(),
                        style: TextStyle(
                          fontSize: 22,
                          fontWeight: FontWeight.w900,
                          color: AppColors.primary,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 8),
                      Expanded(
                        child: Text(
                          (n['context'] ?? '').toString(),
                          style: TextStyle(
                            fontSize: 12.5,
                            height: 1.5,
                            color: isDark ? Colors.white.withOpacity(0.7) : AppColors.textMutedLight,
                          ),
                          maxLines: 4,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
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
// SECTION CARD — full editorial section
// ═══════════════════════════════════════════════════════════════

class _SectionCard extends StatelessWidget {
  const _SectionCard({required this.section});
  final Map<String, dynamic> section;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final title = (section['title'] ?? '').toString();
    final icon = (section['icon'] ?? '').toString();
    final body = (section['body'] ?? '').toString();
    final whyMatters = (section['why_matters'] ?? '').toString();
    final tags = (section['tags'] as List? ?? []).cast<dynamic>();

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 16, 16, 0),
      padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
      decoration: BoxDecoration(
        color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(isDark ? 0.3 : 0.05),
            blurRadius: 12,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Title row
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (icon.isNotEmpty) ...[
                Text(icon, style: const TextStyle(fontSize: 28)),
                const SizedBox(width: 10),
              ],
              Expanded(
                child: Text(
                  title,
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w900,
                    height: 1.4,
                    color: isDark ? Colors.white : AppColors.textLight,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          // Body
          Text(
            body,
            textAlign: TextAlign.justify,
            style: TextStyle(
              fontSize: 15,
              height: 1.9,
              color: isDark ? Colors.white.withOpacity(0.85) : AppColors.textLight,
            ),
          ),
          // Why it matters
          if (whyMatters.isNotEmpty) ...[
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.primary.withOpacity(0.08),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.lightbulb_outline, color: AppColors.primary, size: 18),
                  const SizedBox(width: 8),
                  Expanded(
                    child: RichText(
                      text: TextSpan(
                        style: TextStyle(
                          fontSize: 13.5,
                          height: 1.6,
                          color: isDark ? Colors.white.withOpacity(0.85) : AppColors.textLight,
                        ),
                        children: [
                          TextSpan(
                            text: 'لماذا يهمّك؟  ',
                            style: TextStyle(
                              fontWeight: FontWeight.w800,
                              color: AppColors.primary,
                            ),
                          ),
                          TextSpan(text: whyMatters),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
          // Tags
          if (tags.isNotEmpty) ...[
            const SizedBox(height: 12),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: tags
                  .map((t) => Container(
                        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                        decoration: BoxDecoration(
                          color: isDark
                              ? Colors.white.withOpacity(0.08)
                              : AppColors.primary.withOpacity(0.08),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          '#${t.toString()}',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: AppColors.primary,
                          ),
                        ),
                      ))
                  .toList(),
            ),
          ],
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// QUOTE OF THE DAY
// ═══════════════════════════════════════════════════════════════

class _QuoteCard extends StatelessWidget {
  const _QuoteCard({required this.quote});
  final Map<String, dynamic> quote;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final text = (quote['text'] ?? '').toString();
    final speaker = (quote['speaker'] ?? '').toString();
    final context2 = (quote['context'] ?? '').toString();
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 18, 16, 0),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: isDark
              ? [const Color(0xFF1E293B), const Color(0xFF0F172A)]
              : [const Color(0xFFF8FAFC), const Color(0xFFE2E8F0)],
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.format_quote, color: AppColors.primary, size: 32),
              const SizedBox(width: 6),
              Text(
                'اقتباس اليوم',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  color: AppColors.primary,
                  letterSpacing: 0.5,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            '"$text"',
            style: TextStyle(
              fontSize: 16.5,
              height: 1.85,
              fontStyle: FontStyle.italic,
              fontWeight: FontWeight.w500,
              color: isDark ? Colors.white.withOpacity(0.92) : AppColors.textLight,
            ),
          ),
          const SizedBox(height: 14),
          Text(
            '— $speaker',
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w800,
              color: isDark ? Colors.white.withOpacity(0.75) : AppColors.textLight,
            ),
          ),
          if (context2.isNotEmpty) ...[
            const SizedBox(height: 3),
            Text(
              context2,
              style: TextStyle(
                fontSize: 11.5,
                color: isDark ? Colors.white.withOpacity(0.55) : AppColors.textMutedLight,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// CLOSING QUESTION
// ═══════════════════════════════════════════════════════════════

class _ClosingQuestion extends StatelessWidget {
  const _ClosingQuestion({required this.text});
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 24, 16, 0),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: AppColors.primary.withOpacity(0.08),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.primary.withOpacity(0.2)),
      ),
      child: Column(
        children: [
          Icon(Icons.psychology_outlined, color: AppColors.primary, size: 28),
          const SizedBox(height: 10),
          Text(
            text,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w700,
              fontStyle: FontStyle.italic,
              height: 1.7,
              color: AppColors.primary,
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════

String _formatDate(String? raw) {
  if (raw == null || raw.isEmpty) return '';
  final d = DateTime.tryParse(raw);
  if (d == null) return raw;
  return intl.DateFormat('EEEE، d MMMM yyyy', 'ar').format(d);
}

// ═══════════════════════════════════════════════════════════════
// PDF EXPORT
// ═══════════════════════════════════════════════════════════════

Future<void> _exportPdf(BuildContext context, Map<String, dynamic> data) async {
  // Show a loading toast while the PDF builds — Cairo is loaded over
  // the network the first time which can take a second on slow links.
  ScaffoldMessenger.of(context).showSnackBar(
    const SnackBar(
      content: Row(
        children: [
          SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)),
          SizedBox(width: 12),
          Text('جارٍ تجهيز ملف PDF…'),
        ],
      ),
      duration: Duration(seconds: 4),
    ),
  );
  try {
    final bytes = await _buildPdf(data);
    if (!context.mounted) return;
    await Printing.layoutPdf(
      onLayout: (_) async => bytes,
      name: 'صباح فيد نيوز - ${data['date'] ?? ''}',
      format: pw_color.PdfPageFormat.a4,
    );
  } catch (e) {
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).hideCurrentSnackBar();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('تعذّر تصدير PDF: $e')),
    );
  }
}

Future<List<int>> _buildPdf(Map<String, dynamic> data) async {
  // Cairo font has solid Arabic shaping and is freely embeddable. Pull
  // from Google Fonts via the `printing` helper so we don't ship a 1MB
  // asset for a feature most users hit a few times.
  final cairoRegular = await PdfGoogleFonts.cairoRegular();
  final cairoBold    = await PdfGoogleFonts.cairoBold();
  final cairoBlack   = await PdfGoogleFonts.cairoBlack();
  final notoEmoji    = await PdfGoogleFonts.notoColorEmoji();

  final theme = pw.ThemeData.withFont(
    base:        cairoRegular,
    bold:        cairoBold,
    italic:      cairoRegular,
    boldItalic:  cairoBold,
    fontFallback: [notoEmoji],
  );

  final title = (data['title'] ?? 'صباح فيد نيوز').toString();
  final subtitle = (data['subtitle'] ?? '').toString();
  final summary = (data['summary'] ?? '').toString();
  final sections = (data['sections'] as List? ?? []);
  final keyNumbers = (data['key_numbers'] as List? ?? []);
  final regions = (data['regions'] as List? ?? []);
  final quote = data['quote_of_day'] as Map?;
  final closing = (data['closing_question'] ?? '').toString();
  final articleCount = (data['article_count'] as num?)?.toInt() ?? 0;
  final dateStr = _formatDate(data['date']?.toString());

  final pdf = pw.Document(theme: theme);
  pdf.addPage(
    pw.MultiPage(
      pageFormat: pw_color.PdfPageFormat.a4.copyWith(
        marginTop: 32,
        marginBottom: 32,
        marginLeft: 36,
        marginRight: 36,
      ),
      textDirection: pw.TextDirection.rtl,
      header: (ctx) => ctx.pageNumber == 1
          ? pw.SizedBox.shrink()
          : pw.Padding(
              padding: const pw.EdgeInsets.only(bottom: 12),
              child: pw.Row(
                mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                children: [
                  pw.Text('فيد نيوز', style: pw.TextStyle(
                    fontSize: 10,
                    color: const pw_color.PdfColor.fromInt(0xFF6B7280),
                    fontWeight: pw.FontWeight.bold,
                  )),
                  pw.Text('موجز الصباح • $dateStr', style: const pw.TextStyle(
                    fontSize: 10,
                    color: pw_color.PdfColor.fromInt(0xFF6B7280),
                  )),
                ],
              ),
            ),
      footer: (ctx) => pw.Container(
        padding: const pw.EdgeInsets.only(top: 12),
        decoration: const pw.BoxDecoration(
          border: pw.Border(top: pw.BorderSide(color: pw_color.PdfColor.fromInt(0xFFE5E7EB))),
        ),
        child: pw.Row(
          mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
          children: [
            pw.Text('feedsnews.net', style: const pw.TextStyle(
              fontSize: 9,
              color: pw_color.PdfColor.fromInt(0xFF9CA3AF),
            )),
            pw.Text('${ctx.pageNumber}/${ctx.pagesCount}', style: const pw.TextStyle(
              fontSize: 9,
              color: pw_color.PdfColor.fromInt(0xFF9CA3AF),
            )),
          ],
        ),
      ),
      build: (ctx) => [
        // Hero box
        pw.Container(
          width: double.infinity,
          padding: const pw.EdgeInsets.all(22),
          decoration: const pw.BoxDecoration(
            gradient: pw.LinearGradient(
              begin: pw.Alignment.topRight,
              end: pw.Alignment.bottomLeft,
              colors: [
                pw_color.PdfColor.fromInt(0xFFD97706),
                pw_color.PdfColor.fromInt(0xFFB91C1C),
              ],
            ),
            borderRadius: pw.BorderRadius.all(pw.Radius.circular(12)),
          ),
          child: pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.start,
            children: [
              pw.Row(
                mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                children: [
                  pw.Text('☀ موجز الصباح', style: pw.TextStyle(
                    fontSize: 12,
                    fontWeight: pw.FontWeight.bold,
                    color: pw_color.PdfColors.white,
                  )),
                  if (dateStr.isNotEmpty)
                    pw.Text(dateStr, style: pw.TextStyle(
                      fontSize: 11,
                      fontWeight: pw.FontWeight.bold,
                      color: pw_color.PdfColors.white,
                    )),
                ],
              ),
              pw.SizedBox(height: 14),
              pw.Text(title, style: pw.TextStyle(
                fontSize: 22,
                fontWeight: pw.FontWeight.bold,
                color: pw_color.PdfColors.white,
                height: 1.4,
              )),
              if (subtitle.isNotEmpty) ...[
                pw.SizedBox(height: 8),
                pw.Text(subtitle, style: const pw.TextStyle(
                  fontSize: 12,
                  color: pw_color.PdfColors.white,
                  height: 1.6,
                )),
              ],
              pw.SizedBox(height: 12),
              pw.Text('من $articleCount خبراً' +
                  (regions.isNotEmpty ? '  •  ${regions.join(" • ")}' : ''),
                style: const pw.TextStyle(
                  fontSize: 10,
                  color: pw_color.PdfColors.white,
                )),
            ],
          ),
        ),
        // Hook
        if (summary.isNotEmpty) ...[
          pw.SizedBox(height: 18),
          pw.Container(
            padding: const pw.EdgeInsets.all(14),
            decoration: const pw.BoxDecoration(
              color: pw_color.PdfColor.fromInt(0xFFFEF3C7),
              borderRadius: pw.BorderRadius.all(pw.Radius.circular(8)),
              border: pw.Border(
                right: pw.BorderSide(color: pw_color.PdfColor.fromInt(0xFFD97706), width: 3),
              ),
            ),
            child: pw.Text(summary, textAlign: pw.TextAlign.justify, style: const pw.TextStyle(
              fontSize: 12, height: 1.9, color: pw_color.PdfColor.fromInt(0xFF1F2937),
            )),
          ),
        ],
        // Key numbers
        if (keyNumbers.isNotEmpty) ...[
          pw.SizedBox(height: 18),
          pw.Text('أرقام اليوم', style: pw.TextStyle(
            fontSize: 14, fontWeight: pw.FontWeight.bold,
            color: const pw_color.PdfColor.fromInt(0xFFD97706),
          )),
          pw.SizedBox(height: 8),
          pw.Wrap(
            spacing: 8, runSpacing: 8,
            children: keyNumbers.cast<dynamic>().map((n) {
              final m = (n as Map).cast<String, dynamic>();
              return pw.Container(
                width: 230,
                padding: const pw.EdgeInsets.all(10),
                decoration: const pw.BoxDecoration(
                  color: pw_color.PdfColor.fromInt(0xFFF9FAFB),
                  borderRadius: pw.BorderRadius.all(pw.Radius.circular(6)),
                ),
                child: pw.Column(
                  crossAxisAlignment: pw.CrossAxisAlignment.start,
                  children: [
                    pw.Text((m['value'] ?? '').toString(),
                      style: pw.TextStyle(fontSize: 16, fontWeight: pw.FontWeight.bold,
                        color: const pw_color.PdfColor.fromInt(0xFFD97706))),
                    pw.SizedBox(height: 3),
                    pw.Text((m['context'] ?? '').toString(),
                      style: const pw.TextStyle(fontSize: 10, height: 1.5,
                        color: pw_color.PdfColor.fromInt(0xFF4B5563))),
                  ],
                ),
              );
            }).toList(),
          ),
        ],
        // Sections
        ...sections.cast<dynamic>().where((s) => s is Map).map((s) {
          final m = (s as Map).cast<String, dynamic>();
          final body = (m['body'] ?? '').toString();
          final whyMatters = (m['why_matters'] ?? '').toString();
          final tags = (m['tags'] as List? ?? []).cast<dynamic>();
          return pw.Container(
            margin: const pw.EdgeInsets.only(top: 18),
            padding: const pw.EdgeInsets.all(14),
            decoration: const pw.BoxDecoration(
              color: pw_color.PdfColors.white,
              borderRadius: pw.BorderRadius.all(pw.Radius.circular(8)),
              border: pw.Border.fromBorderSide(pw.BorderSide(
                color: pw_color.PdfColor.fromInt(0xFFE5E7EB), width: 0.5,
              )),
            ),
            child: pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Text(
                  '${m['icon'] ?? ''} ${m['title'] ?? ''}'.trim(),
                  style: pw.TextStyle(
                    fontSize: 14, fontWeight: pw.FontWeight.bold, height: 1.5,
                    color: const pw_color.PdfColor.fromInt(0xFF111827),
                  ),
                ),
                pw.SizedBox(height: 8),
                pw.Text(body, textAlign: pw.TextAlign.justify, style: const pw.TextStyle(
                  fontSize: 11, height: 1.9, color: pw_color.PdfColor.fromInt(0xFF1F2937),
                )),
                if (whyMatters.isNotEmpty) ...[
                  pw.SizedBox(height: 10),
                  pw.Container(
                    padding: const pw.EdgeInsets.all(8),
                    decoration: const pw.BoxDecoration(
                      color: pw_color.PdfColor.fromInt(0xFFFEF3C7),
                      borderRadius: pw.BorderRadius.all(pw.Radius.circular(5)),
                    ),
                    child: pw.RichText(
                      text: pw.TextSpan(
                        children: [
                          pw.TextSpan(text: 'لماذا يهمّك؟  ', style: pw.TextStyle(
                            fontSize: 10, fontWeight: pw.FontWeight.bold,
                            color: const pw_color.PdfColor.fromInt(0xFFB45309))),
                          pw.TextSpan(text: whyMatters, style: const pw.TextStyle(
                            fontSize: 10, height: 1.6,
                            color: pw_color.PdfColor.fromInt(0xFF1F2937))),
                        ],
                      ),
                    ),
                  ),
                ],
                if (tags.isNotEmpty) ...[
                  pw.SizedBox(height: 8),
                  pw.Wrap(
                    spacing: 4, runSpacing: 3,
                    children: tags.map((t) => pw.Container(
                      padding: const pw.EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: const pw.BoxDecoration(
                        color: pw_color.PdfColor.fromInt(0xFFFEF3C7),
                        borderRadius: pw.BorderRadius.all(pw.Radius.circular(3)),
                      ),
                      child: pw.Text('#$t', style: pw.TextStyle(
                        fontSize: 8, fontWeight: pw.FontWeight.bold,
                        color: const pw_color.PdfColor.fromInt(0xFFB45309))),
                    )).toList(),
                  ),
                ],
              ],
            ),
          );
        }),
        // Quote
        if (quote != null) ...[
          pw.SizedBox(height: 18),
          pw.Container(
            padding: const pw.EdgeInsets.all(16),
            decoration: const pw.BoxDecoration(
              color: pw_color.PdfColor.fromInt(0xFFF1F5F9),
              borderRadius: pw.BorderRadius.all(pw.Radius.circular(8)),
            ),
            child: pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Text('« اقتباس اليوم »', style: pw.TextStyle(
                  fontSize: 11, fontWeight: pw.FontWeight.bold,
                  color: const pw_color.PdfColor.fromInt(0xFFD97706))),
                pw.SizedBox(height: 8),
                pw.Text('"${quote['text'] ?? ''}"', style: pw.TextStyle(
                  fontSize: 13, fontStyle: pw.FontStyle.italic, height: 1.8,
                  color: const pw_color.PdfColor.fromInt(0xFF1F2937))),
                pw.SizedBox(height: 8),
                pw.Text('— ${quote['speaker'] ?? ''}', style: pw.TextStyle(
                  fontSize: 11, fontWeight: pw.FontWeight.bold,
                  color: const pw_color.PdfColor.fromInt(0xFF374151))),
              ],
            ),
          ),
        ],
        // Closing
        if (closing.isNotEmpty) ...[
          pw.SizedBox(height: 18),
          pw.Container(
            padding: const pw.EdgeInsets.all(14),
            decoration: pw.BoxDecoration(
              color: const pw_color.PdfColor.fromInt(0xFFFEF3C7),
              borderRadius: const pw.BorderRadius.all(pw.Radius.circular(8)),
              border: pw.Border.all(color: const pw_color.PdfColor.fromInt(0xFFFCD34D)),
            ),
            child: pw.Center(
              child: pw.Text(closing, textAlign: pw.TextAlign.center, style: pw.TextStyle(
                fontSize: 12, fontWeight: pw.FontWeight.bold,
                fontStyle: pw.FontStyle.italic, height: 1.7,
                color: const pw_color.PdfColor.fromInt(0xFFB45309))),
            ),
          ),
        ],
      ],
    ),
  );
  return pdf.save();
}
