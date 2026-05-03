import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

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
  late String _query;

  @override
  void initState() {
    super.initState();
    _query = widget.initialQuery;
    _ctrl = TextEditingController(text: _query);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: TextField(
          controller: _ctrl,
          autofocus: widget.initialQuery.isEmpty,
          textInputAction: TextInputAction.search,
          decoration: const InputDecoration(
            hintText: 'ابحث في الأخبار...',
            border: InputBorder.none,
          ),
          onSubmitted: (v) => setState(() => _query = v.trim()),
        ),
      ),
      body: _query.isEmpty
          ? const EmptyView(message: 'اكتب كلمة للبحث', icon: Icons.search)
          : Consumer(builder: (_, ref, __) {
              final asy = ref.watch(_searchProvider(_query));
              return asy.when(
                loading: () => const LoadingShimmerList(),
                error: (e, _) => ErrorRetryView(message: '$e'),
                data: (p) => p.items.isEmpty
                    ? const EmptyView(message: 'لا نتائج لهذا البحث')
                    : ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: p.items.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (_, i) => ArticleCard(article: p.items[i]),
                      ),
              );
            }),
    );
  }
}

final _searchProvider = FutureProvider.family((ref, String q) {
  return ref.watch(contentRepositoryProvider).search(q, limit: 30);
});
