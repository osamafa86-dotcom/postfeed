import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';

import '../../../core/widgets/loading_state.dart';
import '../../media/data/media_repository.dart';

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
        data: (points) => FlutterMap(
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
                      onTap: p.articleId != null
                          ? () => context.push('/article/${p.articleId}')
                          : null,
                      child: const Icon(Icons.location_on, color: Colors.red, size: 30),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
