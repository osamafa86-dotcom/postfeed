import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:timeago/timeago.dart' as timeago;
import 'package:url_launcher/url_launcher.dart';

import '../../../core/widgets/loading_state.dart';
import '../data/media_repository.dart';

class TwitterScreen extends ConsumerWidget {
  const TwitterScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(twitterFeedProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('تويتر / X')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(twitterFeedProvider)),
        data: (msgs) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(twitterFeedProvider),
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: msgs.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (_, i) {
              final m = msgs[i];
              return InkWell(
                onTap: m.postUrl != null ? () => launchUrl(Uri.parse(m.postUrl!)) : null,
                child: Container(
                  decoration: BoxDecoration(
                    color: Theme.of(context).cardColor,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Theme.of(context).dividerColor),
                  ),
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          if (m.sourceAvatar != null)
                            ClipOval(child: CachedNetworkImage(imageUrl: m.sourceAvatar!, width: 32, height: 32)),
                          if (m.sourceAvatar != null) const SizedBox(width: 8),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(m.sourceName, style: const TextStyle(fontWeight: FontWeight.w700)),
                                if (m.sourceUsername != null)
                                  Text('@${m.sourceUsername}', style: Theme.of(context).textTheme.bodySmall),
                              ],
                            ),
                          ),
                          if (m.postedAt != null)
                            Text(timeago.format(m.postedAt!, locale: 'ar'),
                                style: Theme.of(context).textTheme.bodySmall),
                        ],
                      ),
                      if (m.text.isNotEmpty) Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Text(m.text, style: const TextStyle(height: 1.6)),
                      ),
                      if (m.imageUrl != null) Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(10),
                          child: CachedNetworkImage(imageUrl: m.imageUrl!, fit: BoxFit.cover),
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}
