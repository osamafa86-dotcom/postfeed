import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart' as intl;

import '../../../core/models/article.dart';
import '../../../core/models/cluster_coverage.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

/// "قارن التغطية" — same story across every source that ran it.
/// Mirrors the website's /cluster/<key> page: canonical headline,
/// source-velocity badge, chronological timeline strip, Smart Brevity
/// recap, News Mirror framing analysis, and the numbered coverage list.
final _clusterProvider = FutureProvider.family((ref, String key) {
  return ref.watch(contentRepositoryProvider).cluster(key);
});

class ClusterScreen extends ConsumerWidget {
  const ClusterScreen({super.key, required this.clusterKey});

  /// 40-char SHA-1 hex key that groups the articles.
  final String clusterKey;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_clusterProvider(clusterKey));
    return Scaffold(
      appBar: AppBar(title: const Text('مقارنة التغطية')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_clusterProvider(clusterKey)),
        ),
        data: (coverage) {
          if (coverage == null || coverage.articles.isEmpty) {
            return const EmptyView(
              icon: Icons.layers_outlined,
              message: 'لم نعثر على تغطيات أخرى لهذا الخبر',
              hint: 'تظهر المقارنة عندما يغطّي نفس الخبر أكثر من مصدر.',
            );
          }
          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(_clusterProvider(clusterKey)),
            child: ListView(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 32),
              children: [
                _HeroCard(coverage: coverage),
                if (coverage.velocity.hasSignal) ...[
                  const SizedBox(height: 10),
                  _VelocityBadge(label: coverage.velocity.label),
                ],
                const SizedBox(height: 12),
                _TimelineStrip(timeline: coverage.timeline),
                if (coverage.hasStoryTimeline) ...[
                  const SizedBox(height: 12),
                  _StoryTimelineCta(clusterKey: coverage.key),
                ],
                if (coverage.brevity != null && !coverage.brevity!.isEmpty) ...[
                  const SizedBox(height: 18),
                  _BrevityCard(brevity: coverage.brevity!),
                ],
                if (coverage.mirror != null && !coverage.mirror!.isEmpty) ...[
                  const SizedBox(height: 14),
                  _MirrorCard(mirror: coverage.mirror!),
                ],
                const SizedBox(height: 22),
                _SectionLabel(text: 'كل التغطيات بالترتيب الزمني'),
                const SizedBox(height: 10),
                for (final a in coverage.articles)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: _CoverageCard(article: a),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════
// HERO — canonical headline + source/article counters
// ═══════════════════════════════════════════════════════════════════

class _HeroCard extends StatelessWidget {
  const _HeroCard({required this.coverage});
  final ClusterCoverage coverage;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      padding: const EdgeInsets.fromLTRB(18, 18, 18, 16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFFFDFBF4), Color(0xFFF5EBCE)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: const Color(0xFFE2C264)),
        borderRadius: BorderRadius.circular(16),
        boxShadow: [BoxShadow(
          color: const Color(0xFFC99624).withOpacity(0.18),
          blurRadius: 18, offset: const Offset(0, 6),
        )],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
            decoration: BoxDecoration(
              color: const Color(0xFFF5EBCE),
              border: Border.all(color: const Color(0xFFE2C264)),
              borderRadius: BorderRadius.circular(999),
            ),
            child: Text(
              '📰 قارن التغطية — ${coverage.sourceCount} مصادر',
              style: const TextStyle(
                color: Color(0xFF6B4F0B),
                fontWeight: FontWeight.w800,
                fontSize: 11,
              ),
            ),
          ),
          const SizedBox(height: 12),
          Text(
            coverage.canonicalTitle.isEmpty
                ? 'قارن التغطية'
                : coverage.canonicalTitle,
            textAlign: TextAlign.right,
            style: TextStyle(
              fontWeight: FontWeight.w900,
              fontSize: 19,
              height: 1.5,
              color: isDark ? const Color(0xFF1B1300) : const Color(0xFF2C2416),
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 14,
            runSpacing: 6,
            children: [
              _meta('🗞', '${coverage.articleCount} تقرير'),
              _meta('🌐', '${coverage.sourceCount} مصدر'),
              if (coverage.earliestAt != null)
                _meta('⏱', 'أوّل نشر ${_timeAgo(coverage.earliestAt!)}'),
              if (coverage.latestAt != null &&
                  coverage.latestAt != coverage.earliestAt)
                _meta('↻', 'آخر تحديث ${_timeAgo(coverage.latestAt!)}'),
            ],
          ),
        ],
      ),
    );
  }

  Widget _meta(String icon, String text) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(icon, style: const TextStyle(fontSize: 13)),
          const SizedBox(width: 4),
          Text(text,
              style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: Color(0xFF7A6E5D))),
        ],
      );
}

// ═══════════════════════════════════════════════════════════════════
// SOURCE VELOCITY BADGE
// ═══════════════════════════════════════════════════════════════════

class _VelocityBadge extends StatelessWidget {
  const _VelocityBadge({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
      decoration: BoxDecoration(
        color: const Color(0xFFCE1126).withOpacity(0.08),
        border: Border.all(color: const Color(0xFFCE1126).withOpacity(0.25)),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(label,
          textAlign: TextAlign.right,
          style: const TextStyle(
              color: Color(0xFFCE1126),
              fontWeight: FontWeight.w800,
              fontSize: 13)),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════
// TIMELINE STRIP — chronological list of source names
// ═══════════════════════════════════════════════════════════════════

class _TimelineStrip extends StatelessWidget {
  const _TimelineStrip({required this.timeline});
  final List<TimelinePoint> timeline;

  @override
  Widget build(BuildContext context) {
    if (timeline.isEmpty) return const SizedBox.shrink();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
        border: Border.all(color: const Color(0xFFDDD5C7)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Text('📅', style: TextStyle(fontSize: 14)),
            const SizedBox(width: 6),
            Text('الترتيب الزمني للنشر',
                style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                    color: isDark ? Colors.white70 : const Color(0xFF7A6E5D))),
          ]),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6, runSpacing: 6,
            children: [
              for (final t in timeline)
                if ((t.source ?? '').isNotEmpty)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: isDark
                          ? Colors.white.withOpacity(0.06)
                          : const Color(0xFFF7F3ED),
                      border: Border.all(color: const Color(0xFFDDD5C7)),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      Container(
                        width: 6, height: 6,
                        decoration: const BoxDecoration(
                          color: Color(0xFFC99624),
                          shape: BoxShape.circle,
                        ),
                      ),
                      const SizedBox(width: 6),
                      Text(t.source!,
                          style: const TextStyle(
                              fontSize: 11.5, fontWeight: FontWeight.w700)),
                    ]),
                  ),
            ],
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════
// STORY TIMELINE CTA
// ═══════════════════════════════════════════════════════════════════

class _StoryTimelineCta extends StatelessWidget {
  const _StoryTimelineCta({required this.clusterKey});
  final String clusterKey;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => context.push('/timeline/$clusterKey'),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [Color(0xFF3D5A28), Color(0xFF2D4520)],
          ),
          borderRadius: BorderRadius.circular(12),
          boxShadow: [BoxShadow(
            color: const Color(0xFF3D5A28).withOpacity(0.45),
            blurRadius: 18, offset: const Offset(0, 6),
          )],
        ),
        child: Row(children: const [
          Icon(Icons.auto_awesome, color: Colors.white, size: 18),
          SizedBox(width: 10),
          Expanded(
            child: Text('شاهد الخط الزمني الذكي للقصّة',
                style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 14)),
          ),
          Icon(Icons.arrow_back_ios_new, color: Colors.white, size: 14),
        ]),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════
// SMART BREVITY CARD — Axios-style 5-section recap
// ═══════════════════════════════════════════════════════════════════

class _BrevityCard extends StatefulWidget {
  const _BrevityCard({required this.brevity});
  final SmartBrevity brevity;

  @override
  State<_BrevityCard> createState() => _BrevityCardState();
}

class _BrevityCardState extends State<_BrevityCard> {
  bool _open = true;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: isDark
              ? [const Color(0xFF1A2A1A), const Color(0xFF14272B)]
              : [const Color(0xFFF0F4E5), const Color(0xFFECFDF5)],
        ),
        border: Border.all(color: const Color(0xFF3D5A28).withOpacity(0.25)),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            onTap: () => setState(() => _open = !_open),
            borderRadius: const BorderRadius.vertical(top: Radius.circular(14)),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
              child: Row(children: [
                const Expanded(
                  child: Text('⚡ الإيجاز الذكي — خلاصة القصة في 30 ثانية',
                      style: TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w800,
                          color: Color(0xFF2D4520))),
                ),
                AnimatedRotation(
                  turns: _open ? 0 : -0.25,
                  duration: const Duration(milliseconds: 220),
                  child: const Icon(Icons.expand_more,
                      color: Color(0xFF2D4520)),
                ),
              ]),
            ),
          ),
          if (_open) ...[
            Container(
              height: 1,
              color: const Color(0xFF3D5A28).withOpacity(0.12),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (widget.brevity.whyMatters.isNotEmpty)
                    _section('🎯', 'لماذا يهم',
                        Text(widget.brevity.whyMatters,
                            textAlign: TextAlign.right,
                            style: const TextStyle(
                                fontSize: 13.5, height: 1.7))),
                  if (widget.brevity.bigPicture.isNotEmpty)
                    _section('🌍', 'الصورة الأكبر',
                        Text(widget.brevity.bigPicture,
                            textAlign: TextAlign.right,
                            style: const TextStyle(
                                fontSize: 13.5, height: 1.7))),
                  if (widget.brevity.byTheNumbers.isNotEmpty)
                    _section('📊', 'بالأرقام',
                        Wrap(spacing: 8, runSpacing: 8, children: [
                          for (final n in widget.brevity.byTheNumbers)
                            _NumberPill(value: n.value, context: n.context),
                        ])),
                  if (widget.brevity.whatTheySay.isNotEmpty)
                    _section('💬', 'ماذا يقولون',
                        Column(children: [
                          for (final q in widget.brevity.whatTheySay)
                            _QuotePill(quote: q.quote, speaker: q.speaker),
                        ])),
                  if (widget.brevity.zoomIn.isNotEmpty)
                    _section('🔍', 'تقريب العدسة',
                        Text(widget.brevity.zoomIn,
                            textAlign: TextAlign.right,
                            style: const TextStyle(
                                fontSize: 13.5, height: 1.7))),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _section(String icon, String label, Widget body) => Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Text(icon, style: const TextStyle(fontSize: 13)),
              const SizedBox(width: 5),
              Text(label,
                  style: const TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w800,
                      color: Color(0xFF2D4520))),
            ]),
            const SizedBox(height: 6),
            body,
          ],
        ),
      );
}

class _NumberPill extends StatelessWidget {
  const _NumberPill({required this.value, required this.context});
  final String value;
  final String context;

  @override
  Widget build(BuildContext c) {
    return Container(
      constraints: const BoxConstraints(minWidth: 130),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: const Color(0xFF3D5A28).withOpacity(0.18)),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(value,
              style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                  color: Color(0xFF2D4520),
                  height: 1.1)),
          if (context.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(context,
                  style: const TextStyle(
                      fontSize: 11,
                      color: Color(0xFF7A6E5D),
                      fontWeight: FontWeight.w600)),
            ),
        ],
      ),
    );
  }
}

class _QuotePill extends StatelessWidget {
  const _QuotePill({required this.quote, required this.speaker});
  final String quote;
  final String speaker;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(
          right: BorderSide(color: Color(0xFFC99624), width: 3),
        ),
        borderRadius: BorderRadius.horizontal(left: Radius.circular(10)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('«$quote»',
              textAlign: TextAlign.right,
              style: const TextStyle(
                  fontSize: 13,
                  fontStyle: FontStyle.italic,
                  height: 1.6)),
          if (speaker.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('— $speaker',
                  style: const TextStyle(
                      fontSize: 11,
                      color: Color(0xFF7A6E5D),
                      fontWeight: FontWeight.w800)),
            ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════
// NEWS MIRROR CARD — same concept, different words
// ═══════════════════════════════════════════════════════════════════

class _MirrorCard extends StatefulWidget {
  const _MirrorCard({required this.mirror});
  final NewsMirror mirror;

  @override
  State<_MirrorCard> createState() => _MirrorCardState();
}

class _MirrorCardState extends State<_MirrorCard> {
  bool _open = true;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: isDark
              ? [const Color(0xFF211A0F), const Color(0xFF181722)]
              : [const Color(0xFFFBF7EE), const Color(0xFFEAF0F4)],
        ),
        border: Border.all(color: const Color(0xFFE2C264)),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            onTap: () => setState(() => _open = !_open),
            borderRadius: const BorderRadius.vertical(top: Radius.circular(14)),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
              child: Row(children: [
                const Expanded(
                  child: Text('🪞 مرايا الأخبار — كيف اختلفت صياغة المصادر',
                      style: TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w800,
                          color: Color(0xFF6B4F0B))),
                ),
                AnimatedRotation(
                  turns: _open ? 0 : -0.25,
                  duration: const Duration(milliseconds: 220),
                  child: const Icon(Icons.expand_more,
                      color: Color(0xFF6B4F0B)),
                ),
              ]),
            ),
          ),
          if (_open) ...[
            Container(
              height: 1,
              color: const Color(0xFFC99624).withOpacity(0.25),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (widget.mirror.neutralSummary.isNotEmpty) ...[
                    const Text('⚖️ الخلاصة المحايدة',
                        style: TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w800,
                            color: Color(0xFF3D5A28))),
                    const SizedBox(height: 6),
                    Container(
                      padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        border: Border(
                          right: BorderSide(color: Color(0xFF5B7F3B), width: 3),
                        ),
                        borderRadius: BorderRadius.horizontal(left: Radius.circular(10)),
                      ),
                      child: Text(widget.mirror.neutralSummary,
                          textAlign: TextAlign.right,
                          style: const TextStyle(fontSize: 13.5, height: 1.7)),
                    ),
                  ],
                  if (widget.mirror.divergentTerms.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    const Text('🔤 نفس المعنى... كلمات مختلفة',
                        style: TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w800,
                            color: Color(0xFF3D5A28))),
                    const SizedBox(height: 8),
                    for (final term in widget.mirror.divergentTerms)
                      _DivergentTermRow(term: term),
                  ],
                  if (widget.mirror.framings.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    const Text('🎚 زاوية كل مصدر',
                        style: TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w800,
                            color: Color(0xFF3D5A28))),
                    const SizedBox(height: 8),
                    for (final f in widget.mirror.framings)
                      _FramingRow(framing: f),
                  ],
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _DivergentTermRow extends StatelessWidget {
  const _DivergentTermRow({required this.term});
  final DivergentTerm term;

  Color _toneBg(String tone) {
    if (tone.contains('سلب')) return const Color(0xFFFDECEE);
    if (tone.contains('إيجاب') || tone.contains('ايجاب')) return const Color(0xFFE8F6EE);
    return const Color(0xFFF5EBCE);
  }

  Color _toneText(String tone) {
    if (tone.contains('سلب')) return const Color(0xFF9F0D1D);
    if (tone.contains('إيجاب') || tone.contains('ايجاب')) return const Color(0xFF1B7A3D);
    return const Color(0xFF6B4F0B);
  }

  Color _toneBorder(String tone) {
    if (tone.contains('سلب')) return const Color(0xFFCE1126).withOpacity(0.45);
    if (tone.contains('إيجاب') || tone.contains('ايجاب')) return const Color(0xFF1B7A3D).withOpacity(0.45);
    return const Color(0xFFE2C264);
  }

  @override
  Widget build(BuildContext context) {
    if (term.concept.isEmpty || term.variants.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Text('≡',
                style: TextStyle(
                    color: Color(0xFFC99624),
                    fontSize: 14,
                    fontWeight: FontWeight.w900)),
            const SizedBox(width: 6),
            Expanded(
              child: Text(term.concept,
                  textAlign: TextAlign.right,
                  style: const TextStyle(
                      fontSize: 13, fontWeight: FontWeight.w800)),
            ),
          ]),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6, runSpacing: 6,
            children: [
              for (final v in term.variants)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
                  decoration: BoxDecoration(
                    color: _toneBg(v.tone),
                    border: Border.all(color: _toneBorder(v.tone)),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('«${v.term}»',
                          style: TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w800,
                              color: _toneText(v.tone))),
                      if (v.sources.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 1),
                          child: Text(v.sources.join('، '),
                              style: const TextStyle(
                                  fontSize: 10,
                                  color: Color(0xFF7A6E5D),
                                  fontWeight: FontWeight.w600)),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _FramingRow extends StatelessWidget {
  const _FramingRow({required this.framing});
  final SourceFraming framing;

  @override
  Widget build(BuildContext context) {
    if (framing.angle.isEmpty) return const SizedBox.shrink();
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: const Color(0xFFDDD5C7)),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (framing.sources.isNotEmpty)
            Text(framing.sources.join('، '),
                style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                    color: Color(0xFF2D4520))),
          if (framing.sources.isNotEmpty) const SizedBox(height: 3),
          Text(framing.angle,
              textAlign: TextAlign.right,
              style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700)),
          if (framing.emphasis.isNotEmpty) ...[
            const SizedBox(height: 3),
            Text(framing.emphasis,
                textAlign: TextAlign.right,
                style: const TextStyle(
                    fontSize: 12.5,
                    color: Color(0xFF7A6E5D),
                    height: 1.6)),
          ],
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════
// COVERAGE LIST — chronological, numbered cards
// ═══════════════════════════════════════════════════════════════════

class _SectionLabel extends StatelessWidget {
  const _SectionLabel({required this.text});
  final String text;
  @override
  Widget build(BuildContext context) {
    return Row(children: [
      Container(width: 4, height: 18, color: const Color(0xFFC99624)),
      const SizedBox(width: 8),
      Text(text,
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w900)),
    ]);
  }
}

class _CoverageCard extends StatelessWidget {
  const _CoverageCard({required this.article});
  final Article article;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final summary = (article.aiSummary ?? article.excerpt ?? '').trim();
    final srcName = article.source?.name ?? '—';
    final srcColor = _parseColor(article.source?.logoColor) ?? const Color(0xFF3D5A28);
    final order = article.coverageOrder;

    return GestureDetector(
      onTap: () => context.push('/article/${article.id}'),
      child: Container(
        decoration: BoxDecoration(
          color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
          border: Border.all(color: const Color(0xFFDDD5C7)),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if ((article.imageUrl ?? '').isNotEmpty)
              ClipRRect(
                borderRadius: const BorderRadius.vertical(top: Radius.circular(14)),
                child: AspectRatio(
                  aspectRatio: 16 / 9,
                  child: Stack(fit: StackFit.expand, children: [
                    CachedNetworkImage(
                      imageUrl: article.imageUrl!,
                      fit: BoxFit.cover,
                      placeholder: (_, __) => Container(color: const Color(0xFFE8E3DB)),
                      errorWidget: (_, __, ___) => Container(color: const Color(0xFFE8E3DB)),
                    ),
                    if (order > 0)
                      Positioned(top: 10, right: 10, child: _OrderBubble(order: order)),
                  ]),
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Container(
                      width: 22, height: 22,
                      decoration: BoxDecoration(color: srcColor, shape: BoxShape.circle),
                      alignment: Alignment.center,
                      child: Text(
                        srcName.isNotEmpty ? srcName.substring(0, 1) : '?',
                        style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            fontSize: 11),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(srcName,
                        style: TextStyle(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w800,
                            color: isDark ? Colors.white70 : AppColors.textLight)),
                    if (article.publishedAt != null) ...[
                      const SizedBox(width: 6),
                      const Text('·',
                          style: TextStyle(color: Color(0xFF7A6E5D))),
                      const SizedBox(width: 6),
                      Text(_timeAgo(article.publishedAt!),
                          style: const TextStyle(
                              fontSize: 11.5,
                              fontWeight: FontWeight.w700,
                              color: Color(0xFF7A6E5D))),
                    ],
                  ]),
                  const SizedBox(height: 8),
                  Text(article.title,
                      textAlign: TextAlign.right,
                      style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                          height: 1.55,
                          color: isDark ? Colors.white : const Color(0xFF2C2416))),
                  if (summary.isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Text(
                      summary.length > 280 ? '${summary.substring(0, 280)}…' : summary,
                      textAlign: TextAlign.right,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 12.5,
                          color: Color(0xFF7A6E5D),
                          height: 1.65),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _OrderBubble extends StatelessWidget {
  const _OrderBubble({required this.order});
  final int order;
  @override
  Widget build(BuildContext context) {
    return Container(
      width: 28, height: 28,
      decoration: BoxDecoration(
        color: Colors.black.withOpacity(0.65),
        shape: BoxShape.circle,
      ),
      alignment: Alignment.center,
      child: Text('$order',
          style: const TextStyle(
              color: Colors.white,
              fontSize: 12,
              fontWeight: FontWeight.w800)),
    );
  }
}

Color? _parseColor(String? hex) {
  if (hex == null) return null;
  final clean = hex.replaceAll('#', '');
  final value = int.tryParse('FF$clean', radix: 16);
  return value == null ? null : Color(value);
}

// ═══════════════════════════════════════════════════════════════════
// Tiny time-ago helper to match the website's timeAgo() output.
// Pure Dart so we don't need a timezone or locale package here.
// ═══════════════════════════════════════════════════════════════════

String _timeAgo(DateTime dt) {
  final diff = DateTime.now().difference(dt);
  if (diff.inMinutes < 1) return 'الآن';
  if (diff.inMinutes < 60) return 'قبل ${diff.inMinutes} د';
  if (diff.inHours < 24) return 'قبل ${diff.inHours} س';
  if (diff.inDays < 30) return 'قبل ${diff.inDays} ي';
  final fmt = intl.DateFormat('d MMM', 'ar');
  return fmt.format(dt);
}
