import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/widgets/loading_state.dart';
import '../data/media_repository.dart';

class ReelsScreen extends ConsumerWidget {
  const ReelsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(reelsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('ريلز')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(reelsProvider)),
        data: (items) => GridView.builder(
          padding: const EdgeInsets.all(8),
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 2,
            childAspectRatio: 9 / 14,
            crossAxisSpacing: 8,
            mainAxisSpacing: 8,
          ),
          itemCount: items.length,
          itemBuilder: (_, i) {
            final r = items[i];
            return InkWell(
              onTap: () => launchUrl(Uri.parse(r.url)),
              borderRadius: BorderRadius.circular(12),
              child: Stack(
                fit: StackFit.expand,
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(12),
                    child: r.thumbnailUrl != null
                        ? CachedNetworkImage(imageUrl: r.thumbnailUrl!, fit: BoxFit.cover)
                        : Container(color: Colors.grey.shade300),
                  ),
                  Positioned.fill(
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(12),
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [Colors.transparent, Colors.black.withOpacity(0.7)],
                        ),
                      ),
                    ),
                  ),
                  const Center(
                    child: Icon(Icons.play_circle_fill, color: Colors.white, size: 56),
                  ),
                  if ((r.caption ?? '').isNotEmpty)
                    Positioned(
                      bottom: 8, left: 8, right: 8,
                      child: Text(
                        r.caption!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                      ),
                    ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}
