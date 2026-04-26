import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/models/podcast_episode.dart';

class PodcastRepository {
  PodcastRepository(this._api);
  final ApiClient _api;

  Future<List<PodcastEpisode>> episodes({int page = 1, int limit = 30}) async {
    final res = await _api.get<List<PodcastEpisode>>(
      '/media/podcast/episodes',
      query: {'page': page, 'limit': limit},
      decode: (d) => (d as List)
          .whereType<Map>()
          .map((m) => PodcastEpisode.fromJson(m.cast()))
          .toList(),
    );
    return res.data ?? const [];
  }

  Future<PodcastEpisode> latest() async {
    final res = await _api.get<PodcastEpisode>(
      '/media/podcast/latest',
      decode: (d) => PodcastEpisode.fromJson((d as Map).cast()),
    );
    return res.data!;
  }

  Future<PodcastEpisode> byDate(String date) async {
    final res = await _api.get<PodcastEpisode>(
      '/media/podcast/episode',
      query: {'date': date},
      decode: (d) => PodcastEpisode.fromJson((d as Map).cast()),
    );
    return res.data!;
  }
}

final podcastRepositoryProvider =
    Provider<PodcastRepository>((ref) => PodcastRepository(ref.watch(apiClientProvider)));

final latestEpisodeProvider = FutureProvider<PodcastEpisode>((ref) {
  return ref.watch(podcastRepositoryProvider).latest();
});

final episodesProvider = FutureProvider<List<PodcastEpisode>>((ref) {
  return ref.watch(podcastRepositoryProvider).episodes();
});
