import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'dart:math' as math;

import '../../../core/api/api_client.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';

// ── Model ──

class _StoryNode {
  const _StoryNode({required this.slug, required this.name, required this.icon, required this.articleCount, required this.color});
  final String slug, name, icon, color;
  final int articleCount;

  factory _StoryNode.fromJson(Map<String, dynamic> j) => _StoryNode(
    slug: j['slug']?.toString() ?? '',
    name: j['name']?.toString() ?? '',
    icon: j['icon']?.toString() ?? '📰',
    articleCount: (j['article_count'] as num?)?.toInt() ?? 0,
    color: j['accent_color']?.toString() ?? '#0D9488',
  );
}

class _StoryEdge {
  const _StoryEdge({required this.from, required this.to, required this.weight});
  final String from, to;
  final int weight;

  factory _StoryEdge.fromJson(Map<String, dynamic> j) => _StoryEdge(
    from: j['from']?.toString() ?? '',
    to: j['to']?.toString() ?? '',
    weight: (j['weight'] as num?)?.toInt() ?? 1,
  );
}

// ── Provider ──

final _networkProvider = FutureProvider((ref) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get<Map<String, dynamic>>('/content/stories-network',
      decode: (d) => (d as Map).cast<String, dynamic>());
  final data = res.data!;
  final nodes = (data['nodes'] as List? ?? [])
      .whereType<Map>().map((m) => _StoryNode.fromJson(m.cast())).toList();
  final edges = (data['edges'] as List? ?? [])
      .whereType<Map>().map((m) => _StoryEdge.fromJson(m.cast())).toList();
  return (nodes: nodes, edges: edges);
});

// ── Screen ──

class StoriesNetworkScreen extends ConsumerWidget {
  const StoriesNetworkScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_networkProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('شبكة القصص')),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_networkProvider),
        ),
        data: (data) {
          if (data.nodes.isEmpty) return const EmptyView(message: 'لا توجد قصص مترابطة');
          return _NetworkView(nodes: data.nodes, edges: data.edges);
        },
      ),
    );
  }
}

class _NetworkView extends StatelessWidget {
  const _NetworkView({required this.nodes, required this.edges});
  final List<_StoryNode> nodes;
  final List<_StoryEdge> edges;

  Color _parseColor(String hex) {
    try {
      return Color(int.parse(hex.replaceAll('#', '0xFF')));
    } catch (_) {
      return const Color(0xFF0D9488);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    // Build a simple circular layout
    final maxArticles = nodes.fold<int>(1, (p, n) => math.max(p, n.articleCount));

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          // ── Info card ──
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(16),
            margin: const EdgeInsets.only(bottom: 16),
            decoration: BoxDecoration(
              color: isDark ? Colors.white.withOpacity(0.04) : const Color(0xFFF0FDFA),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFF99F6E4)),
            ),
            child: Row(
              children: [
                const Icon(Icons.hub, size: 20, color: Color(0xFF0D9488)),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'شبكة تفاعلية تُظهر العلاقات بين القصص المتطورة — كلما كبر حجم الدائرة زاد عدد التقارير.',
                    style: TextStyle(fontSize: 12, height: 1.6,
                      color: isDark ? Colors.white54 : AppColors.textMutedLight),
                  ),
                ),
              ],
            ),
          ),

          // ── Story cards (list-based since Flutter doesn't have D3) ──
          ...nodes.map((node) {
            final color = _parseColor(node.color);
            final sizeRatio = node.articleCount / maxArticles;
            final circleSize = 40 + (sizeRatio * 30);

            // Find connections
            final connections = edges.where((e) => e.from == node.slug || e.to == node.slug).toList();
            final connectedNames = connections.map((e) {
              final otherSlug = e.from == node.slug ? e.to : e.from;
              final other = nodes.where((n) => n.slug == otherSlug).toList();
              return other.isNotEmpty ? other.first.name : otherSlug;
            }).toList();

            return GestureDetector(
              onTap: () => context.push('/stories/${node.slug}'),
              child: Container(
                margin: const EdgeInsets.only(bottom: 10),
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(
                    color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
                ),
                child: Row(
                  children: [
                    // Circle node
                    Container(
                      width: circleSize,
                      height: circleSize,
                      decoration: BoxDecoration(
                        gradient: LinearGradient(colors: [color, color.withOpacity(0.7)]),
                        shape: BoxShape.circle,
                        boxShadow: [BoxShadow(color: color.withOpacity(0.25), blurRadius: 10)],
                      ),
                      alignment: Alignment.center,
                      child: Text(node.icon, style: TextStyle(fontSize: circleSize * 0.4)),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(node.name,
                            style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800,
                              color: isDark ? Colors.white : AppColors.textLight)),
                          const SizedBox(height: 4),
                          Text('${node.articleCount} تقرير',
                            style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: color)),
                          if (connectedNames.isNotEmpty) ...[
                            const SizedBox(height: 6),
                            Wrap(
                              spacing: 6,
                              runSpacing: 4,
                              children: connectedNames.take(3).map((name) => Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  color: color.withOpacity(0.08),
                                  borderRadius: BorderRadius.circular(6),
                                ),
                                child: Row(mainAxisSize: MainAxisSize.min, children: [
                                  Icon(Icons.link, size: 10, color: color),
                                  const SizedBox(width: 3),
                                  Text(name, style: TextStyle(fontSize: 9, fontWeight: FontWeight.w600, color: color)),
                                ]),
                              )).toList(),
                            ),
                          ],
                        ],
                      ),
                    ),
                    Icon(Icons.chevron_left, size: 20,
                      color: isDark ? Colors.white38 : AppColors.textMutedLight),
                  ],
                ),
              ),
            );
          }),
        ],
      ),
    );
  }
}
