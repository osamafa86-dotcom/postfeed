import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';

class TelegramMessage {
  const TelegramMessage({
    required this.id,
    required this.text,
    this.imageUrl,
    this.postUrl,
    this.postedAt,
    required this.sourceName,
    this.sourceUsername,
    this.sourceAvatar,
    this.duplicateCount = 0,
    this.alsoReportedBy = const [],
  });

  final int id;
  final String text;
  final String? imageUrl;
  final String? postUrl;
  final DateTime? postedAt;
  final String sourceName;
  final String? sourceUsername;
  final String? sourceAvatar;

  /// How many other sources reported the same story (set when the feed
  /// is requested with dedup=1). Zero means "shown as-is / unique".
  final int duplicateCount;

  /// Display names of those other sources.
  final List<String> alsoReportedBy;

  static int _dupCount(Map<String, dynamic> j) =>
      (j['duplicate_count'] as num?)?.toInt() ?? 0;
  static List<String> _alsoReported(Map<String, dynamic> j) =>
      (j['also_reported_by'] as List? ?? []).map((e) => e.toString()).toList();

  factory TelegramMessage.fromJson(Map<String, dynamic> j) {
    final s = (j['source'] as Map?)?.cast<String, dynamic>() ?? {};
    return TelegramMessage(
      id: (j['id'] as num).toInt(),
      text: (j['text'] as String?) ?? '',
      imageUrl: j['image_url'] as String?,
      postUrl: j['post_url'] as String?,
      postedAt: j['posted_at'] != null
          ? DateTime.tryParse(j['posted_at'].toString().replaceFirst(' ', 'T'))
          : null,
      sourceName: (s['display_name'] as String?) ?? '',
      sourceUsername: s['username'] as String?,
      sourceAvatar: s['avatar_url'] as String?,
      duplicateCount: _dupCount(j),
      alsoReportedBy: _alsoReported(j),
    );
  }
}

class TwitterMessage extends TelegramMessage {
  const TwitterMessage({
    required super.id,
    required super.text,
    super.imageUrl,
    super.postUrl,
    super.postedAt,
    required super.sourceName,
    super.sourceUsername,
    super.sourceAvatar,
    super.duplicateCount,
    super.alsoReportedBy,
  });

  factory TwitterMessage.fromJson(Map<String, dynamic> j) {
    final base = TelegramMessage.fromJson(j);
    return TwitterMessage(
      id: base.id,
      text: base.text,
      imageUrl: base.imageUrl,
      postUrl: base.postUrl,
      postedAt: base.postedAt,
      sourceName: base.sourceName,
      sourceUsername: base.sourceUsername,
      sourceAvatar: base.sourceAvatar,
      duplicateCount: base.duplicateCount,
      alsoReportedBy: base.alsoReportedBy,
    );
  }
}

class YoutubeVideo {
  const YoutubeVideo({
    required this.id,
    required this.videoId,
    required this.videoUrl,
    required this.title,
    this.description = '',
    this.thumbnailUrl,
    this.durationSeconds = 0,
    this.postedAt,
    required this.sourceName,
  });

  final int id;
  final String videoId;
  final String videoUrl;
  final String title;
  final String description;
  final String? thumbnailUrl;
  final int durationSeconds;
  final DateTime? postedAt;
  final String sourceName;

  factory YoutubeVideo.fromJson(Map<String, dynamic> j) {
    final s = (j['source'] as Map?)?.cast<String, dynamic>() ?? {};
    return YoutubeVideo(
      id: (j['id'] as num).toInt(),
      videoId: j['video_id'] as String,
      videoUrl: j['video_url'] as String,
      title: j['title'] as String,
      description: (j['description'] as String?) ?? '',
      thumbnailUrl: j['thumbnail_url'] as String?,
      durationSeconds: (j['duration_seconds'] as num?)?.toInt() ?? 0,
      postedAt: j['posted_at'] != null
          ? DateTime.tryParse(j['posted_at'].toString().replaceFirst(' ', 'T'))
          : null,
      sourceName: (s['display_name'] as String?) ?? '',
    );
  }
}

class ReelItem {
  const ReelItem({
    required this.id,
    required this.url,
    required this.shortcode,
    this.caption,
    this.thumbnailUrl,
    this.sourceName,
  });

  final int id;
  final String url;
  final String shortcode;
  final String? caption;
  final String? thumbnailUrl;
  final String? sourceName;

  factory ReelItem.fromJson(Map<String, dynamic> j) {
    final s = (j['source'] as Map?)?.cast<String, dynamic>() ?? {};
    return ReelItem(
      id: (j['id'] as num).toInt(),
      url: j['instagram_url'] as String,
      shortcode: j['shortcode'] as String,
      caption: j['caption'] as String?,
      thumbnailUrl: j['thumbnail_url'] as String?,
      sourceName: s['display_name'] as String?,
    );
  }
}

class GalleryDay {
  const GalleryDay({
    required this.id,
    required this.date,
    this.headline,
    this.intro,
    this.photos = const [],
  });

  final int id;
  final String date;
  final String? headline;
  final String? intro;
  final List<Map<String, dynamic>> photos;

  factory GalleryDay.fromJson(Map<String, dynamic> j) => GalleryDay(
        id: (j['id'] as num).toInt(),
        date: j['date'] as String,
        headline: j['headline'] as String?,
        intro: j['intro'] as String?,
        photos: (j['photos'] as List? ?? [])
            .whereType<Map>()
            .map((m) => m.cast<String, dynamic>())
            .toList(),
      );
}

class MapPoint {
  const MapPoint({
    required this.id,
    required this.place,
    required this.lat,
    required this.lng,
    this.confidence = 0,
    this.articleId,
    this.articleTitle,
    this.articleSlug,
    this.articleImage,
  });

  final int id;
  final String place;
  final double lat;
  final double lng;
  final double confidence;
  final int? articleId;
  final String? articleTitle;
  final String? articleSlug;
  final String? articleImage;

  factory MapPoint.fromJson(Map<String, dynamic> j) {
    final a = (j['article'] as Map?)?.cast<String, dynamic>();
    return MapPoint(
      id: (j['id'] as num).toInt(),
      place: (j['place'] as String?) ?? '',
      lat: (j['lat'] as num).toDouble(),
      lng: (j['lng'] as num).toDouble(),
      confidence: (j['confidence'] as num?)?.toDouble() ?? 0,
      articleId: a?['id'] as int?,
      articleTitle: a?['title'] as String?,
      articleSlug: a?['slug'] as String?,
      articleImage: a?['image_url'] as String?,
    );
  }
}

/// One section of a rich platform summary (a deep-dive on a topic).
class PlatformSummarySection {
  const PlatformSummarySection({
    required this.title,
    this.icon = '',
    this.items = const [],
    this.whyMatters = '',
  });

  final String title;
  final String icon;
  final List<String> items;
  final String whyMatters;

  factory PlatformSummarySection.fromJson(Map<String, dynamic> j) =>
      PlatformSummarySection(
        title: (j['title'] as String?) ?? '',
        icon: (j['icon'] as String?) ?? '',
        items: (j['items'] as List? ?? []).map((e) => e.toString()).toList(),
        whyMatters: (j['why_matters'] as String?) ?? '',
      );
}

/// Rich, de-duplicated daily AI summary for a social platform.
/// Mirrors /api/v1/media/social-summary (telegram/twitter/youtube).
class PlatformSummary {
  const PlatformSummary({
    this.summary = '',
    this.headline = '',
    this.subheadline = '',
    this.sections = const [],
    this.keyNumbers = const [],
    this.regions = const [],
    this.topics = const [],
    this.messageCount = 0,
    this.generatedAt,
  });

  final String summary;
  final String headline;
  final String subheadline;
  final List<PlatformSummarySection> sections;
  final List<({String value, String context})> keyNumbers;
  final List<String> regions;
  final List<String> topics;
  final int messageCount;
  final DateTime? generatedAt;

  bool get isEmpty => summary.isEmpty && sections.isEmpty;

  factory PlatformSummary.fromJson(Map<String, dynamic> j) => PlatformSummary(
        summary: (j['summary'] as String?) ?? '',
        headline: (j['headline'] as String?) ?? '',
        subheadline: (j['subheadline'] as String?) ?? '',
        sections: (j['sections'] as List? ?? [])
            .whereType<Map>()
            .map((m) => PlatformSummarySection.fromJson(m.cast<String, dynamic>()))
            .toList(),
        keyNumbers: (j['key_numbers'] as List? ?? [])
            .whereType<Map>()
            .map((m) => (
                  value: (m['value'] ?? '').toString(),
                  context: (m['context'] ?? '').toString(),
                ))
            .where((e) => e.value.isNotEmpty)
            .toList(),
        regions: (j['regions'] as List? ?? []).map((e) => e.toString()).toList(),
        topics: (j['topics'] as List? ?? []).map((e) => e.toString()).toList(),
        messageCount: (j['message_count'] as num?)?.toInt() ?? 0,
        generatedAt: j['generated_at'] != null
            ? DateTime.tryParse(j['generated_at'].toString())
            : null,
      );
}

/// Analytics for one platform over a time range (24h/7d/30d).
class PlatformStats {
  const PlatformStats({
    this.total = 0,
    this.activeSources = 0,
    this.palestineShare = 0,
    this.timeline = const [],
    this.topSources = const [],
    this.topTopics = const [],
    this.peak,
  });

  final int total;
  final int activeSources;
  final double palestineShare; // 0..1
  final List<({String label, int count})> timeline;
  final List<({String name, int count})> topSources;
  final List<({String tag, int count})> topTopics;
  final String? peak;

  factory PlatformStats.fromJson(Map<String, dynamic> j) => PlatformStats(
        total: (j['total'] as num?)?.toInt() ?? 0,
        activeSources: (j['active_sources'] as num?)?.toInt() ?? 0,
        palestineShare: (j['palestine_share'] as num?)?.toDouble() ?? 0,
        timeline: (j['timeline'] as List? ?? [])
            .whereType<Map>()
            .map((m) => (
                  label: (m['label'] ?? '').toString(),
                  count: (m['count'] as num?)?.toInt() ?? 0,
                ))
            .toList(),
        topSources: (j['top_sources'] as List? ?? [])
            .whereType<Map>()
            .map((m) => (
                  name: (m['name'] ?? '').toString(),
                  count: (m['count'] as num?)?.toInt() ?? 0,
                ))
            .toList(),
        topTopics: (j['top_topics'] as List? ?? [])
            .whereType<Map>()
            .map((m) => (
                  tag: (m['tag'] ?? '').toString(),
                  count: (m['count'] as num?)?.toInt() ?? 0,
                ))
            .toList(),
        peak: j['peak']?.toString(),
      );
}

class MediaRepository {
  MediaRepository(this._api);
  final ApiClient _api;

  Future<List<TelegramMessage>> telegram(
      {int? sinceId, int? beforeId, int limit = 30, bool dedup = false}) async {
    final res = await _api.get<List<TelegramMessage>>(
      '/media/telegram',
      query: {
        'limit': limit,
        if (sinceId != null) 'since_id': sinceId,
        if (beforeId != null) 'before_id': beforeId,
        if (dedup) 'dedup': 1,
      },
      decode: (d) => (d as List)
          .whereType<Map>()
          .map((m) => TelegramMessage.fromJson(m.cast()))
          .toList(),
    );
    return res.data ?? const [];
  }

  Future<List<TwitterMessage>> twitter(
      {int? sinceId, int? beforeId, int limit = 30, bool sync = false, bool dedup = false}) async {
    final res = await _api.get<List<TwitterMessage>>(
      '/media/twitter',
      query: {
        'limit': limit,
        if (sinceId != null) 'since_id': sinceId,
        if (beforeId != null) 'before_id': beforeId,
        // Opt-in scrape trigger — server only actually scrapes if its
        // newest tweet is >30s old AND the cross-process cooldown allows.
        if (sync) 'sync': 1,
        if (dedup) 'dedup': 1,
      },
      decode: (d) => (d as List)
          .whereType<Map>()
          .map((m) => TwitterMessage.fromJson(m.cast()))
          .toList(),
    );
    return res.data ?? const [];
  }

  Future<List<YoutubeVideo>> youtube({int? sinceId, int? beforeId, int limit = 30}) async {
    final res = await _api.get<List<YoutubeVideo>>(
      '/media/youtube',
      query: {
        'limit': limit,
        if (sinceId != null) 'since_id': sinceId,
        if (beforeId != null) 'before_id': beforeId,
      },
      decode: (d) => (d as List)
          .whereType<Map>()
          .map((m) => YoutubeVideo.fromJson(m.cast()))
          .toList(),
    );
    return res.data ?? const [];
  }

  Future<List<ReelItem>> reels({int page = 1, int limit = 20}) async {
    final res = await _api.get<List<ReelItem>>(
      '/media/reels',
      query: {'page': page, 'limit': limit},
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => ReelItem.fromJson(m.cast())).toList(),
    );
    return res.data ?? const [];
  }

  Future<GalleryDay> gallery({String? date}) async {
    final res = await _api.get<GalleryDay>(
      '/media/gallery',
      query: {if (date != null) 'date': date},
      decode: (d) => GalleryDay.fromJson((d as Map).cast()),
    );
    return res.data ?? const GalleryDay(id: 0, date: '');
  }

  Future<List<MapPoint>> newsMap() async {
    final res = await _api.get<List<MapPoint>>(
      '/content/news-map',
      decode: (d) {
        final pts = (d as Map)['points'] as List? ?? [];
        return pts.whereType<Map>().map((m) => MapPoint.fromJson(m.cast())).toList();
      },
    );
    return res.data ?? const [];
  }

  Future<({String answer, List<Map<String, dynamic>> sources})> ask(String question) async {
    final res = await _api.post<Map<String, dynamic>>(
      '/media/ask',
      body: {'question': question},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    final sources = (res.data!['sources'] as List? ?? [])
        .whereType<Map>()
        .map((m) => m.cast<String, dynamic>())
        .toList();
    return (answer: res.data!['answer']?.toString() ?? '', sources: sources);
  }

  /// AI summary of latest social media posts (telegram/twitter).
  /// Kept for the flat-string consumers (home screen teaser).
  Future<String> socialSummary(String platform) async {
    final res = await _api.get<Map<String, dynamic>>(
      '/media/social-summary',
      query: {'platform': platform},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return res.data?['summary']?.toString() ?? '';
  }

  /// Rich, de-duplicated daily summary for a platform
  /// (telegram/twitter/youtube). Returns an empty summary (not an error)
  /// when none has been generated yet, so the UI can simply hide the card.
  Future<PlatformSummary> platformSummary(String platform) async {
    try {
      final res = await _api.get<PlatformSummary>(
        '/media/social-summary',
        query: {'platform': platform},
        decode: (d) => PlatformSummary.fromJson((d as Map).cast<String, dynamic>()),
      );
      return res.data ?? const PlatformSummary();
    } on ApiException catch (e) {
      // 404 = "no summary generated yet" — treat as empty, not an error.
      if (e.code == 'not_found' || e.status == 404) return const PlatformSummary();
      rethrow;
    }
  }

  /// Live analytics for a platform over a range (24h/7d/30d).
  Future<PlatformStats> platformStats(String platform, {String range = '24h'}) async {
    final res = await _api.get<PlatformStats>(
      '/media/stats',
      query: {'platform': platform, 'range': range},
      decode: (d) => PlatformStats.fromJson((d as Map).cast<String, dynamic>()),
    );
    return res.data ?? const PlatformStats();
  }

  Future<({String? audioUrl, int duration, bool cached})> ttsForArticle(int articleId) async {
    final res = await _api.post<Map<String, dynamic>>(
      '/media/tts',
      body: {'article_id': articleId},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return (
      audioUrl: res.data!['audio_url'] as String?,
      duration: (res.data!['duration'] as num?)?.toInt() ?? 0,
      cached: res.data!['cached'] == true,
    );
  }
}

final mediaRepositoryProvider =
    Provider<MediaRepository>((ref) => MediaRepository(ref.watch(apiClientProvider)));

/// Whether the social feeds collapse near-duplicate posts. Toggling it
/// invalidates the feed providers (they watch it), so the list rebuilds
/// with/without de-duplication. On by default.
final hideDuplicatesProvider = StateProvider<bool>((ref) => true);

/// Auto-refreshing social feed providers — poll every 60 seconds.
/// Polling only starts when the provider has active listeners (i.e. the UI is visible).
final telegramFeedProvider = FutureProvider<List<TelegramMessage>>((ref) {
  final dedup = ref.watch(hideDuplicatesProvider);
  final timer = Timer.periodic(const Duration(seconds: 60), (_) {
    ref.invalidateSelf();
  });
  ref.onDispose(timer.cancel);
  return ref.watch(mediaRepositoryProvider).telegram(dedup: dedup);
});

// Twitter polls more aggressively than the other social feeds because
// X has no realtime push and its scraper hits stale caches more often.
// Each poll passes sync:true so the API kicks a real scrape behind its
// shared lock — the lock prevents stampedes across mobile + web users.
final twitterFeedProvider = FutureProvider<List<TwitterMessage>>((ref) {
  final dedup = ref.watch(hideDuplicatesProvider);
  final timer = Timer.periodic(const Duration(seconds: 30), (_) {
    ref.invalidateSelf();
  });
  ref.onDispose(timer.cancel);
  return ref.watch(mediaRepositoryProvider).twitter(sync: true, dedup: dedup);
});

final youtubeFeedProvider = FutureProvider<List<YoutubeVideo>>((ref) {
  final timer = Timer.periodic(const Duration(seconds: 60), (_) {
    ref.invalidateSelf();
  });
  ref.onDispose(timer.cancel);
  return ref.watch(mediaRepositoryProvider).youtube();
});

final reelsProvider =
    FutureProvider<List<ReelItem>>((ref) => ref.watch(mediaRepositoryProvider).reels());

final galleryProvider =
    FutureProvider<GalleryDay>((ref) => ref.watch(mediaRepositoryProvider).gallery());

final newsMapProvider =
    FutureProvider<List<MapPoint>>((ref) => ref.watch(mediaRepositoryProvider).newsMap());

final telegramSummaryProvider = FutureProvider<String>(
    (ref) => ref.watch(mediaRepositoryProvider).socialSummary('telegram'));

final twitterSummaryProvider = FutureProvider<String>(
    (ref) => ref.watch(mediaRepositoryProvider).socialSummary('twitter'));

/// Rich per-platform summary providers (telegram/twitter/youtube),
/// consumed by the collapsible summary card atop each platform tab.
final platformSummaryProvider =
    FutureProvider.family<PlatformSummary, String>((ref, platform) {
  return ref.watch(mediaRepositoryProvider).platformSummary(platform);
});

/// Platform analytics, keyed by (platform, range).
final platformStatsProvider = FutureProvider.family<PlatformStats,
    ({String platform, String range})>((ref, key) {
  return ref.watch(mediaRepositoryProvider).platformStats(key.platform, range: key.range);
});
