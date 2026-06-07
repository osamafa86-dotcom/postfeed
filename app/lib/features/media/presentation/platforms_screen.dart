import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:timeago/timeago.dart' as timeago;
import 'package:url_launcher/url_launcher.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/safe_launch.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/media_repository.dart';

class PlatformsScreen extends ConsumerStatefulWidget {
  const PlatformsScreen({super.key});

  @override
  ConsumerState<PlatformsScreen> createState() => _PlatformsScreenState();
}

class _PlatformsScreenState extends ConsumerState<PlatformsScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabCtl;

  @override
  void initState() {
    super.initState();
    _tabCtl = TabController(length: 3, vsync: this);
  }

  @override
  void dispose() {
    _tabCtl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('المنصات'),
        bottom: TabBar(
          controller: _tabCtl,
          indicatorColor: AppColors.primary,
          indicatorWeight: 3,
          labelStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
          unselectedLabelStyle: const TextStyle(fontWeight: FontWeight.w500, fontSize: 14),
          tabs: const [
            Tab(child: Row(mainAxisSize: MainAxisSize.min, children: [
              Text('✈️', style: TextStyle(fontSize: 16)),
              SizedBox(width: 6),
              Text('تلغرام'),
            ])),
            Tab(child: Row(mainAxisSize: MainAxisSize.min, children: [
              Text('𝕏', style: TextStyle(fontSize: 16)),
              SizedBox(width: 6),
              Text('منصة X'),
            ])),
            Tab(child: Row(mainAxisSize: MainAxisSize.min, children: [
              Text('▶️', style: TextStyle(fontSize: 16)),
              SizedBox(width: 6),
              Text('يوتيوب'),
            ])),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabCtl,
        children: [
          _TelegramFeed(),
          _TwitterFeed(),
          _YoutubeFeed(),
        ],
      ),
    );
  }
}

/// Reusable "load older" pagination wrapper for the three social feeds.
/// The auto-refreshing provider gives us the freshest N items; this
/// state lets the user pull older items in batches by passing the
/// smallest currently-shown id as `before_id` to the repo method.
mixin _SocialPaging<T extends ConsumerStatefulWidget> on ConsumerState<T> {
  final List<dynamic> _extra = []; // older items beyond the provider's initial batch
  bool _loadingMore = false;
  bool _hasMore = true;

  void resetExtras() {
    _extra.clear();
    _hasMore = true;
    _loadingMore = false;
  }

  Future<void> doLoadMore({
    required int? beforeId,
    required Future<List<dynamic>> Function(int beforeId) fetch,
  }) async {
    if (_loadingMore || !_hasMore || beforeId == null || beforeId <= 0) return;
    setState(() => _loadingMore = true);
    try {
      final more = await fetch(beforeId);
      if (!mounted) return;
      setState(() {
        if (more.isEmpty) {
          _hasMore = false;
        } else {
          _extra.addAll(more);
          // No "hasMore" flag on the social endpoints — assume there's
          // more until we get back a short batch.
          if (more.length < 30) _hasMore = false;
        }
        _loadingMore = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loadingMore = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذّر تحميل المزيد')),
      );
    }
  }
}

Widget _loadMoreButton({
  required bool loading,
  required bool hasMore,
  required bool hasContent,
  required VoidCallback onTap,
}) {
  return Padding(
    padding: const EdgeInsets.symmetric(vertical: 16),
    child: !hasMore && hasContent
        ? Center(
            child: Text(
              'وصلت لنهاية القائمة',
              style: TextStyle(
                fontSize: 13,
                color: Colors.grey.shade500,
              ),
            ),
          )
        : SizedBox(
            height: 46,
            child: ElevatedButton.icon(
              onPressed: loading ? null : onTap,
              icon: loading
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.expand_more, size: 22),
              label: Text(loading ? 'جارٍ التحميل…' : 'عرض المزيد'),
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
          ),
  );
}

class _TelegramFeed extends ConsumerStatefulWidget {
  @override
  ConsumerState<_TelegramFeed> createState() => _TelegramFeedState();
}

class _TelegramFeedState extends ConsumerState<_TelegramFeed>
    with _SocialPaging<_TelegramFeed> {
  @override
  Widget build(BuildContext context) {
    final asy = ref.watch(telegramFeedProvider);
    // Refresh resets the "load more" cursor.
    ref.listen(telegramFeedProvider, (_, __) => setState(resetExtras));
    return asy.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(telegramFeedProvider)),
      data: (initial) {
        final all = [...initial, ..._extra.cast<TelegramMessage>()];
        return RefreshIndicator(
          onRefresh: () async => ref.invalidate(telegramFeedProvider),
          child: all.isEmpty
              ? const EmptyView(message: 'لا توجد منشورات')
              : ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: all.length + 2,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (_, i) {
                    if (i == 0) {
                      return const _PlatformSummaryCard(
                          platform: 'telegram', accent: Color(0xFF0EA5E9));
                    }
                    final idx = i - 1;
                    if (idx < all.length) {
                      return _MessageCard(msg: all[idx], platformColor: const Color(0xFF0EA5E9), platformName: 'تلغرام');
                    }
                    return _loadMoreButton(
                      loading: _loadingMore,
                      hasMore: _hasMore,
                      hasContent: all.isNotEmpty,
                      onTap: () => doLoadMore(
                        beforeId: all.isNotEmpty ? all.last.id : null,
                        fetch: (b) async =>
                            ref.read(mediaRepositoryProvider).telegram(beforeId: b, limit: 30),
                      ),
                    );
                  },
                ),
        );
      },
    );
  }
}

class _TwitterFeed extends ConsumerStatefulWidget {
  @override
  ConsumerState<_TwitterFeed> createState() => _TwitterFeedState();
}

class _TwitterFeedState extends ConsumerState<_TwitterFeed>
    with _SocialPaging<_TwitterFeed> {
  @override
  Widget build(BuildContext context) {
    final asy = ref.watch(twitterFeedProvider);
    ref.listen(twitterFeedProvider, (_, __) => setState(resetExtras));
    return asy.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(twitterFeedProvider)),
      data: (initial) {
        final all = [...initial, ..._extra.cast<TwitterMessage>()];
        return RefreshIndicator(
          onRefresh: () async => ref.invalidate(twitterFeedProvider),
          child: all.isEmpty
              ? const EmptyView(message: 'لا توجد منشورات')
              : ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: all.length + 2,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (_, i) {
                    if (i == 0) {
                      return const _PlatformSummaryCard(
                          platform: 'twitter', accent: Color(0xFF1F2937));
                    }
                    final idx = i - 1;
                    if (idx < all.length) {
                      return _MessageCard(msg: all[idx], platformColor: const Color(0xFF1F2937), platformName: 'X');
                    }
                    return _loadMoreButton(
                      loading: _loadingMore,
                      hasMore: _hasMore,
                      hasContent: all.isNotEmpty,
                      onTap: () => doLoadMore(
                        beforeId: all.isNotEmpty ? all.last.id : null,
                        fetch: (b) async =>
                            ref.read(mediaRepositoryProvider).twitter(beforeId: b, limit: 30),
                      ),
                    );
                  },
                ),
        );
      },
    );
  }
}

class _YoutubeFeed extends ConsumerStatefulWidget {
  @override
  ConsumerState<_YoutubeFeed> createState() => _YoutubeFeedState();
}

class _YoutubeFeedState extends ConsumerState<_YoutubeFeed>
    with _SocialPaging<_YoutubeFeed> {
  @override
  Widget build(BuildContext context) {
    final asy = ref.watch(youtubeFeedProvider);
    ref.listen(youtubeFeedProvider, (_, __) => setState(resetExtras));
    return asy.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(youtubeFeedProvider)),
      data: (initial) {
        final all = [...initial, ..._extra.cast<YoutubeVideo>()];
        return RefreshIndicator(
          onRefresh: () async => ref.invalidate(youtubeFeedProvider),
          child: all.isEmpty
              ? const EmptyView(message: 'لا توجد فيديوهات')
              : ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: all.length + 2,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (_, i) {
                    if (i == 0) {
                      return const _PlatformSummaryCard(
                          platform: 'youtube', accent: Colors.red);
                    }
                    final idx = i - 1;
                    if (idx < all.length) {
                      return _YoutubeCard(video: all[idx]);
                    }
                    return _loadMoreButton(
                      loading: _loadingMore,
                      hasMore: _hasMore,
                      hasContent: all.isNotEmpty,
                      onTap: () => doLoadMore(
                        beforeId: all.isNotEmpty ? all.last.id : null,
                        fetch: (b) async =>
                            ref.read(mediaRepositoryProvider).youtube(beforeId: b, limit: 30),
                      ),
                    );
                  },
                ),
        );
      },
    );
  }
}

class _MessageCard extends StatelessWidget {
  const _MessageCard({required this.msg, required this.platformColor, required this.platformName});
  final TelegramMessage msg;
  final Color platformColor;
  final String platformName;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      decoration: BoxDecoration(
        color: theme.cardColor,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 12, offset: const Offset(0, 3))],
      ),
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Container(
              width: 36, height: 36,
              decoration: BoxDecoration(color: platformColor.withOpacity(0.15), shape: BoxShape.circle),
              alignment: Alignment.center,
              child: Text(msg.sourceName.isNotEmpty ? msg.sourceName[0] : '?',
                style: TextStyle(color: platformColor, fontWeight: FontWeight.w800, fontSize: 16)),
            ),
            const SizedBox(width: 10),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(msg.sourceName, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
              if (msg.sourceUsername != null)
                Text('@${msg.sourceUsername}', style: theme.textTheme.bodySmall?.copyWith(fontSize: 11)),
            ])),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(color: platformColor, borderRadius: BorderRadius.circular(6)),
              child: Text(platformName, style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w700)),
            ),
            const SizedBox(width: 8),
            if (msg.postedAt != null)
              Text(timeago.format(msg.postedAt!, locale: 'ar'), style: theme.textTheme.bodySmall?.copyWith(fontSize: 11)),
          ]),
          if (msg.text.isNotEmpty)
            Padding(padding: const EdgeInsets.only(top: 10),
              child: Text(msg.text, style: const TextStyle(fontSize: 14, height: 1.6), maxLines: 6, overflow: TextOverflow.ellipsis)),
          if (msg.postUrl != null)
            Padding(padding: const EdgeInsets.only(top: 8),
              child: GestureDetector(
                onTap: () => safeLaunch(context, msg.postUrl!),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.open_in_new, size: 14, color: platformColor),
                  const SizedBox(width: 4),
                  Text('فتح المنشور', style: TextStyle(color: platformColor, fontSize: 13, fontWeight: FontWeight.w600)),
                ]),
              )),
        ],
      ),
    );
  }
}

class _YoutubeCard extends StatelessWidget {
  const _YoutubeCard({required this.video});
  final YoutubeVideo video;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return GestureDetector(
      onTap: () => safeLaunch(context, video.videoUrl),
      child: Container(
        decoration: BoxDecoration(
          color: theme.cardColor,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 12, offset: const Offset(0, 3))],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (video.thumbnailUrl != null)
              Stack(children: [
                ClipRRect(
                  borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
                  child: AspectRatio(
                    aspectRatio: 16 / 9,
                    child: CachedNetworkImage(imageUrl: video.thumbnailUrl!, fit: BoxFit.cover),
                  ),
                ),
                Positioned(
                  left: 8, bottom: 8,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                    decoration: BoxDecoration(color: Colors.black87, borderRadius: BorderRadius.circular(4)),
                    child: Text(_formatDuration(video.durationSeconds),
                      style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600)),
                  ),
                ),
                const Positioned(
                  right: 0, left: 0, top: 0, bottom: 0,
                  child: Center(child: Icon(Icons.play_circle_fill, size: 48, color: Colors.white70)),
                ),
              ]),
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(video.title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14, height: 1.4), maxLines: 2, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 6),
                Row(children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                    decoration: BoxDecoration(color: Colors.red, borderRadius: BorderRadius.circular(4)),
                    child: const Text('يوتيوب', style: TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.w700)),
                  ),
                  const SizedBox(width: 6),
                  Text(video.sourceName, style: theme.textTheme.bodySmall?.copyWith(fontSize: 12)),
                  const Spacer(),
                  if (video.postedAt != null)
                    Text(timeago.format(video.postedAt!, locale: 'ar'), style: theme.textTheme.bodySmall?.copyWith(fontSize: 11)),
                ]),
              ]),
            ),
          ],
        ),
      ),
    );
  }

  String _formatDuration(int seconds) {
    final m = seconds ~/ 60;
    final s = seconds % 60;
    return '${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
  }
}

/// Collapsible "ملخص اليوم" card shown at the top of each platform tab.
/// Renders the rich, de-duplicated daily AI summary (headline, paragraph,
/// key numbers, deep-dive sections, topics). Hides itself entirely while
/// loading, on error, or when no summary has been generated yet.
class _PlatformSummaryCard extends ConsumerStatefulWidget {
  const _PlatformSummaryCard({required this.platform, required this.accent});
  final String platform;
  final Color accent;

  @override
  ConsumerState<_PlatformSummaryCard> createState() => _PlatformSummaryCardState();
}

class _PlatformSummaryCardState extends ConsumerState<_PlatformSummaryCard> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final asy = ref.watch(platformSummaryProvider(widget.platform));
    return asy.maybeWhen(
      orElse: () => const SizedBox.shrink(),
      data: (s) {
        if (s.isEmpty) return const SizedBox.shrink();
        final theme = Theme.of(context);
        return Container(
          margin: const EdgeInsets.only(bottom: 4),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topRight,
              end: Alignment.bottomLeft,
              colors: [widget.accent.withOpacity(0.10), widget.accent.withOpacity(0.02)],
            ),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: widget.accent.withOpacity(0.25)),
          ),
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Text('🧠', style: TextStyle(fontSize: 18, color: widget.accent)),
                const SizedBox(width: 6),
                Text('ملخص اليوم',
                    style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: widget.accent)),
                const Spacer(),
                if (s.generatedAt != null)
                  Text(timeago.format(s.generatedAt!, locale: 'ar'),
                      style: theme.textTheme.bodySmall?.copyWith(fontSize: 11)),
              ]),
              if (s.headline.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(s.headline,
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, height: 1.4)),
                ),
              if (s.summary.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    s.summary,
                    style: const TextStyle(fontSize: 13.5, height: 1.7),
                    maxLines: _expanded ? null : 3,
                    overflow: _expanded ? TextOverflow.visible : TextOverflow.ellipsis,
                  ),
                ),
              if (_expanded) ..._buildExpanded(context, s),
              const SizedBox(height: 6),
              Row(children: [
                if (s.messageCount > 0)
                  Text('📡 جُمِّع من ${s.messageCount} منشور بلا تكرار',
                      style: theme.textTheme.bodySmall?.copyWith(fontSize: 11)),
                const Spacer(),
                TextButton.icon(
                  onPressed: () => setState(() => _expanded = !_expanded),
                  icon: Icon(_expanded ? Icons.expand_less : Icons.expand_more,
                      size: 20, color: widget.accent),
                  label: Text(_expanded ? 'طيّ' : 'الملخص الكامل',
                      style: TextStyle(color: widget.accent, fontWeight: FontWeight.w700, fontSize: 13)),
                  style: TextButton.styleFrom(
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    minimumSize: const Size(0, 32),
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                  ),
                ),
              ]),
            ],
          ),
        );
      },
    );
  }

  List<Widget> _buildExpanded(BuildContext context, PlatformSummary s) {
    final theme = Theme.of(context);
    return [
      // Key numbers row.
      if (s.keyNumbers.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: s.keyNumbers
                .map((n) => Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: widget.accent.withOpacity(0.10),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(n.value,
                              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: widget.accent)),
                          Text(n.context,
                              style: theme.textTheme.bodySmall?.copyWith(fontSize: 10.5)),
                        ],
                      ),
                    ))
                .toList(),
          ),
        ),
      // Deep-dive sections.
      for (final sec in s.sections)
        Padding(
          padding: const EdgeInsets.only(top: 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                if (sec.icon.isNotEmpty) Text(sec.icon, style: const TextStyle(fontSize: 16)),
                if (sec.icon.isNotEmpty) const SizedBox(width: 6),
                Expanded(
                  child: Text(sec.title,
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
                ),
              ]),
              for (final item in sec.items)
                Padding(
                  padding: const EdgeInsets.only(top: 5, right: 4),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('• ', style: TextStyle(color: widget.accent, fontWeight: FontWeight.w900)),
                    Expanded(
                      child: Text(item, style: const TextStyle(fontSize: 13, height: 1.6)),
                    ),
                  ]),
                ),
              if (sec.whyMatters.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('لماذا يهم؟ ${sec.whyMatters}',
                      style: theme.textTheme.bodySmall
                          ?.copyWith(fontStyle: FontStyle.italic, fontSize: 11.5)),
                ),
            ],
          ),
        ),
      // Topics.
      if (s.topics.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(top: 14),
          child: Wrap(
            spacing: 6,
            runSpacing: 6,
            children: s.topics
                .map((t) => Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: theme.cardColor,
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: widget.accent.withOpacity(0.3)),
                      ),
                      child: Text('#$t',
                          style: TextStyle(fontSize: 11.5, color: widget.accent, fontWeight: FontWeight.w600)),
                    ))
                .toList(),
          ),
        ),
    ];
  }
}
