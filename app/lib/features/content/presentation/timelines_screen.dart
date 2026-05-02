import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:timeago/timeago.dart' as timeago;

import '../../../core/api/api_client.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';

// ── Models ──

class _Timeline {
  const _Timeline({
    required this.key,
    required this.title,
    required this.summary,
    required this.articleCount,
    required this.sourceCount,
    this.narrative,
    this.entries = const [],
    this.updatedAt,
  });
  final String key, title, summary;
  final int articleCount, sourceCount;
  final String? narrative;
  final DateTime? updatedAt;
  final List<_TimelineEntry> entries;

  factory _Timeline.fromJson(Map<String, dynamic> j) => _Timeline(
        key: j['key']?.toString() ?? j['cluster_key']?.toString() ?? '',
        title: j['title']?.toString() ?? '',
        summary: j['summary']?.toString() ?? '',
        articleCount: (j['article_count'] as num?)?.toInt() ?? 0,
        sourceCount: (j['source_count'] as num?)?.toInt() ?? 0,
        narrative: j['narrative'] as String?,
        updatedAt: j['updated_at'] != null ? DateTime.tryParse(j['updated_at'].toString()) : null,
        entries: (j['entries'] as List? ?? [])
            .whereType<Map>()
            .map((m) => _TimelineEntry.fromJson(m.cast()))
            .toList(),
      );
}

class _TimelineEntry {
  const _TimelineEntry({
    required this.id,
    required this.title,
    this.source,
    this.imageUrl,
    this.publishedAt,
    this.excerpt,
  });
  final int id;
  final String title;
  final String? source, imageUrl, excerpt;
  final DateTime? publishedAt;

  factory _TimelineEntry.fromJson(Map<String, dynamic> j) => _TimelineEntry(
        id: (j['id'] as num?)?.toInt() ?? 0,
        title: j['title']?.toString() ?? '',
        source: j['source_name'] as String? ?? j['source'] as String?,
        imageUrl: j['image_url'] as String?,
        excerpt: j['excerpt'] as String?,
        publishedAt: j['published_at'] != null ? DateTime.tryParse(j['published_at'].toString()) : null,
      );
}

// ── Providers ──

final _timelinesProvider = FutureProvider<List<_Timeline>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<List<dynamic>>('/content/timelines',
      decode: (d) => (d as List).cast<dynamic>());
  return (res.data ?? [])
      .whereType<Map>()
      .map((m) => _Timeline.fromJson(m.cast<String, dynamic>()))
      .toList();
});

final _timelineDetailProvider = FutureProvider.family<_Timeline, String>((ref, key) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/timeline',
      query: {'key': key},
      decode: (d) => (d as Map).cast<String, dynamic>());
  return _Timeline.fromJson(res.data!);
});

// ═══════════════════════════════════════════════════════════════
// TIMELINES INDEX SCREEN
// ═══════════════════════════════════════════════════════════════

class TimelinesScreen extends ConsumerWidget {
  const TimelinesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_timelinesProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: AppBar(title: const Text('الخطوط الزمنية')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_timelinesProvider),
        ),
        data: (list) {
          if (list.isEmpty) return const EmptyView(message: 'لا توجد خطوط زمنية متاحة');
          return RefreshIndicator(
            onRefresh: () async => ref.invalidate(_timelinesProvider),
            child: ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: list.length,
              itemBuilder: (_, i) {
                final t = list[i];
                return _TimelineCard(timeline: t, isDark: isDark);
              },
            ),
          );
        },
      ),
    );
  }
}

class _TimelineCard extends StatelessWidget {
  const _TimelineCard({required this.timeline, required this.isDark});
  final _Timeline timeline;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => _TimelineDetailScreen(timelineKey: timeline.key, title: timeline.title)),
      ),
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
          boxShadow: [
            BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 8, offset: const Offset(0, 2)),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Container(
                width: 40, height: 40,
                decoration: BoxDecoration(
                  gradient: const LinearGradient(colors: [Color(0xFF0D9488), Color(0xFF14B8A6)]),
                  borderRadius: BorderRadius.circular(10),
                ),
                alignment: Alignment.center,
                child: const Icon(Icons.timeline, color: Colors.white, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(timeline.title,
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800,
                    color: isDark ? Colors.white : AppColors.textLight),
                  maxLines: 2, overflow: TextOverflow.ellipsis),
              ),
              Icon(Icons.chevron_left, color: isDark ? Colors.white38 : AppColors.textMutedLight),
            ]),
            if (timeline.summary.isNotEmpty) ...[
              const SizedBox(height: 10),
              Text(timeline.summary,
                style: TextStyle(fontSize: 13, height: 1.6,
                  color: isDark ? Colors.white54 : AppColors.textMutedLight),
                maxLines: 2, overflow: TextOverflow.ellipsis),
            ],
            const SizedBox(height: 10),
            Row(children: [
              _SmallChip(text: '${timeline.articleCount} مقال', color: const Color(0xFF0D9488)),
              const SizedBox(width: 8),
              _SmallChip(text: '${timeline.sourceCount} مصدر', color: const Color(0xFF6366F1)),
              const Spacer(),
              if (timeline.updatedAt != null)
                Text(timeago.format(timeline.updatedAt!, locale: 'ar'),
                  style: TextStyle(fontSize: 11, color: isDark ? Colors.white30 : AppColors.textMutedLight)),
            ]),
          ],
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// TIMELINE DETAIL SCREEN
// ═══════════════════════════════════════════════════════════════

class _TimelineDetailScreen extends ConsumerWidget {
  const _TimelineDetailScreen({required this.timelineKey, required this.title});
  final String timelineKey;
  final String title;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_timelineDetailProvider(timelineKey));
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => Scaffold(
          appBar: AppBar(title: Text(title)),
          body: ErrorRetryView(
            message: '$e',
            onRetry: () => ref.invalidate(_timelineDetailProvider(timelineKey)),
          ),
        ),
        data: (tl) => CustomScrollView(
          slivers: [
            // ── Header ──
            SliverToBoxAdapter(
              child: Container(
                padding: EdgeInsets.fromLTRB(20, MediaQuery.of(context).padding.top + 16, 20, 24),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topRight,
                    end: Alignment.bottomLeft,
                    colors: isDark
                        ? [const Color(0xFF0F2027), const Color(0xFF203A43)]
                        : [const Color(0xFF0D9488), const Color(0xFF0F766E)],
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    GestureDetector(
                      onTap: () => Navigator.of(context).pop(),
                      child: Container(
                        width: 38, height: 38,
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(0.15),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        alignment: Alignment.center,
                        child: const Icon(Icons.arrow_forward_ios, color: Colors.white, size: 16),
                      ),
                    ),
                    const SizedBox(height: 20),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        const Icon(Icons.timeline, color: Colors.white, size: 14),
                        const SizedBox(width: 4),
                        const Text('خط زمني ذكي',
                          style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                      ]),
                    ),
                    const SizedBox(height: 12),
                    Text(tl.title,
                      style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w900, height: 1.3)),
                    const SizedBox(height: 12),
                    Row(children: [
                      Text('${tl.articleCount} تقرير',
                        style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 12, fontWeight: FontWeight.w600)),
                      const SizedBox(width: 12),
                      Text('${tl.sourceCount} مصدر',
                        style: TextStyle(color: Colors.white.withOpacity(0.7), fontSize: 12, fontWeight: FontWeight.w600)),
                    ]),
                  ],
                ),
              ),
            ),

            // ── AI Narrative ──
            if (tl.narrative != null && tl.narrative!.isNotEmpty)
              SliverToBoxAdapter(
                child: Container(
                  margin: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: isDark ? const Color(0xFF1E293B) : const Color(0xFFF0FDFA),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(
                      color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFF99F6E4)),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        const Icon(Icons.auto_awesome, size: 16, color: Color(0xFF0D9488)),
                        const SizedBox(width: 6),
                        Text('سرد الأحداث',
                          style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800,
                            color: isDark ? Colors.white : AppColors.textLight)),
                      ]),
                      const SizedBox(height: 10),
                      Text(tl.narrative!,
                        style: TextStyle(fontSize: 14, height: 1.8,
                          color: isDark ? Colors.white70 : AppColors.textLight)),
                    ],
                  ),
                ),
              ),

            // ── Visual Timeline ──
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 24, 16, 12),
                child: Row(children: [
                  const Text('📋', style: TextStyle(fontSize: 18)),
                  const SizedBox(width: 8),
                  Text('تسلسل الأحداث',
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                      color: isDark ? Colors.white : AppColors.textLight)),
                ]),
              ),
            ),

            if (tl.entries.isEmpty)
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(tl.summary,
                    style: TextStyle(fontSize: 14, height: 1.8,
                      color: isDark ? Colors.white54 : AppColors.textMutedLight)),
                ),
              )
            else
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
                sliver: SliverList.builder(
                  itemCount: tl.entries.length,
                  itemBuilder: (_, i) {
                    final e = tl.entries[i];
                    final isLast = i == tl.entries.length - 1;
                    return _TimelineEntryTile(entry: e, isDark: isDark, isLast: isLast, accent: const Color(0xFF0D9488));
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }
}

// ── Visual timeline entry with line + dot ──

class _TimelineEntryTile extends StatelessWidget {
  const _TimelineEntryTile({required this.entry, required this.isDark, required this.isLast, required this.accent});
  final _TimelineEntry entry;
  final bool isDark;
  final bool isLast;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return IntrinsicHeight(
      child: GestureDetector(
        onTap: () => context.push('/article/${entry.id}'),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ── Timeline rail (dot + line) ──
            SizedBox(
              width: 32,
              child: Column(
                children: [
                  Container(
                    width: 14, height: 14,
                    decoration: BoxDecoration(
                      color: accent,
                      shape: BoxShape.circle,
                      border: Border.all(color: accent.withOpacity(0.3), width: 3),
                    ),
                  ),
                  if (!isLast)
                    Expanded(
                      child: Container(
                        width: 2,
                        color: isDark ? Colors.white.withOpacity(0.08) : const Color(0xFFE2E8F0),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: 10),

            // ── Content ──
            Expanded(
              child: Container(
                margin: const EdgeInsets.only(bottom: 16),
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(
                    color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Time + Source
                    Row(children: [
                      if (entry.publishedAt != null)
                        Text(timeago.format(entry.publishedAt!, locale: 'ar'),
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: accent)),
                      if (entry.source != null) ...[
                        if (entry.publishedAt != null)
                          Text(' • ', style: TextStyle(fontSize: 11, color: isDark ? Colors.white30 : AppColors.textMutedLight)),
                        Text(entry.source!,
                          style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600,
                            color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                      ],
                    ]),
                    const SizedBox(height: 6),
                    Text(entry.title,
                      style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, height: 1.4,
                        color: isDark ? Colors.white : AppColors.textLight),
                      maxLines: 3, overflow: TextOverflow.ellipsis),
                    if (entry.excerpt != null && entry.excerpt!.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(entry.excerpt!,
                        style: TextStyle(fontSize: 12, height: 1.6,
                          color: isDark ? Colors.white38 : AppColors.textMutedLight),
                        maxLines: 2, overflow: TextOverflow.ellipsis),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SmallChip extends StatelessWidget {
  const _SmallChip({required this.text, required this.color});
  final String text;
  final Color color;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(text, style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: color)),
    );
  }
}
