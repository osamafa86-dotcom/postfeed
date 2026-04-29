import 'article.dart';
import 'category.dart';
import 'source.dart';

/// Compact category descriptor used in the stats strip "top trending" chip.
class StatsCategory {
  const StatsCategory({required this.name, required this.slug, this.icon, this.color});
  final String name;
  final String slug;
  final String? icon;
  final String? color;

  factory StatsCategory.fromJson(Map<String, dynamic> j) => StatsCategory(
        name: j['name'] as String,
        slug: (j['slug'] as String?) ?? '',
        icon: j['icon'] as String?,
        color: j['color'] as String?,
      );
}

/// Stats strip payload (5 chips above the section nav on the website).
class HomeStats {
  const HomeStats({
    this.totalArticles = 0,
    this.totalSources = 0,
    this.totalViewsToday,
    this.topCategory,
    this.lastUpdatedAt,
  });
  final int totalArticles;
  final int totalSources;
  final int? totalViewsToday;
  final StatsCategory? topCategory;
  final DateTime? lastUpdatedAt;

  factory HomeStats.fromJson(Map<String, dynamic> j) => HomeStats(
        totalArticles: (j['total_articles'] as num?)?.toInt() ?? 0,
        totalSources: (j['total_sources'] as num?)?.toInt() ?? 0,
        totalViewsToday: (j['total_views_today'] as num?)?.toInt(),
        topCategory: (j['top_category'] is Map)
            ? StatsCategory.fromJson((j['top_category'] as Map).cast())
            : null,
        lastUpdatedAt: _parseDt(j['last_updated_at']),
      );

  static DateTime? _parseDt(dynamic v) {
    if (v == null) return null;
    return DateTime.tryParse(v.toString().replaceFirst(' ', 'T'));
  }
}

/// Latest weekly rewind banner — shown under the stats strip on Sundays.
class WeeklyRewindCover {
  const WeeklyRewindCover({
    required this.yearWeek,
    required this.coverTitle,
    required this.coverSubtitle,
    this.publishedAt,
  });
  final String yearWeek;
  final String coverTitle;
  final String coverSubtitle;
  final DateTime? publishedAt;

  factory WeeklyRewindCover.fromJson(Map<String, dynamic> j) => WeeklyRewindCover(
        yearWeek: j['year_week'] as String,
        coverTitle: (j['cover_title'] as String?) ?? 'مراجعة الأسبوع جاهزة',
        coverSubtitle: (j['cover_subtitle'] as String?) ??
            'ملخص أسبوعي لأبرز ما جرى، بقلم هيئة تحرير الذكاء الاصطناعي.',
        publishedAt: _parseDt(j['published_at']),
      );

  static DateTime? _parseDt(dynamic v) {
    if (v == null) return null;
    return DateTime.tryParse(v.toString().replaceFirst(' ', 'T'));
  }
}

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
    this.stats,
    this.weeklyRewind,
  });

  final Article? hero;
  final List<Article> breaking;
  final List<Article> latest;
  final List<CategoryBucket> buckets;
  final List<TrendTag> trends;
  final List<TickerItem> ticker;
  final List<Source> sources;
  final HomeStats? stats;
  final WeeklyRewindCover? weeklyRewind;

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
        stats: (j['stats'] is Map) ? HomeStats.fromJson((j['stats'] as Map).cast()) : null,
        weeklyRewind: (j['weekly_rewind'] is Map)
            ? WeeklyRewindCover.fromJson((j['weekly_rewind'] as Map).cast())
            : null,
      );
}
