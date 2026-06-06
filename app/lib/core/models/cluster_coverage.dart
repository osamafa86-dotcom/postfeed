import 'article.dart';

/// "قارن التغطية" — every field the cluster_screen needs to mirror
/// the website's /cluster/<key> page (canonical headline, source-
/// velocity tag, Smart Brevity + News Mirror AI cards, the ordered
/// coverage list). Returned by GET /api/v1/content/cluster?key=…
class ClusterCoverage {
  const ClusterCoverage({
    required this.key,
    required this.canonicalTitle,
    required this.articleCount,
    required this.sourceCount,
    this.earliestAt,
    this.latestAt,
    this.heroImage,
    required this.velocity,
    required this.timeline,
    this.hasStoryTimeline = false,
    this.brevity,
    this.mirror,
    this.articles = const [],
  });

  final String key;
  final String canonicalTitle;
  final int articleCount;
  final int sourceCount;
  final DateTime? earliestAt;
  final DateTime? latestAt;
  final String? heroImage;
  final SourceVelocity velocity;
  final List<TimelinePoint> timeline;
  final bool hasStoryTimeline;
  final SmartBrevity? brevity;
  final NewsMirror? mirror;
  final List<Article> articles;

  factory ClusterCoverage.fromJson(Map<String, dynamic> j) => ClusterCoverage(
        key: (j['key'] as String?) ?? '',
        canonicalTitle: (j['canonical_title'] as String?) ?? '',
        articleCount: (j['article_count'] as num?)?.toInt() ?? 0,
        sourceCount: (j['source_count'] as num?)?.toInt() ?? 0,
        earliestAt: _parseDate(j['earliest_at']),
        latestAt: _parseDate(j['latest_at']),
        heroImage: j['hero_image'] as String?,
        velocity: SourceVelocity.fromJson((j['velocity'] as Map?)?.cast<String, dynamic>() ?? const {}),
        timeline: ((j['timeline'] as List?) ?? const [])
            .whereType<Map>()
            .map((m) => TimelinePoint.fromJson(m.cast<String, dynamic>()))
            .toList(),
        hasStoryTimeline: (j['has_story_timeline'] as bool?) ?? false,
        brevity: j['brevity'] is Map
            ? SmartBrevity.fromJson((j['brevity'] as Map).cast<String, dynamic>())
            : null,
        mirror: j['mirror'] is Map
            ? NewsMirror.fromJson((j['mirror'] as Map).cast<String, dynamic>())
            : null,
        articles: ((j['articles'] as List?) ?? const [])
            .whereType<Map>()
            .map((m) => Article.fromJson(m.cast<String, dynamic>()))
            .toList(),
      );
}

DateTime? _parseDate(dynamic v) {
  if (v == null) return null;
  return DateTime.tryParse(v.toString().replaceFirst(' ', 'T'));
}

class SourceVelocity {
  const SourceVelocity({
    this.sources15m = 0,
    this.sources1h = 0,
    this.sources6h = 0,
    this.score = 0,
    this.label = '',
  });
  final int sources15m;
  final int sources1h;
  final int sources6h;
  final int score;
  final String label;

  bool get hasSignal => label.isNotEmpty;

  factory SourceVelocity.fromJson(Map<String, dynamic> j) => SourceVelocity(
        sources15m: (j['sources_15m'] as num?)?.toInt() ?? 0,
        sources1h: (j['sources_1h'] as num?)?.toInt() ?? 0,
        sources6h: (j['sources_6h'] as num?)?.toInt() ?? 0,
        score: (j['score'] as num?)?.toInt() ?? 0,
        label: (j['label'] as String?) ?? '',
      );
}

class TimelinePoint {
  const TimelinePoint({this.source, this.publishedAt});
  final String? source;
  final DateTime? publishedAt;

  factory TimelinePoint.fromJson(Map<String, dynamic> j) => TimelinePoint(
        source: j['source'] as String?,
        publishedAt: _parseDate(j['published_at']),
      );
}

/// Axios-style 5-section recap of the cluster, AI-generated.
class SmartBrevity {
  const SmartBrevity({
    this.whyMatters = '',
    this.bigPicture = '',
    this.byTheNumbers = const [],
    this.whatTheySay = const [],
    this.zoomIn = '',
  });
  final String whyMatters;
  final String bigPicture;
  final List<BrevityNumber> byTheNumbers;
  final List<BrevityQuote> whatTheySay;
  final String zoomIn;

  bool get isEmpty =>
      whyMatters.isEmpty &&
      bigPicture.isEmpty &&
      byTheNumbers.isEmpty &&
      whatTheySay.isEmpty &&
      zoomIn.isEmpty;

  factory SmartBrevity.fromJson(Map<String, dynamic> j) => SmartBrevity(
        whyMatters: (j['why_matters'] as String?) ?? '',
        bigPicture: (j['big_picture'] as String?) ?? '',
        byTheNumbers: ((j['by_the_numbers'] as List?) ?? const [])
            .whereType<Map>()
            .map((m) => BrevityNumber.fromJson(m.cast<String, dynamic>()))
            .toList(),
        whatTheySay: ((j['what_they_say'] as List?) ?? const [])
            .whereType<Map>()
            .map((m) => BrevityQuote.fromJson(m.cast<String, dynamic>()))
            .toList(),
        zoomIn: (j['zoom_in'] as String?) ?? '',
      );
}

class BrevityNumber {
  const BrevityNumber({required this.value, this.context = ''});
  final String value;
  final String context;
  factory BrevityNumber.fromJson(Map<String, dynamic> j) => BrevityNumber(
        value: (j['value'] as String?) ?? '',
        context: (j['context'] as String?) ?? '',
      );
}

class BrevityQuote {
  const BrevityQuote({required this.quote, this.speaker = ''});
  final String quote;
  final String speaker;
  factory BrevityQuote.fromJson(Map<String, dynamic> j) => BrevityQuote(
        quote: (j['quote'] as String?) ?? '',
        speaker: (j['speaker'] as String?) ?? '',
      );
}

/// "مرايا الأخبار" — same-concept-different-words analysis.
class NewsMirror {
  const NewsMirror({
    this.neutralSummary = '',
    this.divergentTerms = const [],
    this.framings = const [],
  });
  final String neutralSummary;
  final List<DivergentTerm> divergentTerms;
  final List<SourceFraming> framings;

  bool get isEmpty =>
      neutralSummary.isEmpty && divergentTerms.isEmpty && framings.isEmpty;

  factory NewsMirror.fromJson(Map<String, dynamic> j) => NewsMirror(
        neutralSummary: (j['neutral_summary'] as String?) ?? '',
        divergentTerms: ((j['divergent_terms'] as List?) ?? const [])
            .whereType<Map>()
            .map((m) => DivergentTerm.fromJson(m.cast<String, dynamic>()))
            .toList(),
        framings: ((j['framings'] as List?) ?? const [])
            .whereType<Map>()
            .map((m) => SourceFraming.fromJson(m.cast<String, dynamic>()))
            .toList(),
      );
}

class DivergentTerm {
  const DivergentTerm({this.concept = '', this.variants = const []});
  final String concept;
  final List<TermVariant> variants;
  factory DivergentTerm.fromJson(Map<String, dynamic> j) => DivergentTerm(
        concept: (j['concept'] as String?) ?? '',
        variants: ((j['variants'] as List?) ?? const [])
            .whereType<Map>()
            .map((m) => TermVariant.fromJson(m.cast<String, dynamic>()))
            .toList(),
      );
}

class TermVariant {
  const TermVariant({this.term = '', this.sources = const [], this.tone = ''});
  final String term;
  final List<String> sources;
  final String tone;
  factory TermVariant.fromJson(Map<String, dynamic> j) => TermVariant(
        term: (j['term'] as String?) ?? '',
        sources: ((j['sources'] as List?) ?? const [])
            .map((s) => s.toString())
            .toList(),
        tone: (j['tone'] as String?) ?? '',
      );
}

class SourceFraming {
  const SourceFraming({this.sources = const [], this.angle = '', this.emphasis = ''});
  final List<String> sources;
  final String angle;
  final String emphasis;
  factory SourceFraming.fromJson(Map<String, dynamic> j) => SourceFraming(
        sources: ((j['sources'] as List?) ?? const [])
            .map((s) => s.toString())
            .toList(),
        angle: (j['angle'] as String?) ?? '',
        emphasis: (j['emphasis'] as String?) ?? '',
      );
}
