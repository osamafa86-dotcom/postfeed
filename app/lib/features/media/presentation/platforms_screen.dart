import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:timeago/timeago.dart' as timeago;

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/safe_launch.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/media_repository.dart';

// Per-platform brand accents (match the web /platforms redesign).
const _telegramAccent = Color(0xFF0EA5E9);
const _twitterAccent = Color(0xFF1F2937);
const _youtubeAccent = Color(0xFFDC2626);

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
    // Keep the segmented control in sync with swipes / programmatic moves.
    _tabCtl.addListener(() {
      if (mounted) setState(() {});
    });
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
        bottom: _segTabs(context),
      ),
      body: TabBarView(
        controller: _tabCtl,
        children: const [
          _TelegramFeed(),
          _TwitterFeed(),
          _YoutubeFeed(),
        ],
      ),
    );
  }

  // Segmented pill tabs (replaces the Material TabBar) — matches Figma.
  PreferredSizeWidget _segTabs(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    const labels = [('✈️', 'تلغرام'), ('𝕏', 'منصة X'), ('▶️', 'يوتيوب')];
    const accents = [_telegramAccent, _twitterAccent, _youtubeAccent];
    return PreferredSize(
      preferredSize: const Size.fromHeight(62),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 6, 16, 12),
        child: Row(
          children: List.generate(3, (i) {
            final active = _tabCtl.index == i;
            final ink = AppColors.accentInk(accents[i], isDark);
            return Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: GestureDetector(
                  onTap: () => _tabCtl.animateTo(i),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 150),
                    height: 44,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: active ? ink : theme.cardColor,
                      borderRadius: BorderRadius.circular(14),
                      border: active ? null : Border.all(color: theme.dividerColor),
                    ),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      Text(labels[i].$1, style: const TextStyle(fontSize: 15)),
                      const SizedBox(width: 6),
                      Text(labels[i].$2,
                          style: TextStyle(
                              fontWeight: FontWeight.w800,
                              fontSize: 13.5,
                              color: active ? Colors.white : theme.textTheme.bodyLarge?.color)),
                    ]),
                  ),
                ),
              ),
            );
          }),
        ),
      ),
    );
  }
}

/// Reusable "load older" pagination wrapper for the three social feeds.
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
              style: TextStyle(fontSize: 13, color: Colors.grey.shade500),
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

/// "أحدث المنشورات" header with an inline de-duplication toggle (matches Figma).
Widget _feedBar(BuildContext context, WidgetRef ref,
    {required bool showToggle, required bool hideDup, required String label}) {
  final theme = Theme.of(context);
  return Padding(
    padding: const EdgeInsets.only(top: 2, bottom: 2),
    child: Row(children: [
      Text(label,
          style: TextStyle(
              fontWeight: FontWeight.w900,
              fontSize: 16,
              color: theme.textTheme.titleLarge?.color)),
      const Spacer(),
      if (showToggle) ...[
        Text('إخفاء المكرر', style: theme.textTheme.bodySmall?.copyWith(fontSize: 12)),
        const SizedBox(width: 4),
        Switch.adaptive(
          value: hideDup,
          onChanged: (v) => ref.read(hideDuplicatesProvider.notifier).state = v,
        ),
      ],
    ]),
  );
}

class _TelegramFeed extends ConsumerStatefulWidget {
  const _TelegramFeed();
  @override
  ConsumerState<_TelegramFeed> createState() => _TelegramFeedState();
}

class _TelegramFeedState extends ConsumerState<_TelegramFeed>
    with _SocialPaging<_TelegramFeed> {
  @override
  Widget build(BuildContext context) {
    final asy = ref.watch(telegramFeedProvider);
    final hideDup = ref.watch(hideDuplicatesProvider);
    ref.listen(telegramFeedProvider, (_, __) => setState(resetExtras));
    return asy.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(telegramFeedProvider)),
      data: (initial) {
        final all = [...initial, ..._extra.cast<TelegramMessage>()];
        return RefreshIndicator(
          onRefresh: () async => ref.invalidate(telegramFeedProvider),
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: all.length + 4,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (_, i) {
              if (i == 0) return const _PlatformSummaryCard(platform: 'telegram', accent: _telegramAccent);
              if (i == 1) return const _PlatformStatsCard(platform: 'telegram', accent: _telegramAccent);
              if (i == 2) return _feedBar(context, ref, showToggle: true, hideDup: hideDup, label: 'أحدث المنشورات');
              final idx = i - 3;
              if (idx < all.length) {
                return _MessageCard(msg: all[idx], platformColor: _telegramAccent, platformName: 'تلغرام');
              }
              if (all.isEmpty) return const SizedBox.shrink();
              return _loadMoreButton(
                loading: _loadingMore,
                hasMore: _hasMore,
                hasContent: all.isNotEmpty,
                onTap: () => doLoadMore(
                  beforeId: all.isNotEmpty ? all.last.id : null,
                  fetch: (b) async => ref.read(mediaRepositoryProvider).telegram(
                      beforeId: b, limit: 30, dedup: ref.read(hideDuplicatesProvider)),
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
  const _TwitterFeed();
  @override
  ConsumerState<_TwitterFeed> createState() => _TwitterFeedState();
}

class _TwitterFeedState extends ConsumerState<_TwitterFeed>
    with _SocialPaging<_TwitterFeed> {
  @override
  Widget build(BuildContext context) {
    final asy = ref.watch(twitterFeedProvider);
    final hideDup = ref.watch(hideDuplicatesProvider);
    ref.listen(twitterFeedProvider, (_, __) => setState(resetExtras));
    return asy.when(
      loading: () => const LoadingShimmerList(),
      error: (e, _) => ErrorRetryView(message: '$e', onRetry: () => ref.invalidate(twitterFeedProvider)),
      data: (initial) {
        final all = [...initial, ..._extra.cast<TwitterMessage>()];
        return RefreshIndicator(
          onRefresh: () async => ref.invalidate(twitterFeedProvider),
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: all.length + 4,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (_, i) {
              if (i == 0) return const _PlatformSummaryCard(platform: 'twitter', accent: _twitterAccent);
              if (i == 1) return const _PlatformStatsCard(platform: 'twitter', accent: _twitterAccent);
              if (i == 2) return _feedBar(context, ref, showToggle: true, hideDup: hideDup, label: 'أحدث المنشورات');
              final idx = i - 3;
              if (idx < all.length) {
                return _MessageCard(msg: all[idx], platformColor: _twitterAccent, platformName: 'X');
              }
              if (all.isEmpty) return const SizedBox.shrink();
              return _loadMoreButton(
                loading: _loadingMore,
                hasMore: _hasMore,
                hasContent: all.isNotEmpty,
                onTap: () => doLoadMore(
                  beforeId: all.isNotEmpty ? all.last.id : null,
                  fetch: (b) async => ref.read(mediaRepositoryProvider).twitter(
                      beforeId: b, limit: 30, dedup: ref.read(hideDuplicatesProvider)),
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
  const _YoutubeFeed();
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
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: all.length + 4,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (_, i) {
              if (i == 0) return const _PlatformSummaryCard(platform: 'youtube', accent: _youtubeAccent);
              if (i == 1) return const _PlatformStatsCard(platform: 'youtube', accent: _youtubeAccent);
              if (i == 2) return _feedBar(context, ref, showToggle: false, hideDup: false, label: 'أحدث الفيديوهات');
              final idx = i - 3;
              if (idx < all.length) {
                return _YoutubeCard(video: all[idx]);
              }
              if (all.isEmpty) return const SizedBox.shrink();
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
    final isDark = theme.brightness == Brightness.dark;
    final ink = AppColors.accentInk(platformColor, isDark);
    return Container(
      decoration: BoxDecoration(
        color: theme.cardColor,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: theme.dividerColor.withOpacity(0.6)),
        boxShadow: [BoxShadow(color: Colors.black.withOpacity(isDark ? 0.32 : 0.05), blurRadius: 16, offset: const Offset(0, 6))],
      ),
      padding: const EdgeInsets.all(15),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Container(
              width: 42, height: 42,
              decoration: BoxDecoration(color: ink, shape: BoxShape.circle),
              alignment: Alignment.center,
              child: Text(msg.sourceName.isNotEmpty ? msg.sourceName[0] : '؟',
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 17)),
            ),
            const SizedBox(width: 10),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(msg.sourceName, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5)),
              if (msg.sourceUsername != null)
                Text('@${msg.sourceUsername}', style: theme.textTheme.bodySmall?.copyWith(fontSize: 11)),
            ])),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(color: ink, borderRadius: BorderRadius.circular(7)),
              child: Text(platformName, style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w700)),
            ),
            const SizedBox(width: 8),
            if (msg.postedAt != null)
              Text(timeago.format(msg.postedAt!, locale: 'ar'), style: theme.textTheme.bodySmall?.copyWith(fontSize: 11)),
          ]),
          if (msg.text.isNotEmpty)
            Padding(padding: const EdgeInsets.only(top: 11),
              child: Text(msg.text, style: const TextStyle(fontSize: 15, height: 1.7), maxLines: 6, overflow: TextOverflow.ellipsis)),
          if (msg.duplicateCount > 0)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Tooltip(
                message: msg.alsoReportedBy.isNotEmpty
                    ? 'أيضاً: ${msg.alsoReportedBy.join('، ')}'
                    : '',
                triggerMode: TooltipTriggerMode.tap,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
                  decoration: BoxDecoration(
                    color: ink.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(9),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(Icons.dynamic_feed, size: 14, color: ink),
                    const SizedBox(width: 4),
                    Text('ورد من ${msg.duplicateCount + 1} مصدر',
                        style: TextStyle(color: ink, fontSize: 12, fontWeight: FontWeight.w700)),
                  ]),
                ),
              ),
            ),
          if (msg.postUrl != null)
            Padding(padding: const EdgeInsets.only(top: 10),
              child: GestureDetector(
                onTap: () => safeLaunch(context, msg.postUrl!),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.open_in_new, size: 14, color: ink),
                  const SizedBox(width: 4),
                  Text('فتح المنشور', style: TextStyle(color: ink, fontSize: 13, fontWeight: FontWeight.w700)),
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
                    decoration: BoxDecoration(color: _youtubeAccent, borderRadius: BorderRadius.circular(4)),
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

/// "ملخص اليوم" — white card with an accent strip; key numbers and topics are
/// always visible, deep-dive sections expand on demand. Hidden while loading,
/// on error, or when no summary exists yet.
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
        final isDark = theme.brightness == Brightness.dark;
        final ink = AppColors.accentInk(widget.accent, isDark);
        return Container(
          decoration: BoxDecoration(
            color: theme.cardColor,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: theme.dividerColor.withOpacity(0.6)),
            boxShadow: [BoxShadow(color: Colors.black.withOpacity(isDark ? 0.32 : 0.05), blurRadius: 16, offset: const Offset(0, 6))],
          ),
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Accent strip
              Container(
                height: 4, width: 54,
                margin: const EdgeInsets.only(bottom: 12),
                decoration: BoxDecoration(color: widget.accent, borderRadius: BorderRadius.circular(4)),
              ),
              // Head
              Row(children: [
                const Text('🧠', style: TextStyle(fontSize: 18)),
                const SizedBox(width: 6),
                Text('ملخص اليوم',
                    style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: ink)),
                const Spacer(),
                if (s.generatedAt != null)
                  Text(timeago.format(s.generatedAt!, locale: 'ar'),
                      style: theme.textTheme.bodySmall?.copyWith(fontSize: 11)),
              ]),
              if (s.headline.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(s.headline,
                      style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17, height: 1.45)),
                ),
              if (s.summary.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    s.summary,
                    style: TextStyle(fontSize: 14, height: 1.85, color: theme.textTheme.bodyMedium?.color),
                    maxLines: _expanded ? null : 5,
                    overflow: _expanded ? TextOverflow.visible : TextOverflow.ellipsis,
                  ),
                ),
              // Key numbers (always visible)
              if (s.keyNumbers.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: s.keyNumbers
                        .map((n) => Container(
                              padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
                              decoration: BoxDecoration(
                                color: ink.withOpacity(0.12),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(n.value,
                                      style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15, color: ink)),
                                  Text(n.context,
                                      style: theme.textTheme.bodySmall?.copyWith(fontSize: 10.5)),
                                ],
                              ),
                            ))
                        .toList(),
                  ),
                ),
              // Deep-dive sections (expand)
              if (_expanded) ..._buildSections(context, s, ink, theme),
              // Topics (always visible)
              if (s.topics.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: s.topics
                        .map((t) => Container(
                              padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
                              decoration: BoxDecoration(
                                borderRadius: BorderRadius.circular(20),
                                border: Border.all(color: ink.withOpacity(0.4)),
                              ),
                              child: Text('#$t',
                                  style: TextStyle(fontSize: 12, color: ink, fontWeight: FontWeight.w700)),
                            ))
                        .toList(),
                  ),
                ),
              // Foot
              const SizedBox(height: 14),
              Row(children: [
                if (s.messageCount > 0)
                  Expanded(
                    child: Text('📡 جُمِّع من ${s.messageCount} منشور بلا تكرار',
                        style: theme.textTheme.bodySmall?.copyWith(fontSize: 11.5)),
                  )
                else
                  const Spacer(),
                if (s.sections.isNotEmpty)
                  TextButton.icon(
                    onPressed: () => setState(() => _expanded = !_expanded),
                    icon: Icon(_expanded ? Icons.expand_less : Icons.expand_more,
                        size: 18, color: Colors.white),
                    label: Text(_expanded ? 'طيّ الملخص' : 'الملخص الكامل',
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 13)),
                    style: TextButton.styleFrom(
                      backgroundColor: ink,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                      minimumSize: const Size(0, 36),
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    ),
                  ),
              ]),
            ],
          ),
        );
      },
    );
  }

  List<Widget> _buildSections(BuildContext context, PlatformSummary s, Color ink, ThemeData theme) {
    return [
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
                    Text('• ', style: TextStyle(color: ink, fontWeight: FontWeight.w900)),
                    Expanded(child: Text(item, style: const TextStyle(fontSize: 13, height: 1.6))),
                  ]),
                ),
              if (sec.whyMatters.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('لماذا يهم؟ ${sec.whyMatters}',
                      style: theme.textTheme.bodySmall?.copyWith(fontStyle: FontStyle.italic, fontSize: 11.5)),
                ),
            ],
          ),
        ),
    ];
  }
}

/// Inline analytics card (KPIs + activity bars + top sources). Replaces the
/// old bottom-sheet so stats live in the page flow, matching Figma.
class _PlatformStatsCard extends ConsumerStatefulWidget {
  const _PlatformStatsCard({required this.platform, required this.accent});
  final String platform;
  final Color accent;

  @override
  ConsumerState<_PlatformStatsCard> createState() => _PlatformStatsCardState();
}

class _PlatformStatsCardState extends ConsumerState<_PlatformStatsCard> {
  String _range = '24h';
  static const _rangeLabels = {'24h': '٢٤ ساعة', '7d': '٧ أيام', '30d': '٣٠ يوم'};

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final ink = AppColors.accentInk(widget.accent, isDark);
    final asy = ref.watch(platformStatsProvider((platform: widget.platform, range: _range)));
    return Container(
      decoration: BoxDecoration(
        color: theme.cardColor,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: theme.dividerColor.withOpacity(0.6)),
        boxShadow: [BoxShadow(color: Colors.black.withOpacity(isDark ? 0.32 : 0.05), blurRadius: 16, offset: const Offset(0, 6))],
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Head: ranges + title
          Row(children: [
            ..._rangeLabels.entries.map((e) {
              final sel = e.key == _range;
              return Padding(
                padding: const EdgeInsets.only(left: 6),
                child: GestureDetector(
                  onTap: () => setState(() => _range = e.key),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
                    decoration: BoxDecoration(
                      color: sel ? ink : theme.scaffoldBackgroundColor,
                      borderRadius: BorderRadius.circular(9),
                      border: sel ? null : Border.all(color: theme.dividerColor),
                    ),
                    child: Text(e.value,
                        style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                            color: sel ? Colors.white : theme.textTheme.bodyMedium?.color)),
                  ),
                ),
              );
            }),
            const Spacer(),
            Text('إحصاءات اليوم',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15, color: ink)),
            const SizedBox(width: 6),
            const Text('📊', style: TextStyle(fontSize: 15)),
          ]),
          const SizedBox(height: 14),
          asy.when(
            loading: () => const SizedBox(
                height: 120, child: Center(child: CircularProgressIndicator(strokeWidth: 2))),
            error: (e, _) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 16),
              child: Text('تعذّر تحميل الإحصاءات',
                  style: theme.textTheme.bodySmall, textAlign: TextAlign.center),
            ),
            data: (st) {
              if (st.total == 0) {
                return Padding(
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  child: Text('لا توجد بيانات في هذه الفترة',
                      style: theme.textTheme.bodySmall, textAlign: TextAlign.center),
                );
              }
              return Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    _kpi(theme, '${st.total}', 'إجمالي المنشورات'),
                    const SizedBox(width: 10),
                    _kpi(theme, '${st.activeSources}', 'مصادر نشطة'),
                  ]),
                  const SizedBox(height: 10),
                  Row(children: [
                    _kpi(theme, '${(st.palestineShare * 100).round()}٪', 'محتوى فلسطيني'),
                    const SizedBox(width: 10),
                    _kpi(theme, st.peak ?? '—', 'ذروة النشاط'),
                  ]),
                  if (st.timeline.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    Text('النشاط خلال الفترة',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: theme.textTheme.titleLarge?.color)),
                    const SizedBox(height: 10),
                    _bars(st.timeline),
                  ],
                  if (st.topSources.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    Text('أكثر المصادر نشاطاً',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: theme.textTheme.titleLarge?.color)),
                    const SizedBox(height: 10),
                    ...st.topSources.map((s) => _srcRow(theme, ink, s.name, s.count, st.topSources.first.count)),
                  ],
                ],
              );
            },
          ),
        ],
      ),
    );
  }

  Widget _kpi(ThemeData theme, String value, String label) {
    final isDark = theme.brightness == Brightness.dark;
    return Expanded(
      child: Container(
        padding: const EdgeInsets.all(13),
        decoration: BoxDecoration(
          color: isDark ? Colors.white.withOpacity(0.04) : Colors.black.withOpacity(0.025),
          borderRadius: BorderRadius.circular(13),
          border: Border.all(color: theme.dividerColor.withOpacity(0.6)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(value, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 21)),
            const SizedBox(height: 2),
            Text(label, style: theme.textTheme.bodySmall?.copyWith(fontSize: 11.5)),
          ],
        ),
      ),
    );
  }

  Widget _bars(List<({String label, int count})> buckets) {
    final maxCount = buckets.fold<int>(1, (m, b) => b.count > m ? b.count : m);
    final step = (buckets.length / 8).ceil().clamp(1, 999);
    return SizedBox(
      height: 120,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: List.generate(buckets.length, (i) {
          final b = buckets[i];
          final frac = (b.count / maxCount).clamp(0.0, 1.0);
          return Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 1.5),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  Container(
                    height: (88 * frac) + (b.count > 0 ? 3 : 0),
                    decoration: BoxDecoration(
                      color: widget.accent.withOpacity(b.count > 0 ? 0.85 : 0.15),
                      borderRadius: const BorderRadius.vertical(top: Radius.circular(3)),
                    ),
                  ),
                  const SizedBox(height: 4),
                  SizedBox(
                    height: 20,
                    child: i % step == 0
                        ? Text(b.label,
                            style: const TextStyle(fontSize: 8),
                            textAlign: TextAlign.center,
                            maxLines: 1,
                            overflow: TextOverflow.clip)
                        : const SizedBox.shrink(),
                  ),
                ],
              ),
            ),
          );
        }),
      ),
    );
  }

  Widget _srcRow(ThemeData theme, Color ink, String name, int count, int maxCount) {
    final frac = maxCount > 0 ? (count / maxCount).clamp(0.05, 1.0) : 0.0;
    return Padding(
      padding: const EdgeInsets.only(bottom: 11),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Expanded(
                child: Text(name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13))),
            Text('$count', style: TextStyle(fontWeight: FontWeight.w800, color: ink)),
          ]),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: frac,
              minHeight: 8,
              backgroundColor: widget.accent.withOpacity(0.12),
              valueColor: AlwaysStoppedAnimation(widget.accent),
            ),
          ),
        ],
      ),
    );
  }
}
