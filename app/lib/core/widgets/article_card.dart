import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart' show Share;
import 'package:timeago/timeago.dart' as timeago;

import '../models/article.dart';
import '../theme/app_theme.dart';
import '../../features/auth/data/auth_storage.dart';
import '../../features/user/data/user_repository.dart';
import 'comments_sheet.dart';

/// Sky blue color used for source names across the app.
const _kSourceBlue = Color(0xFF38BDF8);

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
          child: compact ? _compactLayout(context, theme) : _fullLayout(context, theme),
        ),
      ),
    );
  }

  Widget _fullLayout(BuildContext context, ThemeData theme) {
    final isDark = theme.brightness == Brightness.dark;
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
          ],
        ),
        const SizedBox(height: 8),
        Text(
          article.title,
          style: TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w800,
            height: 1.5,
            color: isDark ? Colors.white : const Color(0xFF0F172A),
            letterSpacing: -0.2,
          ),
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
        ),
        if (article.excerpt != null && article.excerpt!.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(
            article.excerpt!,
            style: TextStyle(
              fontSize: 13,
              height: 1.6,
              color: isDark ? Colors.white54 : const Color(0xFF64748B),
              fontWeight: FontWeight.w400,
            ),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
        ],

        // ── Source + time row ──
        const SizedBox(height: 10),
        Row(
          children: [
            if (article.source != null) ...[
              _sourceBadge(article.source!.logoLetter ?? '', article.source!.logoColor),
              const SizedBox(width: 6),
              Text(article.source!.name,
                style: const TextStyle(color: _kSourceBlue, fontSize: 12, fontWeight: FontWeight.w600)),
            ],
            const Spacer(),
            if (article.publishedAt != null)
              Text(
                timeago.format(article.publishedAt!, locale: 'ar'),
                style: TextStyle(fontSize: 11, color: isDark ? Colors.white30 : const Color(0xFF94A3B8)),
              ),
          ],
        ),

        const SizedBox(height: 6),

        // ── Interactive action bar ──
        _ActionBar(article: article, isDark: isDark),
      ],
    );
  }

  Widget _compactLayout(BuildContext context, ThemeData theme) {
    final isDark = theme.brightness == Brightness.dark;
    return Column(
      children: [
        Row(
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
                            style: const TextStyle(color: _kSourceBlue, fontSize: 12, fontWeight: FontWeight.w600),
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
        ),
        const SizedBox(height: 6),
        // Interactive action bar for compact too
        _ActionBar(article: article, isDark: isDark, small: true),
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

// ═══════════════════════════════════════════════════════════════
// INTERACTIVE ACTION BAR — Bookmark, Share, Comments/Likes
// ═══════════════════════════════════════════════════════════════

class _ActionBar extends ConsumerStatefulWidget {
  const _ActionBar({required this.article, required this.isDark, this.small = false});
  final Article article;
  final bool isDark;
  final bool small;

  @override
  ConsumerState<_ActionBar> createState() => _ActionBarState();
}

class _ActionBarState extends ConsumerState<_ActionBar> {
  String? _reaction; // null = none, 'like'/'love'/'sad'/'angry'/'wow'/'fire'
  bool _reacting = false;

  static const _reactions = [
    ('like',  '👍'),
    ('love',  '❤️'),
    ('wow',   '😮'),
    ('sad',   '😢'),
    ('angry', '😡'),
    ('fire',  '🔥'),
  ];

  IconData get _reactionIcon =>
      _reaction != null ? Icons.favorite : Icons.favorite_border;

  Color _reactionColor(Color muted) {
    if (_reaction == null) return muted;
    switch (_reaction) {
      case 'love': return const Color(0xFFE91E63);
      case 'sad': return const Color(0xFF42A5F5);
      case 'angry': return const Color(0xFFFF5722);
      case 'wow': return const Color(0xFFFFC107);
      case 'fire': return const Color(0xFFFF9800);
      default: return const Color(0xFFEF4444);
    }
  }

  Future<void> _sendReaction(String reaction) async {
    if (_reacting) return;
    setState(() { _reaction = reaction; _reacting = true; });
    try {
      await ref.read(userRepositoryProvider).react(widget.article.id, reaction);
    } catch (_) {
      if (mounted) setState(() => _reaction = null);
    } finally {
      if (mounted) setState(() => _reacting = false);
    }
  }

  void _showReactionPicker(BuildContext context) {
    if (!AuthStorage.isAuthenticated) {
      _showLoginSnack(context);
      return;
    }
    final RenderBox box = context.findRenderObject() as RenderBox;
    final Offset pos = box.localToGlobal(Offset.zero);
    showMenu<String>(
      context: context,
      position: RelativeRect.fromLTRB(pos.dx, pos.dy - 50, pos.dx + 250, pos.dy),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      items: _reactions.map((r) => PopupMenuItem<String>(
        value: r.$1,
        height: 40,
        padding: const EdgeInsets.symmetric(horizontal: 8),
        child: Text(r.$2, style: const TextStyle(fontSize: 22)),
      )).toList(),
    ).then((value) {
      if (value != null) _sendReaction(value);
    });
  }

  @override
  Widget build(BuildContext context) {
    final bookmarks = ref.watch(bookmarkedIdsProvider);
    final isBookmarked = bookmarks.contains(widget.article.id);
    final muted = widget.isDark ? Colors.white38 : const Color(0xFF94A3B8);
    final iconSize = widget.small ? 17.0 : 19.0;
    final fontSize = widget.small ? 11.0 : 12.0;

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 6),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(
          color: widget.isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFF1F5F9),
        )),
      ),
      child: Row(
        children: [
          // Like / Reaction — tap for like, long press for picker
          GestureDetector(
            onLongPress: () => _showReactionPicker(context),
            child: _ActionButton(
              icon: _reactionIcon,
              color: _reactionColor(muted),
              label: widget.article.viewCount > 0 ? '${widget.article.viewCount}' : '',
              iconSize: iconSize,
              fontSize: fontSize,
              onTap: () async {
                if (!AuthStorage.isAuthenticated) {
                  _showLoginSnack(context);
                  return;
                }
                _sendReaction('like');
              },
            ),
          ),
          const SizedBox(width: 2),

          // Comments
          _ActionButton(
            icon: Icons.mode_comment_outlined,
            color: muted,
            label: widget.article.comments > 0 ? '${widget.article.comments}' : '',
            iconSize: iconSize,
            fontSize: fontSize,
            onTap: () => showCommentsSheet(context, widget.article.id),
          ),
          const SizedBox(width: 2),

          // Bookmark
          _ActionButton(
            icon: isBookmarked ? Icons.bookmark_rounded : Icons.bookmark_outline_rounded,
            color: isBookmarked ? _kSourceBlue : muted,
            iconSize: iconSize,
            fontSize: fontSize,
            onTap: () async {
              if (!AuthStorage.isAuthenticated) {
                _showLoginSnack(context);
                return;
              }
              try {
                await ref.read(bookmarkedIdsProvider.notifier).toggle(widget.article.id);
              } catch (_) {
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('تعذّر تحديث المحفوظات')),
                  );
                }
              }
            },
          ),

          const Spacer(),

          // Share
          _ActionButton(
            icon: Icons.ios_share_rounded,
            color: muted,
            iconSize: iconSize,
            fontSize: fontSize,
            onTap: () async {
              final url = 'https://feedsnews.net/article/${widget.article.slug ?? widget.article.id}';
              await Share.share('${widget.article.title}\n$url');
              // Fire-and-forget share tracking
              ref.read(userRepositoryProvider).trackShare(widget.article.id);
            },
          ),
        ],
      ),
    );
  }

  void _showLoginSnack(BuildContext context) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: const Text('سجّل دخولك أولاً'),
        action: SnackBarAction(
          label: 'تسجيل',
          onPressed: () => context.push('/login'),
        ),
      ),
    );
  }
}

class _ActionButton extends StatelessWidget {
  const _ActionButton({
    required this.icon, required this.color,
    this.label, this.iconSize = 18, this.fontSize = 11,
    required this.onTap,
  });
  final IconData icon;
  final Color color;
  final String? label;
  final double iconSize;
  final double fontSize;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(
            color: isDark ? Colors.white.withOpacity(0.04) : const Color(0xFFF8FAFC),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: iconSize, color: color),
              if (label != null && label!.isNotEmpty) ...[
                const SizedBox(width: 4),
                Text(label!, style: TextStyle(color: color, fontSize: fontSize, fontWeight: FontWeight.w600)),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
