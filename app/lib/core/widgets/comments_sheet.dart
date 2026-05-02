import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:timeago/timeago.dart' as timeago;

import '../../features/auth/data/auth_storage.dart';
import '../../features/user/data/user_repository.dart';
import '../theme/app_theme.dart';

/// Provider that fetches comments for a given article ID.
final _commentsProvider = FutureProvider.family<List<Comment>, int>((ref, articleId) {
  return ref.watch(userRepositoryProvider).comments(articleId);
});

/// Opens a draggable bottom sheet with the article's comments.
void showCommentsSheet(BuildContext context, int articleId) {
  showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
    ),
    builder: (_) => DraggableScrollableSheet(
      initialChildSize: 0.7,
      minChildSize: 0.4,
      maxChildSize: 0.95,
      expand: false,
      builder: (ctx, scrollCtrl) => _CommentsSheet(
        articleId: articleId,
        scrollController: scrollCtrl,
      ),
    ),
  );
}

class _CommentsSheet extends ConsumerStatefulWidget {
  const _CommentsSheet({required this.articleId, required this.scrollController});
  final int articleId;
  final ScrollController scrollController;

  @override
  ConsumerState<_CommentsSheet> createState() => _CommentsSheetState();
}

class _CommentsSheetState extends ConsumerState<_CommentsSheet> {
  final _textCtrl = TextEditingController();
  bool _sending = false;

  @override
  void dispose() {
    _textCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final body = _textCtrl.text.trim();
    if (body.length < 2) return;
    setState(() => _sending = true);
    try {
      await ref.read(userRepositoryProvider).addComment(widget.articleId, body);
      _textCtrl.clear();
      ref.invalidate(_commentsProvider(widget.articleId));
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$e')),
        );
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final comments = ref.watch(_commentsProvider(widget.articleId));

    return Column(
      children: [
        // Drag handle
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 10),
          child: Container(
            width: 40, height: 4,
            decoration: BoxDecoration(
              color: Colors.grey.withOpacity(0.3),
              borderRadius: BorderRadius.circular(2),
            ),
          ),
        ),

        // Title
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            children: [
              Text('التعليقات',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                  color: isDark ? Colors.white : AppColors.textLight)),
              const Spacer(),
              IconButton(
                icon: const Icon(Icons.close),
                onPressed: () => Navigator.of(context).pop(),
              ),
            ],
          ),
        ),
        const Divider(height: 1),

        // Comment list
        Expanded(
          child: comments.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (e, _) => Center(child: Text('$e')),
            data: (list) => list.isEmpty
                ? Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.chat_bubble_outline, size: 48,
                            color: Colors.grey.withOpacity(0.4)),
                        const SizedBox(height: 12),
                        Text('لا توجد تعليقات بعد',
                          style: TextStyle(
                            color: isDark ? Colors.white38 : AppColors.textMutedLight,
                            fontWeight: FontWeight.w600)),
                        const SizedBox(height: 4),
                        Text('كن أول من يعلّق!',
                          style: TextStyle(fontSize: 12,
                            color: isDark ? Colors.white24 : Colors.grey)),
                      ],
                    ),
                  )
                : ListView.separated(
                    controller: widget.scrollController,
                    padding: const EdgeInsets.all(16),
                    itemCount: list.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 12),
                    itemBuilder: (_, i) => _CommentTile(comment: list[i], isDark: isDark),
                  ),
          ),
        ),

        // Input field
        if (AuthStorage.isAuthenticated) ...[
          const Divider(height: 1),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _textCtrl,
                      decoration: InputDecoration(
                        hintText: 'اكتب تعليقك...',
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(24),
                          borderSide: BorderSide.none,
                        ),
                        filled: true,
                        fillColor: isDark ? Colors.white.withOpacity(0.06) : Colors.grey.withOpacity(0.08),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                      ),
                      maxLines: 3,
                      minLines: 1,
                      textInputAction: TextInputAction.send,
                      onSubmitted: (_) => _submit(),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton.filled(
                    onPressed: _sending ? null : _submit,
                    icon: _sending
                        ? const SizedBox(width: 18, height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Icon(Icons.send, size: 20),
                    style: IconButton.styleFrom(
                      backgroundColor: AppColors.primary,
                      foregroundColor: Colors.white,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class _CommentTile extends StatelessWidget {
  const _CommentTile({required this.comment, required this.isDark});
  final Comment comment;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    final initial = (comment.userName ?? '؟').isNotEmpty
        ? comment.userName!.substring(0, 1).toUpperCase()
        : '؟';
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CircleAvatar(
          radius: 16,
          backgroundColor: AppColors.primary.withOpacity(0.15),
          child: Text(initial,
            style: TextStyle(color: AppColors.primary, fontWeight: FontWeight.w700, fontSize: 13)),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Text(comment.userName ?? 'مجهول',
                    style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13,
                      color: isDark ? Colors.white : AppColors.textLight)),
                  const SizedBox(width: 8),
                  if (comment.createdAt != null)
                    Text(timeago.format(comment.createdAt!, locale: 'ar'),
                      style: TextStyle(fontSize: 11,
                        color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                ],
              ),
              const SizedBox(height: 4),
              Text(comment.body,
                style: TextStyle(fontSize: 13, height: 1.6,
                  color: isDark ? Colors.white70 : AppColors.textLight)),
            ],
          ),
        ),
      ],
    );
  }
}
