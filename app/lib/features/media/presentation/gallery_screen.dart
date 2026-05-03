import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/loading_state.dart';
import '../data/media_repository.dart';

String? _photoUrl(Map<String, dynamic> p) {
  final url = p['image_url'] as String? ?? p['url'] as String?;
  if (url == null || url.isEmpty) return null;
  return url;
}

class GalleryScreen extends ConsumerWidget {
  const GalleryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(galleryProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('معرض الصور')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(galleryProvider)),
        data: (g) => ListView(
          padding: EdgeInsets.zero,
          children: [
            if (g.headline != null) Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
              child: Text(g.headline!, style: Theme.of(context).textTheme.headlineMedium),
            ),
            if (g.intro != null) Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
              child: Text(g.intro!, style: Theme.of(context).textTheme.bodyLarge),
            ),
            for (final p in g.photos)
              if (_photoUrl(p) != null) Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(10),
                      child: CachedNetworkImage(
                        imageUrl: _photoUrl(p)!,
                        fit: BoxFit.cover,
                        width: double.infinity,
                      ),
                    ),
                  if ((p['caption'] ?? '').toString().isNotEmpty) Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(p['caption'].toString(),
                        style: Theme.of(context).textTheme.bodyMedium),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }
}
