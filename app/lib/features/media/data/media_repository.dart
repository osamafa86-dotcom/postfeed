import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';

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
  });

  final int id;
  final String text;
  final String? imageUrl;
  final String? postUrl;
  final DateTime? postedAt;
  final String sourceName;
  final String? sourceUsername;
  final String? sourceAvatar;

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

class MediaRepository {
  MediaRepository(this._api);
  final ApiClient _api;

  Future<List<TelegramMessage>> telegram({int? sinceId, int limit = 30}) async {
    final res = await _api.get<List<TelegramMessage>>(
      '/media/telegram',
      query: {'limit': limit, if (sinceId != null) 'since_id': sinceId},
      decode: (d) => (d as List)
          .whereType<Map>()
          .map((m) => TelegramMessage.fromJson(m.cast()))
          .toList(),
    );
    return res.data ?? const [];
  }

  Future<List<TwitterMessage>> twitter({int? sinceId, int limit = 30}) async {
    final res = await _api.get<List<TwitterMessage>>(
      '/media/twitter',
      query: {'limit': limit, if (sinceId != null) 'since_id': sinceId},
      decode: (d) => (d as List)
          .whereType<Map>()
          .map((m) => TwitterMessage.fromJson(m.cast()))
          .toList(),
    );
    return res.data ?? const [];
  }

  Future<List<YoutubeVideo>> youtube({int? sinceId, int limit = 30}) async {
    final res = await _api.get<List<YoutubeVideo>>(
      '/media/youtube',
      query: {'limit': limit, if (sinceId != null) 'since_id': sinceId},
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
  Future<String> socialSummary(String platform) async {
    final res = await _api.get<Map<String, dynamic>>(
      '/media/social-summary',
      query: {'platform': platform},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return res.data?['summary']?.toString() ?? '';
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

/// Auto-refreshing social feed providers — poll every 60 seconds.
/// Polling only starts when the provider has active listeners (i.e. the UI is visible).
final telegramFeedProvider = FutureProvider<List<TelegramMessage>>((ref) {
  final timer = Timer.periodic(const Duration(seconds: 60), (_) {
    ref.invalidateSelf();
  });
  ref.onDispose(timer.cancel);
  return ref.watch(mediaRepositoryProvider).telegram();
});

final twitterFeedProvider = FutureProvider<List<TwitterMessage>>((ref) {
  final timer = Timer.periodic(const Duration(seconds: 60), (_) {
    ref.invalidateSelf();
  });
  ref.onDispose(timer.cancel);
  return ref.watch(mediaRepositoryProvider).twitter();
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
