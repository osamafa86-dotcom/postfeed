import 'dart:async';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:just_audio/just_audio.dart';
import 'package:share_plus/share_plus.dart' show Share;
import 'package:timeago/timeago.dart' as timeago;
import 'package:url_launcher/url_launcher.dart';

import '../../../core/utils/safe_launch.dart';
import '../../../core/models/article.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/comments_sheet.dart';
import '../../../core/widgets/loading_state.dart';
import '../../../core/widgets/section_header.dart';
import '../../auth/data/auth_storage.dart';
import '../../user/data/user_repository.dart';
import '../data/content_repository.dart';

class ArticleScreen extends ConsumerWidget {
  const ArticleScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asy = ref.watch(articleProvider(id));
    final bookmarks = ref.watch(bookmarkedIdsProvider);
    final isBookmarked = bookmarks.contains(id);

    return Scaffold(
      appBar: AppBar(
        actions: [
          asy.maybeWhen(
            data: (d) => Builder(
              builder: (btnCtx) => IconButton(
                icon: const Icon(Icons.share_outlined),
                onPressed: () {
                  final box = btnCtx.findRenderObject() as RenderBox?;
                  Share.share(
                    d.article.title +
                        (d.article.sourceUrl != null ? '\n${d.article.sourceUrl}' : ''),
                    sharePositionOrigin: box != null
                        ? box.localToGlobal(Offset.zero) & box.size
                        : null,
                  );
                },
              ),
            ),
            orElse: () => const SizedBox.shrink(),
          ),
          IconButton(
            icon: Icon(isBookmarked ? Icons.bookmark : Icons.bookmark_outline),
            color: isBookmarked ? AppColors.primary : null,
            onPressed: () async {
              if (!AuthStorage.isAuthenticated) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: const Text('سجّل دخولك لحفظ المقال'),
                    action: SnackBarAction(label: 'دخول', onPressed: () => context.push('/login')),
                  ),
                );
                return;
              }
              try {
                await ref.read(bookmarkedIdsProvider.notifier).toggle(id);
              } catch (_) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('تعذّر حفظ المقال — حاول لاحقاً')),
                  );
                }
              }
            },
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(2),
          child: asy.maybeWhen(
            data: (_) => const _ReadProgressBar(),
            orElse: () => const SizedBox.shrink(),
          ),
        ),
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

// ═══════════════════════════════════════════════════════════════
// READ PROGRESS BAR
// ═══════════════════════════════════════════════════════════════

class _ReadProgressBar extends StatefulWidget {
  const _ReadProgressBar();
  @override
  State<_ReadProgressBar> createState() => _ReadProgressBarState();
}

class _ReadProgressBarState extends State<_ReadProgressBar> {
  double _progress = 0;
  ScrollController? _controller;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final controller = PrimaryScrollController.of(context);
      if (_controller == controller) return;
      _controller?.removeListener(_onScroll);
      _controller = controller;
      _controller?.addListener(_onScroll);
    });
  }

  void _onScroll() {
    final controller = _controller;
    if (controller == null || !controller.hasClients) return;
    final max = controller.position.maxScrollExtent;
    if (max <= 0) return;
    final progress = (controller.offset / max).clamp(0.0, 1.0);
    if (mounted && (progress - _progress).abs() > 0.005) {
      setState(() => _progress = progress);
    }
  }

  @override
  void dispose() {
    _controller?.removeListener(_onScroll);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return LinearProgressIndicator(
      value: _progress,
      minHeight: 2,
      backgroundColor: Colors.transparent,
      valueColor: const AlwaysStoppedAnimation<Color>(AppColors.primary),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// ARTICLE BODY
// ═══════════════════════════════════════════════════════════════

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

              const SizedBox(height: 14),

              // TTS hidden until the production server has a TTS provider key
              // configured. _TtsPlayer is kept below for the next release.

              // ── Quick actions row ──
              _QuickActions(article: article),

              const Divider(height: 32),

              // ── AI summary + key points (when available) ──
              if (article.aiSummary != null || article.aiKeyPoints.isNotEmpty) ...[
                _ArticleAiBrief(
                  summary: article.aiSummary,
                  keyPoints: article.aiKeyPoints,
                ),
                const SizedBox(height: 20),
              ] else if (article.excerpt != null && article.excerpt!.isNotEmpty) ...[
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
                  onPressed: () => safeLaunch(context, article.sourceUrl!),
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

// ═══════════════════════════════════════════════════════════════
// TTS PLAYER
// ═══════════════════════════════════════════════════════════════

class _TtsPlayer extends ConsumerStatefulWidget {
  const _TtsPlayer({required this.articleId});
  final int articleId;

  @override
  ConsumerState<_TtsPlayer> createState() => _TtsPlayerState();
}

class _TtsPlayerState extends ConsumerState<_TtsPlayer> {
  AudioPlayer? _player;
  _TtsState _state = _TtsState.idle;
  Duration _position = Duration.zero;
  Duration _duration = Duration.zero;
  final List<StreamSubscription> _subs = [];

  @override
  void dispose() {
    for (final sub in _subs) {
      sub.cancel();
    }
    _player?.dispose();
    super.dispose();
  }

  Future<void> _play() async {
    if (_state == _TtsState.loading) return;

    // If already loaded, just toggle play/pause
    if (_player != null) {
      if (_player!.playing) {
        _player!.pause();
      } else {
        _player!.play();
      }
      return;
    }

    setState(() => _state = _TtsState.loading);

    try {
      final result = await ref.read(contentRepositoryProvider).tts(widget.articleId);
      final player = AudioPlayer();
      await player.setUrl(result.audioUrl);

      _subs.add(player.playerStateStream.listen((s) {
        if (!mounted) return;
        setState(() {
          if (s.processingState == ProcessingState.completed) {
            _state = _TtsState.idle;
            _position = Duration.zero;
            player.seek(Duration.zero);
            player.pause();
          } else if (s.playing) {
            _state = _TtsState.playing;
          } else {
            _state = _TtsState.paused;
          }
        });
      }));

      _subs.add(player.positionStream.listen((p) {
        if (mounted) setState(() => _position = p);
      }));

      _subs.add(player.durationStream.listen((d) {
        if (mounted && d != null) setState(() => _duration = d);
      }));

      _player = player;
      player.play();
    } catch (e) {
      if (mounted) {
        setState(() => _state = _TtsState.idle);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تعذّر تشغيل القراءة الصوتية')),
        );
      }
    }
  }

  String _fmt(Duration d) {
    final m = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: NeoDecoration.soft(isDark: isDark, radius: 14),
      child: Row(
        children: [
          // Play/Pause button
          GestureDetector(
            onTap: _play,
            child: Container(
              width: 38, height: 38,
              decoration: BoxDecoration(
                color: AppColors.primary,
                borderRadius: BorderRadius.circular(10),
              ),
              alignment: Alignment.center,
              child: _state == _TtsState.loading
                  ? const SizedBox(width: 18, height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Icon(
                      _state == _TtsState.playing ? Icons.pause : Icons.headphones,
                      color: Colors.white, size: 20),
            ),
          ),
          const SizedBox(width: 10),

          // Label + progress
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _state == _TtsState.idle ? 'استمع للمقال' : 'جارٍ القراءة...',
                  style: TextStyle(
                    fontSize: 12, fontWeight: FontWeight.w700,
                    color: isDark ? Colors.white : AppColors.textLight,
                  ),
                ),
                if (_state != _TtsState.idle && _duration.inSeconds > 0) ...[
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Text(_fmt(_position),
                        style: TextStyle(fontSize: 10,
                          color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                      Expanded(
                        child: SliderTheme(
                          data: SliderThemeData(
                            trackHeight: 3,
                            thumbShape: const RoundSliderThumbShape(enabledThumbRadius: 5),
                            activeTrackColor: AppColors.primary,
                            inactiveTrackColor: isDark ? Colors.white12 : const Color(0xFFE0F2FE),
                            thumbColor: AppColors.primary,
                            overlayShape: SliderComponentShape.noOverlay,
                          ),
                          child: Slider(
                            value: _position.inMilliseconds.toDouble().clamp(0, _duration.inMilliseconds.toDouble()),
                            min: 0,
                            max: _duration.inMilliseconds.toDouble().clamp(1, double.infinity),
                            onChanged: (v) => _player?.seek(Duration(milliseconds: v.toInt())),
                          ),
                        ),
                      ),
                      Text(_fmt(_duration),
                        style: TextStyle(fontSize: 10,
                          color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

enum _TtsState { idle, loading, playing, paused }

// ═══════════════════════════════════════════════════════════════
// QUICK ACTIONS ROW (comments, reactions)
// ═══════════════════════════════════════════════════════════════

class _QuickActions extends StatelessWidget {
  const _QuickActions({required this.article});
  final Article article;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final muted = isDark ? Colors.white54 : AppColors.textMutedLight;

    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Row(
        children: [
          _chip(Icons.chat_bubble_outline, 'تعليقات', muted, isDark,
            onTap: () => showCommentsSheet(context, article.id)),
          const SizedBox(width: 10),
          _chip(Icons.compare_arrows, 'مقارنة التغطية', muted, isDark,
            onTap: () => context.push('/clusters')),
        ],
      ),
    );
  }

  Widget _chip(IconData icon, String label, Color muted, bool isDark, {VoidCallback? onTap}) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: NeoDecoration.soft(isDark: isDark, radius: 10),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 14, color: muted),
            const SizedBox(width: 4),
            Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: muted)),
          ],
        ),
      ),
    );
  }
}

/// AI-generated brief shown before the article body.
/// Combines the smart-summary block plus a bulleted "أهم النقاط" list
/// when key points were extracted. The article's full content still
/// follows below, so this is a read-aid not a replacement.
class _ArticleAiBrief extends StatelessWidget {
  const _ArticleAiBrief({this.summary, this.keyPoints = const []});
  final String? summary;
  final List<String> keyPoints;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = AppColors.primary;
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
      decoration: BoxDecoration(
        color: isDark ? accent.withOpacity(0.10) : accent.withOpacity(0.06),
        borderRadius: BorderRadius.circular(14),
        border: Border(
          right: BorderSide(color: accent.withOpacity(isDark ? 0.55 : 0.45), width: 4),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text('✨', style: TextStyle(fontSize: 14, color: accent)),
              const SizedBox(width: 6),
              Text(
                'ملخّص ذكي',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                  color: accent,
                  letterSpacing: 0.3,
                ),
              ),
            ],
          ),
          if (summary != null && summary!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              summary!,
              style: TextStyle(
                fontSize: 15,
                height: 1.75,
                fontWeight: FontWeight.w500,
                color: isDark ? Colors.white : AppColors.textLight,
              ),
            ),
          ],
          if (keyPoints.isNotEmpty) ...[
            const SizedBox(height: 14),
            Text(
              'أبرز النقاط',
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w800,
                color: accent.withOpacity(0.85),
                letterSpacing: 0.3,
              ),
            ),
            const SizedBox(height: 6),
            for (final point in keyPoints.take(5))
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 7),
                      child: Container(
                        width: 5,
                        height: 5,
                        decoration: BoxDecoration(
                          color: accent.withOpacity(0.75),
                          shape: BoxShape.circle,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        point,
                        style: TextStyle(
                          fontSize: 13.5,
                          height: 1.7,
                          color: isDark ? Colors.white70 : AppColors.textLight,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ],
      ),
    );
  }
}
