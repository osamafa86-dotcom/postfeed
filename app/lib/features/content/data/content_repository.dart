import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/models/article.dart';
import '../../../core/models/category.dart';
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
      },
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => Article.fromJson(m.cast())).toList(),
    );
    return PaginatedArticles(items: res.data ?? const [], hasMore: res.hasMore, total: res.total);
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

final evolvingStoriesProvider = FutureProvider<List<EvolvingStory>>((ref) {
  return ref.watch(contentRepositoryProvider).evolvingStories();
});

final articleProvider =
    FutureProvider.family<({Article article, List<Article> related}), int>((ref, id) {
  return ref.watch(contentRepositoryProvider).article(id: id);
});
