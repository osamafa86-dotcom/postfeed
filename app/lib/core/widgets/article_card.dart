import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:timeago/timeago.dart' as timeago;

import '../models/article.dart';
import '../theme/app_theme.dart';

class ArticleCard extends StatelessWidget {
  const ArticleCard({super.key, required this.article, this.compact = false});
  final Article article;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: () => context.push('/article/${article.id}'),
        borderRadius: BorderRadius.circular(14),
        child: Container(
          decoration: BoxDecoration(
            color: theme.cardColor,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: theme.dividerColor),
          ),
          padding: const EdgeInsets.all(10),
          child: compact ? _compactLayout(theme) : _fullLayout(theme),
        ),
      ),
    );
  }

  Widget _fullLayout(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (article.imageUrl != null)
          ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: AspectRatio(
              aspectRatio: 16 / 9,
              child: CachedNetworkImage(
                imageUrl: article.imageUrl!,
                fit: BoxFit.cover,
                placeholder: (_, __) => Container(color: theme.dividerColor.withOpacity(0.3)),
                errorWidget: (_, __, ___) => Container(color: theme.dividerColor.withOpacity(0.3)),
              ),
            ),
          ),
        const SizedBox(height: 10),
        Row(
          children: [
            if (article.category != null) _categoryChip(article.category!),
            if (article.isBreaking) ...[
              const SizedBox(width: 6),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AppColors.breaking,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: const Text('عاجل',
                    style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
              ),
            ],
            const Spacer(),
            if (article.publishedAt != null)
              Text(
                timeago.format(article.publishedAt!, locale: 'ar'),
                style: theme.textTheme.bodySmall,
              ),
          ],
        ),
        const SizedBox(height: 6),
        Text(
          article.title,
          style: theme.textTheme.titleMedium?.copyWith(height: 1.45),
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
        ),
        if (article.excerpt != null && article.excerpt!.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(
            article.excerpt!,
            style: theme.textTheme.bodySmall,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
        ],
        if (article.source != null) ...[
          const SizedBox(height: 8),
          Row(
            children: [
              _sourceBadge(article.source!.logoLetter ?? '', article.source!.logoColor),
              const SizedBox(width: 6),
              Text(article.source!.name, style: theme.textTheme.bodySmall),
            ],
          ),
        ],
      ],
    );
  }

  Widget _compactLayout(ThemeData theme) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (article.imageUrl != null)
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: SizedBox(
              width: 84,
              height: 84,
              child: CachedNetworkImage(
                imageUrl: article.imageUrl!,
                fit: BoxFit.cover,
                placeholder: (_, __) => Container(color: theme.dividerColor.withOpacity(0.3)),
                errorWidget: (_, __, ___) => Container(color: theme.dividerColor.withOpacity(0.3)),
              ),
            ),
          ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (article.category != null) _categoryChip(article.category!),
              const SizedBox(height: 4),
              Text(
                article.title,
                style: theme.textTheme.titleSmall?.copyWith(height: 1.45),
                maxLines: 3,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 6),
              Row(
                children: [
                  if (article.source != null)
                    Expanded(
                      child: Text(
                        article.source!.name,
                        style: theme.textTheme.bodySmall,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  if (article.publishedAt != null)
                    Text(
                      timeago.format(article.publishedAt!, locale: 'ar'),
                      style: theme.textTheme.bodySmall,
                    ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _categoryChip(category) {
    final color = AppColors.categoryColors[category.color] ?? AppColors.primary;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.12),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        '${category.icon ?? ''} ${category.name}'.trim(),
        style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700),
      ),
    );
  }

  Widget _sourceBadge(String letter, String? hex) {
    Color color;
    try {
      color = Color(int.parse(hex?.replaceAll('#', '0xFF') ?? '0xFF5A85B0'));
    } catch (_) {
      color = AppColors.primary;
    }
    return Container(
      width: 22,
      height: 22,
      decoration: BoxDecoration(color: color.withOpacity(0.15), shape: BoxShape.circle),
      alignment: Alignment.center,
      child: Text(
        letter,
        style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700),
      ),
    );
  }
}
