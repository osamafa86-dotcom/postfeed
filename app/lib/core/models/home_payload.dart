import 'article.dart';
import 'category.dart';
import 'source.dart';

class TrendTag {
  const TrendTag({required this.id, required this.title, this.tweetCount = 0, this.searchCount = 0});
  final int id;
  final String title;
  final int tweetCount;
  final int searchCount;

  factory TrendTag.fromJson(Map<String, dynamic> j) => TrendTag(
        id: (j['id'] as num).toInt(),
        title: j['title'] as String,
        tweetCount: (j['tweet_count'] as num?)?.toInt() ?? 0,
        searchCount: (j['search_count'] as num?)?.toInt() ?? 0,
      );
}

class TickerItem {
  const TickerItem({required this.id, required this.text, this.link});
  final int id;
  final String text;
  final String? link;

  factory TickerItem.fromJson(Map<String, dynamic> j) => TickerItem(
        id: (j['id'] as num).toInt(),
        text: j['text'] as String,
        link: j['link'] as String?,
      );
}

class CategoryBucket {
  const CategoryBucket({required this.category, required this.articles});
  final Category category;
  final List<Article> articles;

  factory CategoryBucket.fromJson(Map<String, dynamic> j) => CategoryBucket(
        category: Category.fromJson((j['category'] as Map).cast()),
        articles: (j['articles'] as List? ?? [])
            .whereType<Map>()
            .map((a) => Article.fromJson(a.cast()))
            .toList(),
      );
}

class HomePayload {
  const HomePayload({
    this.hero,
    this.breaking = const [],
    this.latest = const [],
    this.buckets = const [],
    this.trends = const [],
    this.ticker = const [],
    this.sources = const [],
  });

  final Article? hero;
  final List<Article> breaking;
  final List<Article> latest;
  final List<CategoryBucket> buckets;
  final List<TrendTag> trends;
  final List<TickerItem> ticker;
  final List<Source> sources;

  factory HomePayload.fromJson(Map<String, dynamic> j) => HomePayload(
        hero: (j['hero'] is Map) ? Article.fromJson((j['hero'] as Map).cast()) : null,
        breaking: (j['breaking'] as List? ?? [])
            .whereType<Map>()
            .map((a) => Article.fromJson(a.cast()))
            .toList(),
        latest: (j['latest'] as List? ?? [])
            .whereType<Map>()
            .map((a) => Article.fromJson(a.cast()))
            .toList(),
        buckets: (j['buckets'] as List? ?? [])
            .whereType<Map>()
            .map((b) => CategoryBucket.fromJson(b.cast()))
            .toList(),
        trends: (j['trends'] as List? ?? [])
            .whereType<Map>()
            .map((t) => TrendTag.fromJson(t.cast()))
            .toList(),
        ticker: (j['ticker'] as List? ?? [])
            .whereType<Map>()
            .map((t) => TickerItem.fromJson(t.cast()))
            .toList(),
        sources: (j['sources'] as List? ?? [])
            .whereType<Map>()
            .map((s) => Source.fromJson(s.cast()))
            .toList(),
      );
}
