import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../data/media_repository.dart';

class AskScreen extends ConsumerStatefulWidget {
  const AskScreen({super.key});

  @override
  ConsumerState<AskScreen> createState() => _AskScreenState();
}

class _AskScreenState extends ConsumerState<AskScreen> {
  final _ctrl = TextEditingController();
  bool _loading = false;
  String _answer = '';
  List<Map<String, dynamic>> _sources = [];
  String? _error;

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  Future<void> _ask() async {
    final q = _ctrl.text.trim();
    if (q.length < 3) return;
    setState(() {
      _loading = true; _answer = ''; _sources = []; _error = null;
    });
    try {
      final res = await ref.read(mediaRepositoryProvider).ask(q);
      setState(() { _answer = res.answer; _sources = res.sources; });
    } catch (e) {
      setState(() => _error = '$e');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('اسأل الذكاء')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
            controller: _ctrl,
            maxLines: 3,
            decoration: const InputDecoration(
              hintText: 'اسأل عن أي خبر أو موضوع...',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: ElevatedButton.icon(
              onPressed: _loading ? null : _ask,
              icon: _loading
                  ? const SizedBox(
                      width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.send),
              label: const Text('اسأل'),
            ),
          ),
          if (_error != null) Padding(
            padding: const EdgeInsets.only(top: 16),
            child: Text(_error!, style: const TextStyle(color: Colors.red)),
          ),
          if (_answer.isNotEmpty) ...[
            const SizedBox(height: 24),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Theme.of(context).cardColor,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Theme.of(context).dividerColor),
              ),
              child: Text(_answer, style: const TextStyle(height: 1.7)),
            ),
            if (_sources.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text('المصادر', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              for (final s in _sources)
                if (s['id'] != null)
                  ListTile(
                    title: Text(s['title']?.toString() ?? '—'),
                    onTap: () => context.push('/article/${s['id']}'),
                  ),
            ],
          ],
        ],
      ),
    );
  }
}
