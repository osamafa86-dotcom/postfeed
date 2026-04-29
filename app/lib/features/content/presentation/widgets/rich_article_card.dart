import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';
import 'package:timeago/timeago.dart' as timeago;

import '../../../../core/models/article.dart';
import '../../../../core/models/category.dart';
import '../../../../core/theme/app_theme.dart';

/// Mirrors the website's `.news-card` block + `.nf-action-bar` footer.
///
///  ┌──────────────────────────────────────────────┐
///  │             [card image 16:9]            🔖 │
///  │ ┌─[فلسطين]                                  │
///  │ │ Headline goes here, up to three lines     │
///  │ │ Excerpt, two lines max …                  │
///  │ │ ●العربي · منذ 12 دقيقة                    │
///  │ ├──────────────────────────────────────────┤
///  │ │ 👍 12   👎 0     ↗ 4    🔖     👁 1.2k    │
///  │ └──────────────────────────────────────────┘
///  └──────────────────────────────────────────────┘
class RichArticleCard extends StatefulWidget {
  const RichArticleCard({super.key, required this.article});
  final Article article;

  @override
  State<RichArticleCard> createState() => _RichArticleCardState();
}

class _RichArticleCardState extends State<RichArticleCard> {
  // Local optimistic state — Phase 2 will wire these to the backend.
  bool _saved = false;
  String? _myReaction; // 'like' | 'dislike' | null
  int _likes = 0;
  int _dislikes = 0;
  int _shares = 0;

  void _toggleReaction(String type) {
    setState(() {
      if (_myReaction == type) {
        _myReaction = null;
        if (type == 'like') _likes--;
        else _dislikes--;
      } else {
        if (_myReaction == 'like') _likes--;
        if (_myReaction == 'dislike') _dislikes--;
        _myReaction = type;
        if (type == 'like') _likes++;
        else _dislikes++;
      }
    });
  }

  Future<void> _share() async {
    final a = widget.article;
    const base = 'https://feedsnews.net';
    final url = a.slug != null ? '$base/article/${a.slug}' : '$base/article/${a.id}';
    await Share.share('${a.title}\n$url');
    if (!mounted) return;
    setState(() => _shares++);
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.article;
    final theme = Theme.of(context);
    return Container(
      decoration: BoxDecoration(
        color: AppColors.cardLight,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.borderLight),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0F000000),
            blurRadius: 6,
            offset: Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _Image(article: a, saved: _saved, onToggleSave: () => setState(() => _saved = !_saved)),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    if (a.category != null) _CategoryChip(category: a.category!),
                    if (a.isBreaking) ...[
                      const SizedBox(width: 6),
                      _BreakingBadge(),
                    ],
                  ],
                ),
                const SizedBox(height: 8),
                InkWell(
                  onTap: () => context.push('/article/${a.id}'),
                  child: Text(
                    a.title,
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      height: 1.4,
                      fontSize: 15.5,
                      color: AppColors.textLight,
                    ),
                  ),
                ),
                if (a.excerpt != null && a.excerpt!.isNotEmpty) ...[
                  const SizedBox(height: 6),
                  Text(
                    a.excerpt!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: AppColors.textMutedLight,
                      height: 1.55,
                      fontSize: 12.5,
                    ),
                  ),
                ],
                const SizedBox(height: 10),
                _SourceRow(article: a),
              ],
            ),
          ),
          const Divider(height: 1, thickness: 1, color: AppColors.borderLight),
          _ActionBar(
            myReaction: _myReaction,
            likes: _likes,
            dislikes: _dislikes,
            shares: _shares,
            saved: _saved,
            views: a.viewCount,
            onLike: () => _toggleReaction('like'),
            onDislike: () => _toggleReaction('dislike'),
            onShare: _share,
            onSave: () => setState(() => _saved = !_saved),
          ),
        ],
      ),
    );
  }
}

class _Image extends StatelessWidget {
  const _Image({required this.article, required this.saved, required this.onToggleSave});
  final Article article;
  final bool saved;
  final VoidCallback onToggleSave;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(14)),
      child: Stack(
        children: [
          AspectRatio(
            aspectRatio: 16 / 9,
            child: article.imageUrl != null
                ? CachedNetworkImage(
                    imageUrl: article.imageUrl!,
                    fit: BoxFit.cover,
                    placeholder: (_, __) => Container(color: const Color(0xFFE5E7EB)),
                    errorWidget: (_, __, ___) => Container(
                      color: const Color(0xFFE5E7EB),
                      alignment: Alignment.center,
                      child: const Icon(Icons.image_not_supported_outlined,
                          color: Color(0xFF9CA3AF)),
                    ),
                  )
                : Container(color: const Color(0xFFE5E7EB)),
          ),
          PositionedDirectional(
            top: 8,
            start: 8,
            child: Material(
              color: Colors.black.withOpacity(0.5),
              shape: const CircleBorder(),
              child: InkWell(
                customBorder: const CircleBorder(),
                onTap: onToggleSave,
                child: Padding(
                  padding: const EdgeInsets.all(8),
                  child: Icon(
                    saved ? Icons.bookmark : Icons.bookmark_border,
                    color: saved ? AppColors.goldBright : Colors.white,
                    size: 18,
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CategoryChip extends StatelessWidget {
  const _CategoryChip({required this.category});
  final Category category;

  @override
  Widget build(BuildContext context) {
    final color = AppColors.categoryColors[category.color] ?? AppColors.accent;
    final label = '${category.icon ?? ''} ${category.name}'.trim();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.12),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontSize: 11,
          fontWeight: FontWeight.w800,
          height: 1.2,
        ),
      ),
    );
  }
}

class _BreakingBadge extends StatelessWidget {
  const _BreakingBadge();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: AppColors.breaking,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: const [
          SizedBox(
            width: 6,
            height: 6,
            child: DecoratedBox(
              decoration: BoxDecoration(color: Colors.white, shape: BoxShape.circle),
            ),
          ),
          SizedBox(width: 4),
          Text(
            'عاجل',
            style: TextStyle(
              color: Colors.white,
              fontSize: 11,
              fontWeight: FontWeight.w800,
              height: 1.2,
            ),
          ),
        ],
      ),
    );
  }
}

class _SourceRow extends StatelessWidget {
  const _SourceRow({required this.article});
  final Article article;

  @override
  Widget build(BuildContext context) {
    final source = article.source;
    final published = article.publishedAt;
    return Row(
      children: [
        if (source != null) ...[
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              color: _parseHex(source.logoColor) ?? AppColors.accent,
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              source.name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: AppColors.textLight,
                fontWeight: FontWeight.w700,
                fontSize: 12,
              ),
            ),
          ),
        ],
        if (source != null && published != null)
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 6),
            child: Text(
              '·',
              style: TextStyle(color: AppColors.textMutedLight, fontSize: 12),
            ),
          ),
        if (published != null)
          Text(
            timeago.format(published, locale: 'ar'),
            style: const TextStyle(
              color: AppColors.textMutedLight,
              fontSize: 12,
            ),
          ),
      ],
    );
  }

  static Color? _parseHex(String? hex) {
    if (hex == null || hex.isEmpty) return null;
    final s = hex.replaceAll('#', '');
    if (s.length != 6) return null;
    final v = int.tryParse(s, radix: 16);
    if (v == null) return null;
    return Color(0xFF000000 | v);
  }
}

class _ActionBar extends StatelessWidget {
  const _ActionBar({
    required this.myReaction,
    required this.likes,
    required this.dislikes,
    required this.shares,
    required this.saved,
    required this.views,
    required this.onLike,
    required this.onDislike,
    required this.onShare,
    required this.onSave,
  });

  final String? myReaction;
  final int likes;
  final int dislikes;
  final int shares;
  final bool saved;
  final int views;
  final VoidCallback onLike;
  final VoidCallback onDislike;
  final VoidCallback onShare;
  final VoidCallback onSave;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      child: Row(
        children: [
          _ActBtn(
            icon: Icons.thumb_up_outlined,
            iconActive: Icons.thumb_up,
            label: likes > 0 ? _format(likes) : null,
            active: myReaction == 'like',
            activeColor: AppColors.accent,
            onTap: onLike,
          ),
          _ActBtn(
            icon: Icons.thumb_down_outlined,
            iconActive: Icons.thumb_down,
            label: dislikes > 0 ? _format(dislikes) : null,
            active: myReaction == 'dislike',
            activeColor: AppColors.textMutedLight,
            onTap: onDislike,
          ),
          const SizedBox(width: 6),
          Container(width: 1, height: 20, color: AppColors.borderLight),
          const SizedBox(width: 6),
          _ActBtn(
            icon: Icons.ios_share_rounded,
            iconActive: Icons.ios_share_rounded,
            label: shares > 0 ? _format(shares) : null,
            active: false,
            activeColor: AppColors.accent2,
            onTap: onShare,
          ),
          _ActBtn(
            icon: Icons.bookmark_border,
            iconActive: Icons.bookmark,
            label: null,
            active: saved,
            activeColor: AppColors.goldBright,
            onTap: onSave,
          ),
          const Spacer(),
          if (views > 0) ...[
            const Icon(Icons.remove_red_eye_outlined,
                size: 14, color: AppColors.textMutedLight),
            const SizedBox(width: 4),
            Text(
              _format(views),
              style: const TextStyle(
                color: AppColors.textMutedLight,
                fontSize: 11,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(width: 6),
          ],
        ],
      ),
    );
  }

  static String _format(int n) {
    if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(1)}M';
    if (n >= 1000) return '${(n / 1000).toStringAsFixed(1)}K';
    return n.toString();
  }
}

class _ActBtn extends StatelessWidget {
  const _ActBtn({
    required this.icon,
    required this.iconActive,
    required this.label,
    required this.active,
    required this.activeColor,
    required this.onTap,
  });

  final IconData icon;
  final IconData iconActive;
  final String? label;
  final bool active;
  final Color activeColor;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = active ? activeColor : AppColors.textMutedLight;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(active ? iconActive : icon, size: 16, color: color),
            if (label != null) ...[
              const SizedBox(width: 4),
              Text(
                label!,
                style: TextStyle(
                  color: color,
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
