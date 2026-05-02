import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/models/article.dart';
import '../../auth/data/auth_storage.dart';

// ═══════════════════════════════════════════════════════════════
// MODELS
// ═══════════════════════════════════════════════════════════════

class Comment {
  const Comment({
    required this.id,
    required this.body,
    this.userId,
    this.userName,
    this.parentId,
    this.createdAt,
  });

  final int id;
  final String body;
  final int? userId;
  final String? userName;
  final int? parentId;
  final DateTime? createdAt;

  factory Comment.fromJson(Map<String, dynamic> j) => Comment(
        id: (j['id'] as num).toInt(),
        body: j['body'] as String,
        userId: (j['user_id'] as num?)?.toInt(),
        userName: j['user_name'] as String?,
        parentId: (j['parent_id'] as num?)?.toInt(),
        createdAt: j['created_at'] != null
            ? DateTime.tryParse(j['created_at'].toString().replaceFirst(' ', 'T'))
            : null,
      );
}

class ReactionCounts {
  const ReactionCounts({
    this.like = 0,
    this.love = 0,
    this.sad = 0,
    this.angry = 0,
    this.wow = 0,
    this.fire = 0,
    this.userReaction,
  });

  final int like;
  final int love;
  final int sad;
  final int angry;
  final int wow;
  final int fire;
  final String? userReaction;

  int get total => like + love + sad + angry + wow + fire;

  factory ReactionCounts.fromJson(Map<String, dynamic> j) => ReactionCounts(
        like: (j['like'] as num?)?.toInt() ?? 0,
        love: (j['love'] as num?)?.toInt() ?? 0,
        sad: (j['sad'] as num?)?.toInt() ?? 0,
        angry: (j['angry'] as num?)?.toInt() ?? 0,
        wow: (j['wow'] as num?)?.toInt() ?? 0,
        fire: (j['fire'] as num?)?.toInt() ?? 0,
        userReaction: j['user_reaction'] as String?,
      );
}

class FollowGroup {
  const FollowGroup({required this.type, required this.ids});
  final String type;
  final List<int> ids;
}

class ArticleCluster {
  const ArticleCluster({required this.title, required this.articles});
  final String title;
  final List<Article> articles;
}

// ═══════════════════════════════════════════════════════════════
// REPOSITORY
// ═══════════════════════════════════════════════════════════════

class UserRepository {
  UserRepository(this._api);
  final ApiClient _api;

  // ── Bookmarks ──

  Future<List<Article>> bookmarks({int page = 1}) async {
    final res = await _api.get<List<Article>>(
      '/user/bookmarks',
      query: {'page': '$page'},
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => Article.fromJson(m.cast())).toList(),
    );
    return res.data ?? const [];
  }

  /// Toggle bookmark — returns true if bookmarked, false if removed.
  Future<bool> toggleBookmark(int articleId) async {
    final res = await _api.post<Map<String, dynamic>>(
      '/user/bookmarks',
      body: {'article_id': articleId},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    final data = res.data ?? {};
    return data['bookmarked'] == true;
  }

  Future<void> removeBookmark(int bookmarkId) async {
    await _api.delete('/user/bookmarks', query: {'id': '$bookmarkId'});
  }

  // ── Follows ──

  Future<Map<String, List<int>>> follows() async {
    final res = await _api.get<Map<String, dynamic>>(
      '/user/follows',
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    final data = res.data ?? {};
    final result = <String, List<int>>{};
    for (final type in ['category', 'source', 'story']) {
      final list = data[type];
      if (list is List) {
        result[type] = list.map((e) => (e as num).toInt()).toList();
      }
    }
    return result;
  }

  /// Toggle follow — returns true if now following, false if unfollowed.
  Future<bool> toggleFollow(String type, int targetId) async {
    final res = await _api.post<Map<String, dynamic>>(
      '/user/follows',
      body: {'type': type, 'target': targetId},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    final data = res.data ?? {};
    return data['following'] == true;
  }

  // ── Comments ──

  Future<List<Comment>> comments(int articleId, {int page = 1}) async {
    final res = await _api.get<List<Comment>>(
      '/user/comments',
      query: {'article_id': '$articleId', 'page': '$page'},
      decode: (d) =>
          (d as List).whereType<Map>().map((m) => Comment.fromJson(m.cast())).toList(),
    );
    return res.data ?? const [];
  }

  Future<Comment> addComment(int articleId, String body, {int? parentId}) async {
    final res = await _api.post<Comment>(
      '/user/comments',
      body: {
        'article_id': articleId,
        'body': body,
        if (parentId != null) 'parent_id': parentId,
      },
      decode: (d) => Comment.fromJson((d as Map).cast()),
    );
    return res.data!;
  }

  // ── Reactions ──

  Future<ReactionCounts> react(int articleId, String reaction) async {
    final res = await _api.post<ReactionCounts>(
      '/user/reactions',
      body: {'article_id': articleId, 'reaction': reaction},
      decode: (d) => ReactionCounts.fromJson((d as Map).cast()),
    );
    return res.data!;
  }

  // ── Share Tracking ──

  /// Notify the backend that an article was shared. Fire-and-forget.
  Future<void> trackShare(int articleId) async {
    try {
      await _api.post('/user/reactions', body: {
        'article_id': articleId,
        'reaction': 'share',
      });
    } catch (_) {
      // Non-critical — silently ignore
    }
  }

  // ── Clusters (Compare Coverage) ──

  Future<List<ArticleCluster>> clusters({int page = 1}) async {
    final res = await _api.get<List<ArticleCluster>>(
      '/content/clusters',
      query: {'page': '$page'},
      decode: (d) => (d as List).whereType<Map>().map((m) {
        final map = m.cast<String, dynamic>();
        return ArticleCluster(
          title: (map['cluster_title'] as String?) ?? '',
          articles: (map['articles'] as List? ?? [])
              .whereType<Map>()
              .map((a) => Article.fromJson(a.cast()))
              .toList(),
        );
      }).toList(),
    );
    return res.data ?? const [];
  }
}

// ═══════════════════════════════════════════════════════════════
// PROVIDERS
// ═══════════════════════════════════════════════════════════════

final userRepositoryProvider =
    Provider<UserRepository>((ref) => UserRepository(ref.watch(apiClientProvider)));

/// Set of bookmarked article IDs — kept in memory for fast toggle checks.
final bookmarkedIdsProvider = StateNotifierProvider<BookmarkIdsNotifier, Set<int>>((ref) {
  return BookmarkIdsNotifier(ref.watch(userRepositoryProvider));
});

class BookmarkIdsNotifier extends StateNotifier<Set<int>> {
  BookmarkIdsNotifier(this._repo) : super(const {}) {
    _load();
  }
  final UserRepository _repo;

  Future<void> _load() async {
    if (!AuthStorage.isAuthenticated) return;
    try {
      final articles = await _repo.bookmarks();
      state = articles.map((a) => a.id).toSet();
    } catch (_) {}
  }

  Future<bool> toggle(int articleId) async {
    final result = await _repo.toggleBookmark(articleId);
    if (result) {
      state = {...state, articleId};
    } else {
      state = {...state}..remove(articleId);
    }
    return result;
  }

  void refresh() => _load();
}

/// Set of followed IDs grouped by type.
final followedIdsProvider = StateNotifierProvider<FollowIdsNotifier, Map<String, Set<int>>>((ref) {
  return FollowIdsNotifier(ref.watch(userRepositoryProvider));
});

class FollowIdsNotifier extends StateNotifier<Map<String, Set<int>>> {
  FollowIdsNotifier(this._repo) : super(const {}) {
    _load();
  }
  final UserRepository _repo;

  Future<void> _load() async {
    if (!AuthStorage.isAuthenticated) return;
    try {
      final data = await _repo.follows();
      state = data.map((k, v) => MapEntry(k, v.toSet()));
    } catch (_) {}
  }

  bool isFollowing(String type, int id) => state[type]?.contains(id) ?? false;

  Future<bool> toggle(String type, int targetId) async {
    final result = await _repo.toggleFollow(type, targetId);
    final current = {...state};
    final set = {...(current[type] ?? {})};
    if (result) {
      set.add(targetId);
    } else {
      set.remove(targetId);
    }
    current[type] = set;
    state = current;
    return result;
  }

  void refresh() => _load();
}
