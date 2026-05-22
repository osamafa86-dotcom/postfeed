import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../data/media_repository.dart';

class AskScreen extends ConsumerStatefulWidget {
  const AskScreen({super.key});

  @override
  ConsumerState<AskScreen> createState() => _AskScreenState();
}

class _AskScreenState extends ConsumerState<AskScreen> {
  final _ctrl = TextEditingController();
  final _scrollCtrl = ScrollController();
  final List<_ChatMessage> _messages = [];
  bool _sending = false;

  static const _starterQuestions = [
    'ما أبرز الأخبار اليوم؟',
    'ما آخر تطورات الوضع في غزة؟',
    'لخّص أحدث أخبار الاقتصاد',
    'ماذا يحدث في الذكاء الاصطناعي مؤخراً؟',
  ];

  @override
  void dispose() {
    _ctrl.dispose();
    _scrollCtrl.dispose();
    super.dispose();
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollCtrl.hasClients) return;
      _scrollCtrl.animateTo(
        _scrollCtrl.position.maxScrollExtent,
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOut,
      );
    });
  }

  Future<void> _send(String text) async {
    final q = text.trim();
    if (q.length < 3 || _sending) return;
    setState(() {
      _messages.add(_ChatMessage.user(q));
      _messages.add(_ChatMessage.assistantLoading());
      _sending = true;
      _ctrl.clear();
    });
    _scrollToBottom();
    try {
      final res = await ref.read(mediaRepositoryProvider).ask(q);
      if (!mounted) return;
      setState(() {
        _messages.removeLast();
        _messages.add(_ChatMessage.assistant(
          text: res.answer.isNotEmpty ? res.answer : 'لم أجد إجابة مناسبة.',
          sources: res.sources,
        ));
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _messages.removeLast();
        _messages.add(_ChatMessage.assistant(
          text: 'تعذّر الحصول على الإجابة. تأكد من اتصالك بالإنترنت وحاول مرة أخرى.',
          sources: const [],
          isError: true,
        ));
      });
    } finally {
      if (mounted) setState(() => _sending = false);
      _scrollToBottom();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(
        title: const Text('اسأل الذكاء'),
        actions: [
          if (_messages.isNotEmpty)
            IconButton(
              icon: const Icon(Icons.refresh),
              tooltip: 'محادثة جديدة',
              onPressed: _sending ? null : () => setState(_messages.clear),
            ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: _messages.isEmpty
                ? _EmptyChatHint(
                    starters: _starterQuestions,
                    onPick: _send,
                  )
                : ListView.builder(
                    controller: _scrollCtrl,
                    padding: const EdgeInsets.fromLTRB(12, 16, 12, 16),
                    itemCount: _messages.length,
                    itemBuilder: (_, i) => _MessageBubble(message: _messages[i]),
                  ),
          ),
          SafeArea(
            top: false,
            child: Container(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
              decoration: BoxDecoration(
                color: theme.cardColor,
                border: Border(top: BorderSide(color: theme.dividerColor, width: 0.5)),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Expanded(
                    child: TextField(
                      controller: _ctrl,
                      minLines: 1,
                      maxLines: 4,
                      textInputAction: TextInputAction.send,
                      onSubmitted: _send,
                      decoration: InputDecoration(
                        hintText: 'اسأل عن أي خبر أو موضوع…',
                        filled: true,
                        fillColor: isDark ? Colors.white10 : Colors.grey.shade100,
                        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(22),
                          borderSide: BorderSide.none,
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Material(
                    color: AppColors.primary,
                    shape: const CircleBorder(),
                    child: InkWell(
                      customBorder: const CircleBorder(),
                      onTap: _sending ? null : () => _send(_ctrl.text),
                      child: SizedBox(
                        width: 44,
                        height: 44,
                        child: _sending
                            ? const Center(
                                child: SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                ),
                              )
                            : const Icon(Icons.arrow_upward, color: Colors.white, size: 22),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ─── Models ────────────────────────────────────────────────────────────

class _ChatMessage {
  _ChatMessage._({
    required this.isUser,
    required this.text,
    this.sources = const [],
    this.loading = false,
    this.isError = false,
  });
  final bool isUser;
  final String text;
  final List<Map<String, dynamic>> sources;
  final bool loading;
  final bool isError;

  factory _ChatMessage.user(String text) => _ChatMessage._(isUser: true, text: text);
  factory _ChatMessage.assistantLoading() =>
      _ChatMessage._(isUser: false, text: '', loading: true);
  factory _ChatMessage.assistant({
    required String text,
    required List<Map<String, dynamic>> sources,
    bool isError = false,
  }) =>
      _ChatMessage._(isUser: false, text: text, sources: sources, isError: isError);
}

// ─── Chat Bubble ───────────────────────────────────────────────────────

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({required this.message});
  final _ChatMessage message;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final isUser = message.isUser;
    final bg = isUser
        ? AppColors.primary
        : (isDark ? Colors.white.withOpacity(0.06) : Colors.grey.shade100);
    final fg = isUser
        ? Colors.white
        : (isDark ? Colors.white : AppColors.textLight);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        mainAxisAlignment: isUser ? MainAxisAlignment.end : MainAxisAlignment.start,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (!isUser) ...[
            Container(
              width: 32,
              height: 32,
              decoration: BoxDecoration(
                color: AppColors.primary.withOpacity(0.15),
                borderRadius: BorderRadius.circular(10),
              ),
              alignment: Alignment.center,
              child: Text('✨', style: TextStyle(fontSize: 16, color: AppColors.primary)),
            ),
            const SizedBox(width: 8),
          ],
          Flexible(
            child: Column(
              crossAxisAlignment: isUser ? CrossAxisAlignment.end : CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: bg,
                    borderRadius: BorderRadius.only(
                      topLeft: const Radius.circular(16),
                      topRight: const Radius.circular(16),
                      bottomLeft: Radius.circular(isUser ? 16 : 4),
                      bottomRight: Radius.circular(isUser ? 4 : 16),
                    ),
                  ),
                  child: message.loading
                      ? const _TypingIndicator()
                      : Text(
                          message.text,
                          style: TextStyle(
                            color: message.isError ? Colors.red.shade400 : fg,
                            fontSize: 14.5,
                            height: 1.7,
                          ),
                        ),
                ),
                if (message.sources.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  _SourcesChips(sources: message.sources),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TypingIndicator extends StatefulWidget {
  const _TypingIndicator();
  @override
  State<_TypingIndicator> createState() => _TypingIndicatorState();
}

class _TypingIndicatorState extends State<_TypingIndicator>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ac = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  )..repeat();

  @override
  void dispose() {
    _ac.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _ac,
      builder: (_, __) {
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(3, (i) {
            final t = ((_ac.value + i / 3) % 1.0);
            final opacity = (1 - (t - 0.5).abs() * 2).clamp(0.3, 1.0);
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 2.5),
              child: Container(
                width: 7,
                height: 7,
                decoration: BoxDecoration(
                  color: AppColors.primary.withOpacity(opacity),
                  shape: BoxShape.circle,
                ),
              ),
            );
          }),
        );
      },
    );
  }
}

// ─── Sources Chips ─────────────────────────────────────────────────────

class _SourcesChips extends StatelessWidget {
  const _SourcesChips({required this.sources});
  final List<Map<String, dynamic>> sources;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final s in sources.take(5))
          if (s['id'] != null)
            InkWell(
              borderRadius: BorderRadius.circular(20),
              onTap: () => context.push('/article/${s['id']}'),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: isDark ? Colors.white12 : Colors.white,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: AppColors.primary.withOpacity(0.35),
                    width: 1,
                  ),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.article_outlined, size: 13, color: AppColors.primary),
                    const SizedBox(width: 5),
                    Flexible(
                      child: Text(
                        (s['title']?.toString() ?? '').trim(),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                          color: AppColors.primary,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
      ],
    );
  }
}

// ─── Empty State with Starters ─────────────────────────────────────────

class _EmptyChatHint extends StatelessWidget {
  const _EmptyChatHint({required this.starters, required this.onPick});
  final List<String> starters;
  final ValueChanged<String> onPick;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Column(
        children: [
          const SizedBox(height: 40),
          Container(
            width: 84,
            height: 84,
            decoration: BoxDecoration(
              color: AppColors.primary.withOpacity(isDark ? 0.15 : 0.08),
              shape: BoxShape.circle,
            ),
            alignment: Alignment.center,
            child: Text('✨', style: TextStyle(fontSize: 38, color: AppColors.primary)),
          ),
          const SizedBox(height: 16),
          Text(
            'اسأل أي شيء عن الأخبار',
            style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 6),
          Text(
            'يبحث الذكاء عبر آلاف المقالات ويُجيب مع ذكر المصادر.',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 13,
              height: 1.6,
              color: isDark ? Colors.white54 : AppColors.textMutedLight,
            ),
          ),
          const SizedBox(height: 28),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: Text(
              'جرّب هذه الأسئلة',
              style: TextStyle(
                fontSize: 11.5,
                fontWeight: FontWeight.w800,
                color: isDark ? Colors.white54 : AppColors.textMutedLight,
                letterSpacing: 0.5,
              ),
            ),
          ),
          const SizedBox(height: 10),
          for (final s in starters)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: () => onPick(s),
                child: Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
                  decoration: BoxDecoration(
                    color: isDark ? Colors.white.withOpacity(0.05) : Colors.grey.shade50,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: isDark ? Colors.white12 : Colors.grey.shade200,
                    ),
                  ),
                  child: Row(
                    children: [
                      Icon(Icons.bolt_outlined, size: 16, color: AppColors.primary),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          s,
                          style: TextStyle(
                            fontSize: 13.5,
                            fontWeight: FontWeight.w600,
                            color: isDark ? Colors.white70 : AppColors.textLight,
                          ),
                        ),
                      ),
                      Icon(
                        Icons.arrow_back_ios,
                        size: 12,
                        color: isDark ? Colors.white38 : Colors.grey,
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
