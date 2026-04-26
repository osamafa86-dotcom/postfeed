import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:timeago/timeago.dart' as timeago;
import 'package:url_launcher/url_launcher.dart';

import '../../../core/widgets/loading_state.dart';
import '../data/media_repository.dart';

class TelegramScreen extends ConsumerWidget {
  const TelegramScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(telegramFeedProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('تيليجرام')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(telegramFeedProvider)),
        data: (msgs) => RefreshIndicator(
          onRefresh: () async => ref.invalidate(telegramFeedProvider),
          child: msgs.isEmpty
              ? const EmptyView(message: 'لا توجد رسائل')
              : ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: msgs.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (_, i) {
                    final m = msgs[i];
                    return Container(
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
                                ClipOval(
                                  child: CachedNetworkImage(
                                    imageUrl: m.sourceAvatar!,
                                    width: 28, height: 28, fit: BoxFit.cover,
                                  ),
                                ),
                              if (m.sourceAvatar != null) const SizedBox(width: 8),
                              Expanded(child: Text(m.sourceName, style: const TextStyle(fontWeight: FontWeight.w700))),
                              if (m.postedAt != null)
                                Text(timeago.format(m.postedAt!, locale: 'ar'),
                                    style: Theme.of(context).textTheme.bodySmall),
                            ],
                          ),
                          if (m.text.isNotEmpty) Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: Text(m.text),
                          ),
                          if (m.imageUrl != null) Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: CachedNetworkImage(imageUrl: m.imageUrl!, fit: BoxFit.cover),
                            ),
                          ),
                          if (m.postUrl != null) Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: TextButton.icon(
                              icon: const Icon(Icons.open_in_new, size: 16),
                              label: const Text('فتح في تيليجرام'),
                              onPressed: () => launchUrl(Uri.parse(m.postUrl!)),
                            ),
                          ),
                        ],
                      ),
                    );
                  },
                ),
        ),
      ),
    );
  }
}
