import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/widgets/loading_state.dart';
import '../data/media_repository.dart';

class YoutubeScreen extends ConsumerWidget {
  const YoutubeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(youtubeFeedProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('يوتيوب')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(youtubeFeedProvider)),
        data: (vids) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(youtubeFeedProvider),
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: vids.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (_, i) {
              final v = vids[i];
              return InkWell(
                onTap: () => launchUrl(Uri.parse(v.videoUrl)),
                borderRadius: BorderRadius.circular(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (v.thumbnailUrl != null)
                      ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: AspectRatio(
                          aspectRatio: 16 / 9,
                          child: Stack(
                            fit: StackFit.expand,
                            children: [
                              CachedNetworkImage(imageUrl: v.thumbnailUrl!, fit: BoxFit.cover),
                              const Center(
                                child: CircleAvatar(
                                  radius: 24,
                                  backgroundColor: Color(0xFFB91C1C),
                                  child: Icon(Icons.play_arrow, color: Colors.white, size: 28),
                                ),
                              ),
                              if (v.durationSeconds > 0)
                                Positioned(
                                  bottom: 6, left: 6,
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                    color: Colors.black.withOpacity(0.7),
                                    child: Text(_fmtDur(v.durationSeconds),
                                        style: const TextStyle(color: Colors.white, fontSize: 12)),
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                    const SizedBox(height: 8),
                    Text(v.title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                    const SizedBox(height: 4),
                    Text(v.sourceName, style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              );
            },
          ),
        ),
      ),
    );
  }

  String _fmtDur(int s) {
    final h = s ~/ 3600, m = (s % 3600) ~/ 60, sec = s % 60;
    if (h > 0) return '$h:${m.toString().padLeft(2, '0')}:${sec.toString().padLeft(2, '0')}';
    return '$m:${sec.toString().padLeft(2, '0')}';
  }
}
