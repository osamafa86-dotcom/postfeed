/// Helpers for generating share/deep links to articles, stories, podcast.
class ShareLinks {
  static const String webBase = 'https://feedsnews.net';
  static const String scheme = 'feedsnews';

  static String article(int id) => '$webBase/article.php?id=$id';
  static String story(String slug) => '$webBase/evolving-story.php?slug=$slug';
  static String podcastEpisode(String date) => '$webBase/podcast.php?d=$date';

  static String deepArticle(int id) => '$scheme://article/$id';
  static String deepStory(String slug) => '$scheme://stories/$slug';
}
