import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';

import '../../../core/widgets/loading_state.dart';
import '../../media/data/media_repository.dart';

void _showPointSheet(BuildContext context, MapPoint p) {
  showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    builder: (_) => Padding(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (p.place.isNotEmpty)
            Row(children: [
              const Icon(Icons.location_on, size: 18, color: Colors.red),
              const SizedBox(width: 6),
              Text(p.place,
                  style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
            ]),
          if (p.articleTitle != null && p.articleTitle!.isNotEmpty) ...[
            const SizedBox(height: 12),
            Text(p.articleTitle!, style: const TextStyle(fontSize: 14, height: 1.5)),
          ],
          if (p.articleId != null) ...[
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () {
                  Navigator.of(context).pop();
                  context.push('/article/${p.articleId}');
                },
                child: const Text('فتح الخبر'),
              ),
            ),
          ],
        ],
      ),
    ),
  );
}

class NewsMapScreen extends ConsumerWidget {
  const NewsMapScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(newsMapProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('خريطة الأخبار')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(newsMapProvider)),
        data: (points) => Stack(
          children: [
            FlutterMap(
              options: const MapOptions(
                initialCenter: LatLng(31.78, 35.21), // Jerusalem
                initialZoom: 6,
              ),
              children: [
                TileLayer(
                  urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                  userAgentPackageName: 'net.feedsnews.app',
                ),
                MarkerLayer(
                  markers: [
                    for (final p in points)
                      Marker(
                        point: LatLng(p.lat, p.lng),
                        width: 30, height: 30,
                        child: GestureDetector(
                          // Tap shows place + article. Even when there's
                          // no article_id, surface the location info in a
                          // bottom sheet rather than ignore the tap.
                          onTap: () => _showPointSheet(context, p),
                          child: const Icon(Icons.location_on, color: Colors.red, size: 30),
                        ),
                      ),
                  ],
                ),
              ],
            ),
            if (points.isEmpty)
              const Center(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: EmptyView(
                    icon: Icons.public,
                    message: 'لا توجد علامات على الخريطة بعد',
                    hint: 'يتم وسم الأخبار بمواقعها تلقائياً — تابعنا لاحقاً.',
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
