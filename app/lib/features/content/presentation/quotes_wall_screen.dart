import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/models/evolving_story.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

class QuotesWallScreen extends ConsumerWidget {
  const QuotesWallScreen({super.key, required this.slug, required this.storyName});
  final String slug;
  final String storyName;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(_quotesProvider(slug));
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: AppBar(
        title: Text('اقتباسات — $storyName'),
      ),
      body: asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_quotesProvider(slug)),
        ),
        data: (quotes) {
          if (quotes.isEmpty) {
            return const EmptyView(message: 'لا توجد اقتباسات لهذه القصة');
          }
          return ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: quotes.length,
            itemBuilder: (_, i) {
              final q = quotes[i];
              return _QuoteCard(quote: q, isDark: isDark);
            },
          );
        },
      ),
    );
  }
}

final _quotesProvider = FutureProvider.family<List<StoryQuote>, String>((ref, slug) {
  return ref.watch(contentRepositoryProvider).storyQuotes(slug);
});

class _QuoteCard extends StatelessWidget {
  const _QuoteCard({required this.quote, required this.isDark});
  final StoryQuote quote;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    final accent = const Color(0xFF0D9488);
    final sp = quote.speaker ?? '';
    final initial = sp.isNotEmpty ? sp.substring(0, 1) : '؟';

    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: isDark ? Colors.white.withOpacity(0.04) : Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border(
          right: BorderSide(color: accent, width: 4),
          top: BorderSide(color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
          bottom: BorderSide(color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
          left: BorderSide(color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Quote mark
          Text('"', style: TextStyle(
            fontSize: 48, fontWeight: FontWeight.w900,
            color: accent.withOpacity(0.4), height: 0.5)),
          const SizedBox(height: 12),

          // Quote text
          Text(quote.quote,
            style: TextStyle(
              fontSize: 16, height: 1.8, fontWeight: FontWeight.w500,
              color: isDark ? Colors.white : AppColors.textLight)),

          const SizedBox(height: 16),

          // Attribution
          Row(children: [
            Container(
              width: 40, height: 40,
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [accent, const Color(0xFF14B8A6)]),
                shape: BoxShape.circle,
              ),
              alignment: Alignment.center,
              child: Text(initial,
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 16)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(quote.speaker ?? '—',
                    style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800,
                      color: isDark ? Colors.white : AppColors.textLight)),
                  if (quote.context != null)
                    Text(quote.context!,
                      style: TextStyle(fontSize: 12,
                        color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                ],
              ),
            ),
          ]),

          // Link to article
          if (quote.articleId != null) ...[
            const SizedBox(height: 12),
            GestureDetector(
              onTap: () => context.push('/article/${quote.articleId}'),
              child: Row(children: [
                Icon(Icons.article_outlined, size: 14, color: accent),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(quote.articleTitle ?? 'المقال المصدر',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: accent),
                    maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
              ]),
            ),
          ],
        ],
      ),
    );
  }
}
