class EvolvingStory {
  const EvolvingStory({
    required this.id,
    required this.name,
    required this.slug,
    this.description,
    this.icon = '',
    this.coverImage,
    this.accentColor = '#0d9488',
    this.articleCount = 0,
    this.lastMatchedAt,
    this.latest = const [],
  });

  final int id;
  final String name;
  final String slug;
  final String? description;
  final String icon;
  final String? coverImage;
  final String accentColor;
  final int articleCount;
  final DateTime? lastMatchedAt;
  final List<StoryPreviewArticle> latest;

  /// Whether this story has been updated within the last 2 hours.
  bool get isLive {
    if (lastMatchedAt == null) return false;
    return DateTime.now().difference(lastMatchedAt!).inHours < 2;
  }

  factory EvolvingStory.fromJson(Map<String, dynamic> j) => EvolvingStory(
        id: (j['id'] as num).toInt(),
        name: j['name'] as String,
        slug: j['slug'] as String,
        description: j['description'] as String?,
        icon: (j['icon'] as String?) ?? '',
        coverImage: j['cover_image'] as String?,
        accentColor: (j['accent_color'] as String?) ?? '#0d9488',
        articleCount: (j['article_count'] as num?)?.toInt() ?? 0,
        lastMatchedAt: j['last_matched_at'] != null
            ? DateTime.tryParse(j['last_matched_at'].toString().replaceFirst(' ', 'T'))
            : null,
        latest: (j['latest'] as List? ?? [])
            .whereType<Map>()
            .map((m) => StoryPreviewArticle.fromJson(m.cast()))
            .toList(),
      );
}

/// Lightweight article preview returned with evolving stories.
class StoryPreviewArticle {
  const StoryPreviewArticle({
    required this.id,
    required this.title,
    this.publishedAt,
    this.imageUrl,
  });

  final int id;
  final String title;
  final DateTime? publishedAt;
  final String? imageUrl;

  factory StoryPreviewArticle.fromJson(Map<String, dynamic> j) =>
      StoryPreviewArticle(
        id: (j['id'] as num).toInt(),
        title: j['title'] as String,
        publishedAt: j['published_at'] != null
            ? DateTime.tryParse(j['published_at'].toString().replaceFirst(' ', 'T'))
            : null,
        imageUrl: j['image_url'] as String?,
      );
}

class StoryQuote {
  const StoryQuote({
    required this.id,
    required this.quote,
    this.speaker,
    this.context,
    this.createdAt,
    this.articleId,
    this.articleTitle,
    this.articleSlug,
    this.articleImage,
  });

  final int id;
  final String quote;
  final String? speaker;
  final String? context;
  final DateTime? createdAt;
  final int? articleId;
  final String? articleTitle;
  final String? articleSlug;
  final String? articleImage;

  factory StoryQuote.fromJson(Map<String, dynamic> j) {
    final art = (j['article'] as Map?)?.cast<String, dynamic>();
    return StoryQuote(
      id: (j['id'] as num).toInt(),
      quote: j['quote'] as String,
      speaker: j['speaker'] as String?,
      context: j['context'] as String?,
      createdAt: j['created_at'] != null
          ? DateTime.tryParse(j['created_at'].toString().replaceFirst(' ', 'T'))
          : null,
      articleId: art?['id'] as int?,
      articleTitle: art?['title'] as String?,
      articleSlug: art?['slug'] as String?,
      articleImage: art?['image_url'] as String?,
    );
  }
}
