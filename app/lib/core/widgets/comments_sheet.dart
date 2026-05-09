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
    final currentUserId = AuthStorage.userId;

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
                    itemBuilder: (_, i) => _CommentTile(
                      comment: list[i],
                      isDark: isDark,
                      currentUserId: currentUserId,
                      onDelete: () => _onDelete(list[i]),
                      onReport: () => _onReport(list[i]),
                      onBlock: () => _onBlock(list[i]),
                    ),
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
          // Community Guidelines reminder (Apple App Store requirement).
          Padding(
            padding: const EdgeInsets.only(bottom: 6, right: 16, left: 16),
            child: Text(
              'بالنشر فإنّك توافق على شروط الاستخدام (سياسة عدم تهاون مع المحتوى المسيء).',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 10,
                color: isDark ? Colors.white38 : Colors.grey,
              ),
            ),
          ),
        ],
      ],
    );
  }

  // ─── Moderation actions ──────────────────────────────────────────

  Future<void> _onDelete(Comment c) async {
    final confirmed = await _confirm(
      title: 'حذف التعليق',
      message: 'هل تريد حذف تعليقك؟ لا يمكن التراجع عن هذا الإجراء.',
      confirmLabel: 'حذف',
      destructive: true,
    );
    if (confirmed != true) return;
    try {
      await ref.read(userRepositoryProvider).deleteComment(c.id);
      if (!mounted) return;
      ref.invalidate(_commentsProvider(widget.articleId));
      _toast('تمّ حذف التعليق');
    } catch (e) {
      _toast('تعذّر الحذف: $e');
    }
  }

  Future<void> _onReport(Comment c) async {
    final reason = await showModalBottomSheet<CommentReportReason>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Text(
                'سبب الإبلاغ',
                style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
              ),
            ),
            for (final r in CommentReportReason.values)
              ListTile(
                title: Text(r.label),
                onTap: () => Navigator.of(ctx).pop(r),
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (reason == null || !mounted) return;
    try {
      await ref.read(userRepositoryProvider).reportComment(c.id, reason: reason);
      if (!mounted) return;
      _toast('تمّ استلام البلاغ. سيراجعه فريقنا خلال ٢٤ ساعة.');
    } catch (e) {
      _toast('تعذّر الإبلاغ: $e');
    }
  }

  Future<void> _onBlock(Comment c) async {
    final uid = c.userId;
    if (uid == null) return;
    final confirmed = await _confirm(
      title: 'حظر هذا المستخدم؟',
      message: 'لن تظهر لك تعليقاته بعد الآن. يمكنك إلغاء الحظر من إعدادات الحساب.',
      confirmLabel: 'حظر',
      destructive: true,
    );
    if (confirmed != true || !mounted) return;
    try {
      await ref.read(userRepositoryProvider).blockUser(uid);
      if (!mounted) return;
      ref.invalidate(_commentsProvider(widget.articleId));
      _toast('تمّ حظر المستخدم');
    } catch (e) {
      _toast('تعذّر الحظر: $e');
    }
  }

  Future<bool?> _confirm({
    required String title,
    required String message,
    required String confirmLabel,
    bool destructive = false,
  }) {
    return showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: Text(message),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('إلغاء'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: TextButton.styleFrom(
              foregroundColor: destructive ? Colors.red : null,
            ),
            child: Text(confirmLabel),
          ),
        ],
      ),
    );
  }

  void _toast(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }
}

class _CommentTile extends StatelessWidget {
  const _CommentTile({
    required this.comment,
    required this.isDark,
    required this.currentUserId,
    required this.onDelete,
    required this.onReport,
    required this.onBlock,
  });

  final Comment comment;
  final bool isDark;
  final int? currentUserId;
  final VoidCallback onDelete;
  final VoidCallback onReport;
  final VoidCallback onBlock;

  bool get _isMine =>
      currentUserId != null && comment.userId != null && currentUserId == comment.userId;

  @override
  Widget build(BuildContext context) {
    final name = comment.userName;
    final initial = (name != null && name.isNotEmpty)
        ? name.substring(0, 1).toUpperCase()
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
                  Text(name ?? 'مجهول',
                    style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13,
                      color: isDark ? Colors.white : AppColors.textLight)),
                  const SizedBox(width: 8),
                  if (comment.createdAt != null)
                    Text(timeago.format(comment.createdAt!, locale: 'ar'),
                      style: TextStyle(fontSize: 11,
                        color: isDark ? Colors.white38 : AppColors.textMutedLight)),
                  const Spacer(),
                  // Apple Guideline 1.2: every UGC item needs a moderation menu.
                  PopupMenuButton<String>(
                    tooltip: 'خيارات',
                    icon: Icon(Icons.more_horiz,
                        size: 18,
                        color: isDark ? Colors.white38 : Colors.grey),
                    padding: EdgeInsets.zero,
                    onSelected: (value) {
                      switch (value) {
                        case 'delete':
                          onDelete();
                          break;
                        case 'report':
                          onReport();
                          break;
                        case 'block':
                          onBlock();
                          break;
                      }
                    },
                    itemBuilder: (_) => [
                      if (_isMine)
                        const PopupMenuItem(
                          value: 'delete',
                          child: Row(children: [
                            Icon(Icons.delete_outline, size: 18, color: Colors.red),
                            SizedBox(width: 8),
                            Text('حذف تعليقي', style: TextStyle(color: Colors.red)),
                          ]),
                        ),
                      if (!_isMine && currentUserId != null) ...[
                        const PopupMenuItem(
                          value: 'report',
                          child: Row(children: [
                            Icon(Icons.flag_outlined, size: 18),
                            SizedBox(width: 8),
                            Text('بلّغ عن التعليق'),
                          ]),
                        ),
                        const PopupMenuItem(
                          value: 'block',
                          child: Row(children: [
                            Icon(Icons.block, size: 18),
                            SizedBox(width: 8),
                            Text('حظر هذا المستخدم'),
                          ]),
                        ),
                      ],
                      if (currentUserId == null)
                        const PopupMenuItem(
                          enabled: false,
                          child: Text('سجّل دخولك للإبلاغ'),
                        ),
                    ],
                  ),
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
