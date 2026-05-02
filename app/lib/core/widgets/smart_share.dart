import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:share_plus/share_plus.dart';

import '../theme/app_theme.dart';

/// Shows a preview of the share card, then shares it as an image.
class SmartShare {
  static Future<void> shareArticle(
    BuildContext context, {
    required String title,
    required String? sourceName,
    required String? categoryName,
    required String? imageUrl,
    required String? sourceUrl,
  }) async {
    final card = _ShareCard(
      title: title,
      sourceName: sourceName ?? '',
      categoryName: categoryName ?? '',
    );

    // Show bottom sheet with preview + share button
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => _ShareSheet(
        card: card,
        title: title,
        sourceUrl: sourceUrl,
      ),
    );
  }
}

class _ShareSheet extends StatefulWidget {
  const _ShareSheet({required this.card, required this.title, this.sourceUrl});
  final _ShareCard card;
  final String title;
  final String? sourceUrl;

  @override
  State<_ShareSheet> createState() => _ShareSheetState();
}

class _ShareSheetState extends State<_ShareSheet> {
  final _repaintKey = GlobalKey();
  bool _sharing = false;

  Future<void> _doShare() async {
    setState(() => _sharing = true);
    try {
      // Capture the card as an image
      final boundary = _repaintKey.currentContext?.findRenderObject() as RenderRepaintBoundary?;
      if (boundary == null) return;

      final image = await boundary.toImage(pixelRatio: 3.0);
      final byteData = await image.toByteData(format: ui.ImageByteFormat.png);
      if (byteData == null) return;

      final pngBytes = byteData.buffer.asUint8List();

      await Share.shareXFiles(
        [XFile.fromData(pngBytes, mimeType: 'image/png', name: 'feed_news.png')],
        text: '${widget.title}\n\n${widget.sourceUrl ?? 'عبر تطبيق فيد نيوز'}',
      );
    } catch (_) {
      // Fallback to text share
      Share.share('${widget.title}\n${widget.sourceUrl ?? ''}');
    }
    if (mounted) setState(() => _sharing = false);
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Theme.of(context).scaffoldBackgroundColor,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: EdgeInsets.fromLTRB(20, 12, 20, MediaQuery.of(context).padding.bottom + 20),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // Handle bar
          Container(
            width: 40, height: 4,
            decoration: BoxDecoration(
              color: Theme.of(context).dividerColor,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(height: 20),

          // Preview
          RepaintBoundary(
            key: _repaintKey,
            child: widget.card,
          ),
          const SizedBox(height: 20),

          // Share buttons
          Row(
            children: [
              Expanded(
                child: SizedBox(
                  height: 50,
                  child: ElevatedButton.icon(
                    onPressed: _sharing ? null : _doShare,
                    icon: _sharing
                        ? const SizedBox(width: 18, height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Icon(Icons.image_outlined),
                    label: const Text('مشاركة كصورة', style: TextStyle(fontWeight: FontWeight.w700)),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.primary,
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              SizedBox(
                height: 50,
                child: ElevatedButton.icon(
                  onPressed: () {
                    Share.share('${widget.title}\n${widget.sourceUrl ?? ''}');
                    Navigator.pop(context);
                  },
                  icon: const Icon(Icons.text_fields),
                  label: const Text('نص', style: TextStyle(fontWeight: FontWeight.w700)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Theme.of(context).dividerColor,
                    foregroundColor: Theme.of(context).textTheme.bodyLarge?.color,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    elevation: 0,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ShareCard extends StatelessWidget {
  const _ShareCard({
    required this.title,
    required this.sourceName,
    required this.categoryName,
  });

  final String title;
  final String sourceName;
  final String categoryName;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
          colors: [Color(0xFF1A1A2E), Color(0xFF16213E), Color(0xFF0F3460)],
        ),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          // Logo + branding
          Row(
            children: [
              Container(
                width: 36, height: 36,
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(10),
                ),
                alignment: Alignment.center,
                child: const Text('ف', style: TextStyle(
                  color: AppColors.primary, fontWeight: FontWeight.w900, fontSize: 20)),
              ),
              const SizedBox(width: 10),
              const Text('فيد نيوز', style: TextStyle(
                color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16)),
              const Spacer(),
              if (categoryName.isNotEmpty)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(
                    color: AppColors.primary,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(categoryName, style: const TextStyle(
                    color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                ),
            ],
          ),
          const SizedBox(height: 24),

          // Decorative line
          Container(
            width: 40, height: 4,
            decoration: BoxDecoration(
              gradient: LinearGradient(colors: [Colors.amber.shade400, Colors.orange.shade600]),
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(height: 16),

          // Title
          Text(
            title,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 20,
              fontWeight: FontWeight.w800,
              height: 1.6,
            ),
          ),
          const SizedBox(height: 20),

          // Source + watermark
          Row(
            children: [
              if (sourceName.isNotEmpty)
                Text(sourceName, style: TextStyle(
                  color: Colors.white.withOpacity(0.6), fontSize: 13, fontWeight: FontWeight.w500)),
              const Spacer(),
              Text('feedsnews.net', style: TextStyle(
                color: Colors.white.withOpacity(0.3), fontSize: 11)),
            ],
          ),
        ],
      ),
    );
  }
}
