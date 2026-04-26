import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:just_audio/just_audio.dart';
import 'package:just_audio_background/just_audio_background.dart';

import '../../../core/models/podcast_episode.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/podcast_repository.dart';

final _playerProvider = Provider<AudioPlayer>((ref) {
  final p = AudioPlayer();
  ref.onDispose(p.dispose);
  return p;
});

class PodcastScreen extends ConsumerWidget {
  const PodcastScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final latest = ref.watch(latestEpisodeProvider);
    final episodes = ref.watch(episodesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('البودكاست')),
      body: latest.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(latestEpisodeProvider)),
        data: (ep) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(latestEpisodeProvider);
            ref.invalidate(episodesProvider);
          },
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _LatestCard(episode: ep),
              const SizedBox(height: 20),
              Text('الأرشيف', style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 8),
              episodes.when(
                loading: () => const LoadingShimmerList(itemCount: 3),
                error: (e, _) => Text('$e'),
                data: (list) => Column(
                  children: [
                    for (final e in list) _EpisodeTile(episode: e),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LatestCard extends ConsumerStatefulWidget {
  const _LatestCard({required this.episode});
  final PodcastEpisode episode;
  @override
  ConsumerState<_LatestCard> createState() => _LatestCardState();
}

class _LatestCardState extends ConsumerState<_LatestCard> {
  bool _loaded = false;

  Future<void> _load() async {
    if (_loaded || widget.episode.audioUrl == null) return;
    final player = ref.read(_playerProvider);
    await player.setAudioSource(
      AudioSource.uri(
        Uri.parse(widget.episode.audioUrl!),
        tag: MediaItem(
          id: widget.episode.id.toString(),
          album: 'فيد نيوز — البودكاست',
          title: widget.episode.title,
          artUri: null,
          duration: Duration(seconds: widget.episode.durationSeconds),
        ),
      ),
    );
    _loaded = true;
  }

  @override
  Widget build(BuildContext context) {
    final player = ref.watch(_playerProvider);
    final ep = widget.episode;

    return Container(
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Theme.of(context).dividerColor),
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(ep.title, style: Theme.of(context).textTheme.headlineSmall),
          if (ep.subtitle.isNotEmpty) Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(ep.subtitle, style: Theme.of(context).textTheme.bodyMedium),
          ),
          const SizedBox(height: 14),
          StreamBuilder<PlayerState>(
            stream: player.playerStateStream,
            builder: (_, s) {
              final playing = s.data?.playing ?? false;
              return Row(
                children: [
                  IconButton.filled(
                    iconSize: 32,
                    icon: Icon(playing ? Icons.pause : Icons.play_arrow),
                    onPressed: ep.audioUrl == null ? null : () async {
                      await _load();
                      if (playing) player.pause(); else player.play();
                    },
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: StreamBuilder<Duration?>(
                      stream: player.durationStream,
                      builder: (_, durSnap) {
                        final dur = durSnap.data ?? Duration(seconds: ep.durationSeconds);
                        return StreamBuilder<Duration>(
                          stream: player.positionStream,
                          builder: (_, posSnap) {
                            final pos = posSnap.data ?? Duration.zero;
                            return Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Slider(
                                  value: pos.inSeconds.toDouble().clamp(0, dur.inSeconds.toDouble()),
                                  max: dur.inSeconds.toDouble().clamp(1, double.infinity),
                                  onChanged: (v) => player.seek(Duration(seconds: v.toInt())),
                                ),
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Text(_fmt(pos), style: Theme.of(context).textTheme.bodySmall),
                                    Text(_fmt(dur), style: Theme.of(context).textTheme.bodySmall),
                                  ],
                                ),
                              ],
                            );
                          },
                        );
                      },
                    ),
                  ),
                ],
              );
            },
          ),
          if (ep.intro.isNotEmpty) Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Text(ep.intro, style: const TextStyle(height: 1.7)),
          ),
        ],
      ),
    );
  }

  String _fmt(Duration d) {
    final m = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    final h = d.inHours;
    return h > 0 ? '$h:$m:$s' : '$m:$s';
  }
}

class _EpisodeTile extends StatelessWidget {
  const _EpisodeTile({required this.episode});
  final PodcastEpisode episode;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const CircleAvatar(child: Icon(Icons.headphones)),
      title: Text(episode.title, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: Text(episode.date),
      trailing: Text('${episode.durationSeconds ~/ 60} د'),
    );
  }
}
