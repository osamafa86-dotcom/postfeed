import 'category.dart';
import 'source.dart';

class Article {
  const Article({
    required this.id,
    required this.title,
    this.slug,
    this.excerpt,
    this.content,
    this.imageUrl,
    this.sourceUrl,
    this.category,
    this.source,
    this.isBreaking = false,
    this.isFeatured = false,
    this.isHero = false,
    this.viewCount = 0,
    this.comments = 0,
    this.publishedAt,
    this.createdAt,
  });

  final int id;
  final String title;
  final String? slug;
  final String? excerpt;
  final String? content;
  final String? imageUrl;
  final String? sourceUrl;
  final Category? category;
  final Source? source;
  final bool isBreaking;
  final bool isFeatured;
  final bool isHero;
  final int viewCount;
  final int comments;
  final DateTime? publishedAt;
  final DateTime? createdAt;

  factory Article.fromJson(Map<String, dynamic> j) => Article(
        id: j['id'] as int,
        title: j['title'] as String,
        slug: j['slug'] as String?,
        excerpt: j['excerpt'] as String?,
        content: j['content'] as String?,
        imageUrl: j['image_url'] as String?,
        sourceUrl: j['source_url'] as String?,
        category: (j['category'] is Map) ? Category.fromJson((j['category'] as Map).cast()) : null,
        source: (j['source'] is Map) ? Source.fromJson((j['source'] as Map).cast()) : null,
        isBreaking: j['is_breaking'] == true,
        isFeatured: j['is_featured'] == true,
        isHero: j['is_hero'] == true,
        viewCount: (j['view_count'] as num?)?.toInt() ?? 0,
        comments: (j['comments'] as num?)?.toInt() ?? 0,
        publishedAt: _parseDt(j['published_at']),
        createdAt: _parseDt(j['created_at']),
      );

  static DateTime? _parseDt(dynamic v) {
    if (v == null) return null;
    return DateTime.tryParse(v.toString().replaceFirst(' ', 'T'));
  }
}
