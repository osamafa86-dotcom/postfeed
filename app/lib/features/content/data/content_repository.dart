import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/models/article.dart';
import '../../../core/models/category.dart';
import '../../../core/models/cluster_coverage.dart';
import '../../../core/models/evolving_story.dart';
import '../../../core/models/home_payload.dart';
import '../../../core/models/source.dart';

class PaginatedArticles {
  const PaginatedArticles({required this.items, required this.hasMore, required this.total});
  final List<Article> items;
  final bool hasMore;
  final int total;
}

class ContentRepository {
  ContentRepository(this._api);
  final ApiClient _api;

  Future<HomePayload> home() async {
    final res = await _api.get<HomePayload>('/content/home',
        decode: (d) => HomePayload.fromJson((d as Map).cast()));
    return res.data!;
  }

  Future<PaginatedArticles> articles({
    int page = 1,
    int limit = 20,
    String? category,
    String? source,
    bool? breaking,
    String? q,
    String? contentType,           // news | report | article
    List<String>? categorySlugs,   // aggregate tabs (e.g. منوعات = sports+arts+tech+media)
    String? clusterKey,            // coverage-comparison: same story across sources
    bool palestine = false,        // restrict to Palestine-keyword titles
    bool notPalestine = false,     // exclude Palestine-keyword titles
  }) async {
    final res = await _api.get<List<Article>>(
      '/content/articles',
      query: {
        'page': page,
        'limit': limit,
        if (category != null) 'category': category,
        if (source != null) 'source': source,
        if (breaking == true) 'breaking': 1,
        if (q != null && q.isNotEmpty) 'q': q,
        if (contentType != null) 'content_type': contentType,
        if (categorySlugs != null && categorySlugs.isNotEmpty)
          'category_slugs': categorySlugs.join(','),
        if (clusterKey != null) 'cluster_key': clusterKey,
        if (palestine) 'palestine': 1,
        if (notPalestine) 'not_palestine': 1,
      },
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => Article.fromJson(m.cast())).toList(),
    );
    return PaginatedArticles(items: res.data ?? const [], hasMore: res.hasMore, total: res.total);
  }

  /// Full "قارن التغطية" payload — mirrors the website's /cluster/<key> page.
  Future<ClusterCoverage?> cluster(String key) async {
    final res = await _api.get<ClusterCoverage?>(
      '/content/cluster',
      query: {'key': key},
      decode: (d) {
        if (d is! Map) return null;
        return ClusterCoverage.fromJson(d.cast<String, dynamic>());
      },
    );
    return res.data;
  }

  Future<({Article article, List<Article> related})> article({int? id, String? slug}) async {
    final res = await _api.get<Map<String, dynamic>>(
      '/content/article',
      query: {if (id != null) 'id': id, if (slug != null) 'slug': slug},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    final article = Article.fromJson((res.data!['article'] as Map).cast());
    final related = (res.data!['related'] as List? ?? [])
        .whereType<Map>()
        .map((m) => Article.fromJson(m.cast()))
        .toList();
    return (article: article, related: related);
  }

  Future<List<Category>> categories() async {
    final res = await _api.get<List<Category>>(
      '/content/categories',
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => Category.fromJson(m.cast())).toList(),
    );
    return res.data ?? const [];
  }

  Future<List<Source>> sources() async {
    final res = await _api.get<List<Source>>(
      '/content/sources',
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => Source.fromJson(m.cast())).toList(),
    );
    return res.data ?? const [];
  }

  Future<PaginatedArticles> search(String q, {int page = 1, int limit = 20}) async {
    final res = await _api.get<List<Article>>(
      '/content/search',
      query: {'q': q, 'page': page, 'limit': limit},
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => Article.fromJson(m.cast())).toList(),
    );
    return PaginatedArticles(items: res.data ?? const [], hasMore: res.hasMore, total: res.total);
  }

  Future<({List<TrendTag> tags, List<Article> articles})> trending() async {
    final res = await _api.get<Map<String, dynamic>>(
      '/content/trending',
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    final tags = (res.data!['tags'] as List? ?? [])
        .whereType<Map>()
        .map((m) => TrendTag.fromJson(m.cast()))
        .toList();
    final arts = (res.data!['articles'] as List? ?? [])
        .whereType<Map>()
        .map((m) => Article.fromJson(m.cast()))
        .toList();
    return (tags: tags, articles: arts);
  }

  Future<List<EvolvingStory>> evolvingStories() async {
    final res = await _api.get<List<EvolvingStory>>(
      '/content/evolving-stories',
      decode: (d) => (d as List).whereType<Map>().map((m) => EvolvingStory.fromJson(m.cast())).toList(),
    );
    return res.data ?? const [];
  }

  Future<({EvolvingStory story, List<Article> articles, bool hasMore})> evolvingStory(
    String slug, {
    int page = 1,
    int limit = 20,
  }) async {
    final res = await _api.get<Map<String, dynamic>>(
      '/content/evolving-story',
      query: {'slug': slug, 'page': page, 'limit': limit},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return (
      story: EvolvingStory.fromJson((res.data!['story'] as Map).cast()),
      articles: (res.data!['articles'] as List? ?? [])
          .whereType<Map>()
          .map((m) => Article.fromJson(m.cast()))
          .toList(),
      hasMore: res.hasMore,
    );
  }

  /// Request TTS audio for an article. Returns the audio URL.
  Future<({String audioUrl, int duration})> tts(int articleId) async {
    final res = await _api.post<Map<String, dynamic>>(
      '/media/tts',
      body: {'article_id': articleId},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return (
      audioUrl: res.data!['audio_url'] as String,
      duration: (res.data!['duration'] as num?)?.toInt() ?? 0,
    );
  }

  /// Personalized "For You" feed based on user's follows and reads.
  Future<List<Article>> forYou() async {
    final res = await _api.get<List<Article>>(
      '/content/for-you',
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => Article.fromJson(m.cast())).toList(),
    );
    return res.data ?? const [];
  }

  Future<List<StoryQuote>> storyQuotes(String slug) async {
    final res = await _api.get<Map<String, dynamic>>(
      '/content/evolving-story-quotes',
      query: {'slug': slug},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return (res.data!['quotes'] as List? ?? [])
        .whereType<Map>()
        .map((m) => StoryQuote.fromJson(m.cast()))
        .toList();
  }
}

final contentRepositoryProvider =
    Provider<ContentRepository>((ref) => ContentRepository(ref.watch(apiClientProvider)));

final homeProvider = FutureProvider<HomePayload>((ref) {
  return ref.watch(contentRepositoryProvider).home();
});

final categoriesProvider = FutureProvider<List<Category>>((ref) {
  return ref.watch(contentRepositoryProvider).categories();
});

final sourcesProvider = FutureProvider<List<Source>>((ref) {
  return ref.watch(contentRepositoryProvider).sources();
});

final evolvingStoriesProvider = FutureProvider.autoDispose<List<EvolvingStory>>((ref) {
  return ref.watch(contentRepositoryProvider).evolvingStories();
});

final forYouProvider = FutureProvider<List<Article>>((ref) {
  return ref.watch(contentRepositoryProvider).forYou();
});

final articleProvider =
    FutureProvider.family<({Article article, List<Article> related}), int>((ref, id) {
  return ref.watch(contentRepositoryProvider).article(id: id);
});

/// State for the "Load more" button on the home screen's latest-news
/// section. Holds the extra pages fetched on top of whatever
/// `homeProvider` already returned (page 1 / 20 articles). Resets on
/// pull-to-refresh because `homeProvider` invalidation re-fires
/// `seedFromHome` on the next build.
class LatestExtraState {
  const LatestExtraState({
    this.items = const [],
    this.nextPage = 2,
    this.hasMore = true,
    this.loading = false,
    this.error,
  });
  final List<Article> items;
  final int nextPage;
  final bool hasMore;
  final bool loading;
  final String? error;

  LatestExtraState copyWith({
    List<Article>? items,
    int? nextPage,
    bool? hasMore,
    bool? loading,
    Object? error = _sentinel,
  }) {
    return LatestExtraState(
      items: items ?? this.items,
      nextPage: nextPage ?? this.nextPage,
      hasMore: hasMore ?? this.hasMore,
      loading: loading ?? this.loading,
      error: identical(error, _sentinel) ? this.error : error as String?,
    );
  }

  static const _sentinel = Object();
}

class LatestExtraNotifier extends StateNotifier<LatestExtraState> {
  LatestExtraNotifier(this._ref) : super(const LatestExtraState());
  final Ref _ref;

  /// Pull the next page from /content/articles, append to state,
  /// dedupe against article IDs already shown.
  Future<void> loadMore({Set<int> excludeIds = const {}}) async {
    if (state.loading || !state.hasMore) return;
    state = state.copyWith(loading: true, error: null);
    try {
      // Match the home payload's filter — "آخر الأخبار" is news-only,
      // not a mixed feed of news + reports + opinion.
      final page = await _ref
          .read(contentRepositoryProvider)
          .articles(page: state.nextPage, limit: 20, contentType: 'news');
      final seen = {...excludeIds, ...state.items.map((a) => a.id)};
      final fresh = page.items.where((a) => !seen.contains(a.id)).toList();
      state = state.copyWith(
        items: [...state.items, ...fresh],
        nextPage: state.nextPage + 1,
        hasMore: page.hasMore,
        loading: false,
      );
    } catch (e) {
      state = state.copyWith(loading: false, error: e.toString());
    }
  }

  /// Reset to a fresh state — used implicitly when homeProvider is
  /// invalidated (pull-to-refresh) so the next "load more" starts
  /// from page 2 of the fresh feed.
  void reset() {
    state = const LatestExtraState();
  }
}

// Explicit type annotation — without it Dart's inference loops on the
// self-reference inside the listener (`latestExtraProvider.notifier`).
final StateNotifierProvider<LatestExtraNotifier, LatestExtraState>
    latestExtraProvider =
    StateNotifierProvider<LatestExtraNotifier, LatestExtraState>((ref) {
  // When home is invalidated (pull-to-refresh), reset the extra pages
  // so we don't keep appending to a stale feed.
  ref.listen(homeProvider, (_, __) {
    ref.read(latestExtraProvider.notifier).reset();
  });
  return LatestExtraNotifier(ref);
});
