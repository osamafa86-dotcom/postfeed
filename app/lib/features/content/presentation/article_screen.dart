import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';
import 'package:timeago/timeago.dart' as timeago;
import 'package:url_launcher/url_launcher.dart';

import '../../../core/models/article.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../../../core/widgets/section_header.dart';
import '../data/content_repository.dart';

class ArticleScreen extends ConsumerWidget {
  const ArticleScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(articleProvider(id));
    return Scaffold(
      appBar: AppBar(
        actions: [
          asy.maybeWhen(
            data: (d) => IconButton(
              icon: const Icon(Icons.share_outlined),
              onPressed: () => Share.share(d.article.title +
                  (d.article.sourceUrl != null ? '\n${d.article.sourceUrl}' : '')),
            ),
            orElse: () => const SizedBox.shrink(),
          ),
          IconButton(
            icon: const Icon(Icons.bookmark_outline),
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('سجّل دخولك لحفظ المقال')),
              );
            },
          ),
        ],
      ),
      body: asy.when(
        loading: () => const LoadingShimmerList(itemCount: 4),
        error: (e, _) => ErrorRetryView(
          message: 'تعذّر تحميل المقال\n$e',
          onRetry: () => ref.invalidate(articleProvider(id)),
        ),
        data: (data) => _ArticleBody(article: data.article, related: data.related),
      ),
    );
  }
}

class _ArticleBody extends StatelessWidget {
  const _ArticleBody({required this.article, required this.related});
  final Article article;
  final List<Article> related;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return ListView(
      padding: EdgeInsets.zero,
      children: [
        if (article.imageUrl != null)
          CachedNetworkImage(
            imageUrl: article.imageUrl!,
            width: double.infinity,
            height: 230,
            fit: BoxFit.cover,
          ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  if (article.category != null)
                    InkWell(
                      onTap: () => context.push('/category/${article.category!.slug}'),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: AppColors.primary.withOpacity(0.1),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          '${article.category!.icon ?? ''} ${article.category!.name}'.trim(),
                          style: TextStyle(color: AppColors.primary, fontWeight: FontWeight.w700),
                        ),
                      ),
                    ),
                  if (article.isBreaking) ...[
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppColors.breaking,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Text('عاجل', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
                    ),
                  ],
                ],
              ),
              const SizedBox(height: 14),
              Text(article.title,
                  style: theme.textTheme.headlineMedium?.copyWith(height: 1.35)),
              const SizedBox(height: 10),
              Row(
                children: [
                  if (article.source != null) ...[
                    Icon(Icons.public, size: 14, color: theme.textTheme.bodySmall?.color),
                    const SizedBox(width: 4),
                    InkWell(
                      onTap: () => context.push('/source/${article.source!.slug}'),
                      child: Text(article.source!.name, style: theme.textTheme.bodyMedium),
                    ),
                    const SizedBox(width: 12),
                  ],
                  if (article.publishedAt != null) ...[
                    Icon(Icons.schedule, size: 14, color: theme.textTheme.bodySmall?.color),
                    const SizedBox(width: 4),
                    Text(timeago.format(article.publishedAt!, locale: 'ar'),
                        style: theme.textTheme.bodySmall),
                  ],
                  const Spacer(),
                  Icon(Icons.visibility_outlined, size: 14, color: theme.textTheme.bodySmall?.color),
                  const SizedBox(width: 4),
                  Text('${article.viewCount}', style: theme.textTheme.bodySmall),
                ],
              ),
              const Divider(height: 32),
              if (article.excerpt != null && article.excerpt!.isNotEmpty) ...[
                Text(
                  article.excerpt!,
                  style: theme.textTheme.bodyLarge?.copyWith(
                    fontWeight: FontWeight.w600,
                    height: 1.7,
                  ),
                ),
                const SizedBox(height: 16),
              ],
              if (article.content != null && article.content!.isNotEmpty)
                Html(
                  data: article.content!,
                  style: {
                    'body': Style(margin: Margins.zero, padding: HtmlPaddings.zero, fontSize: FontSize(16), lineHeight: LineHeight.number(1.8)),
                    'p': Style(margin: Margins.only(bottom: 14)),
                    'h2': Style(fontSize: FontSize(20), fontWeight: FontWeight.w800, margin: Margins.only(top: 18, bottom: 8)),
                    'h3': Style(fontSize: FontSize(18), fontWeight: FontWeight.w700, margin: Margins.only(top: 16, bottom: 6)),
                    'a': Style(color: AppColors.primary, textDecoration: TextDecoration.underline),
                    'blockquote': Style(
                      padding: HtmlPaddings.symmetric(horizontal: 12, vertical: 8),
                      backgroundColor: AppColors.primary.withOpacity(0.06),
                      border: const Border(right: BorderSide(color: AppColors.primary, width: 4)),
                      margin: Margins.symmetric(vertical: 12),
                    ),
                  },
                ),
              if (article.sourceUrl != null) ...[
                const SizedBox(height: 24),
                OutlinedButton.icon(
                  icon: const Icon(Icons.open_in_new),
                  label: const Text('قراءة المصدر الأصلي'),
                  onPressed: () => launchUrl(Uri.parse(article.sourceUrl!)),
                ),
              ],
            ],
          ),
        ),
        if (related.isNotEmpty) ...[
          const SectionHeader(title: 'مقالات ذات صلة', icon: Icons.read_more),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
            child: Column(
              children: [
                for (final r in related)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: ArticleCard(article: r, compact: true),
                  ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}
