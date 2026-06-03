import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/storage/recent_searches_store.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/article_card.dart';
import '../../../core/widgets/loading_state.dart';
import '../data/content_repository.dart';

class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key, this.initialQuery = ''});
  final String initialQuery;

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  late final TextEditingController _ctrl;
  final _focus = FocusNode();
  Timer? _debounce;

  // The query the results list is actually bound to. Updated after the
  // debounce fires (live typing) or immediately on submit.
  String _query = '';
  // Live mirror of the text field, drives the clear button + which
  // panel (suggestions vs. results) is shown.
  String _typed = '';
  List<String> _recent = const [];

  @override
  void initState() {
    super.initState();
    _typed = widget.initialQuery;
    _query = widget.initialQuery.trim();
    _ctrl = TextEditingController(text: _typed);
    _loadRecent();
    // If we arrived with a query (e.g. tapped a trending tag), record it.
    if (_query.length >= 2) {
      RecentSearchesStore.add(_query).then(_setRecent);
    } else {
      // Empty arrival → focus the field so the keyboard opens.
      WidgetsBinding.instance.addPostFrameCallback((_) => _focus.requestFocus());
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _ctrl.dispose();
    _focus.dispose();
    super.dispose();
  }

  Future<void> _loadRecent() async => _setRecent(await RecentSearchesStore.load());

  void _setRecent(List<String> r) {
    if (mounted) setState(() => _recent = r);
  }

  void _onChanged(String v) {
    setState(() => _typed = v);
    _debounce?.cancel();
    final q = v.trim();
    // Debounce so we don't fire a request on every keystroke. 350ms is
    // long enough to coalesce a fast typist, short enough to feel live.
    _debounce = Timer(const Duration(milliseconds: 350), () {
      if (!mounted) return;
      setState(() => _query = q.length >= 2 ? q : '');
    });
  }

  void _submit(String v) {
    final q = v.trim();
    _debounce?.cancel();
    setState(() => _query = q.length >= 2 ? q : '');
    if (q.length >= 2) RecentSearchesStore.add(q).then(_setRecent);
    _focus.unfocus();
  }

  void _runSuggestion(String q) {
    _ctrl.text = q;
    _ctrl.selection = TextSelection.collapsed(offset: q.length);
    _submit(q);
  }

  void _clearField() {
    _ctrl.clear();
    setState(() {
      _typed = '';
      _query = '';
    });
    _focus.requestFocus();
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: TextField(
          controller: _ctrl,
          focusNode: _focus,
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(
            hintText: 'ابحث في الأخبار...',
            border: InputBorder.none,
            suffixIcon: _typed.isEmpty
                ? null
                : IconButton(
                    icon: const Icon(Icons.close, size: 20),
                    tooltip: 'مسح',
                    onPressed: _clearField,
                  ),
          ),
          onChanged: _onChanged,
          onSubmitted: _submit,
        ),
      ),
      body: _query.length < 2 ? _suggestionsPanel(isDark) : _resultsPanel(),
    );
  }

  // ── Suggestions: recent searches + trending topics ──────────────────
  Widget _suggestionsPanel(bool isDark) {
    final muted = isDark ? AppColors.textMutedDark : AppColors.textMutedLight;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_recent.isNotEmpty) ...[
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              _sectionLabel('عمليات البحث الأخيرة', Icons.history, muted),
              TextButton(
                onPressed: () async {
                  await RecentSearchesStore.clear();
                  _setRecent(const []);
                },
                child: const Text('مسح الكل'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final q in _recent)
                InputChip(
                  label: Text(q),
                  avatar: const Icon(Icons.history, size: 16),
                  onPressed: () => _runSuggestion(q),
                  onDeleted: () async => _setRecent(await RecentSearchesStore.remove(q)),
                ),
            ],
          ),
          const SizedBox(height: 24),
        ],
        _sectionLabel('المواضيع الرائجة', Icons.trending_up, muted),
        const SizedBox(height: 8),
        Consumer(builder: (_, ref, __) {
          final asy = ref.watch(_trendingTagsProvider);
          return asy.when(
            loading: () => const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Align(
                alignment: Alignment.centerRight,
                child: SizedBox(
                  width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ),
            ),
            error: (_, __) => const SizedBox.shrink(),
            data: (tags) => tags.isEmpty
                ? Text('لا مواضيع رائجة حالياً', style: TextStyle(color: muted))
                : Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final t in tags)
                        ActionChip(
                          label: Text('# ${t.title}',
                              style: const TextStyle(fontWeight: FontWeight.w700)),
                          onPressed: () => _runSuggestion(t.title),
                        ),
                    ],
                  ),
          );
        }),
      ],
    );
  }

  Widget _sectionLabel(String text, IconData icon, Color color) => Row(
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 6),
          Text(text,
              style: TextStyle(fontWeight: FontWeight.w700, color: color, fontSize: 13)),
        ],
      );

  // ── Results ──────────────────────────────────────────────────────────
  Widget _resultsPanel() {
    return Consumer(builder: (_, ref, __) {
      final asy = ref.watch(_searchProvider(_query));
      return asy.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(_searchProvider(_query)),
        ),
        data: (p) {
          if (p.items.isEmpty) {
            return EmptyView(
              icon: Icons.search_off,
              message: 'لا نتائج للبحث عن «$_query»',
            );
          }
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _resultCountBar(p.total > 0 ? p.total : p.items.length),
              Expanded(
                child: ListView.separated(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                  itemCount: p.items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (_, i) => ArticleCard(article: p.items[i]),
                ),
              ),
            ],
          );
        },
      );
    });
  }

  Widget _resultCountBar(int count) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final muted = isDark ? AppColors.textMutedDark : AppColors.textMutedLight;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: Text(
        '$count نتيجة لـ «$_query»',
        style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: muted),
      ),
    );
  }
}

final _searchProvider = FutureProvider.family((ref, String q) {
  return ref.watch(contentRepositoryProvider).search(q, limit: 30);
});

/// Trending topic tags reused as search suggestions. Cached for the
/// session by Riverpod, so opening search repeatedly is cheap.
final _trendingTagsProvider = FutureProvider((ref) async {
  final t = await ref.watch(contentRepositoryProvider).trending();
  return t.tags;
});
