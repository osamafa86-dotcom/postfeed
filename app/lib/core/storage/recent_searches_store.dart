import 'package:shared_preferences/shared_preferences.dart';

/// Locally-stored recent search queries. Newest first, de-duplicated
/// (case-insensitive), capped so the suggestions list stays tidy.
/// Purely local — never synced to the server — so it works for
/// anonymous users and respects privacy.
class RecentSearchesStore {
  static const _key = 'recent_search_queries';
  static const _max = 10;

  /// Add a query to the top of the history. A query that already exists
  /// (ignoring case + surrounding whitespace) is moved to the top rather
  /// than duplicated.
  static Future<List<String>> add(String query) async {
    final q = query.trim();
    if (q.length < 2) return load();
    final p = await SharedPreferences.getInstance();
    final list = p.getStringList(_key) ?? <String>[];
    // Drop any case-insensitive match, then prepend.
    list.removeWhere((e) => e.toLowerCase() == q.toLowerCase());
    list.insert(0, q);
    final trimmed = list.take(_max).toList();
    await p.setStringList(_key, trimmed);
    return trimmed;
  }

  static Future<List<String>> load() async {
    final p = await SharedPreferences.getInstance();
    return p.getStringList(_key) ?? const [];
  }

  /// Remove a single query (the little × on a history chip).
  static Future<List<String>> remove(String query) async {
    final p = await SharedPreferences.getInstance();
    final list = p.getStringList(_key) ?? <String>[];
    list.removeWhere((e) => e.toLowerCase() == query.trim().toLowerCase());
    await p.setStringList(_key, list);
    return list;
  }

  static Future<void> clear() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_key);
  }
}
