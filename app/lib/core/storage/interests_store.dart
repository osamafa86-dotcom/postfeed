import 'package:shared_preferences/shared_preferences.dart';

/// Locally-stored category interests chosen during onboarding (before the
/// user has an account). Read by the feed for anonymous personalization
/// and replayed as server-side follows once the user logs in.
class InterestsStore {
  static const _key = 'onboarding_interest_category_ids';

  static Future<void> save(Set<int> categoryIds) async {
    final p = await SharedPreferences.getInstance();
    await p.setStringList(_key, categoryIds.map((e) => e.toString()).toList());
  }

  static Future<Set<int>> load() async {
    final p = await SharedPreferences.getInstance();
    final raw = p.getStringList(_key) ?? const [];
    return raw.map(int.tryParse).whereType<int>().toSet();
  }

  static Future<void> clear() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_key);
  }
}
