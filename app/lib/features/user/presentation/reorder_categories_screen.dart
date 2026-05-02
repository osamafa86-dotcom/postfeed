import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/models/category.dart';
import '../../../core/theme/app_theme.dart';
import '../../content/data/content_repository.dart';

/// Persists the user's preferred category order locally.
class CategoryOrderService {
  static const _key = 'user_category_order';

  static Future<List<int>?> loadOrder() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getStringList(_key);
    if (raw == null) return null;
    return raw.map(int.parse).toList();
  }

  static Future<void> saveOrder(List<int> ids) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_key, ids.map((e) => '$e').toList());
  }
}

/// Provides categories sorted by the user's preferred order.
final orderedCategoriesProvider = FutureProvider<List<Category>>((ref) async {
  final all = await ref.watch(categoriesProvider.future);
  final order = await CategoryOrderService.loadOrder();
  if (order == null || order.isEmpty) return all;

  final map = {for (final c in all) c.id: c};
  final ordered = <Category>[];
  for (final id in order) {
    final cat = map.remove(id);
    if (cat != null) ordered.add(cat);
  }
  // Append any new categories not in the saved order
  ordered.addAll(map.values);
  return ordered;
});

class ReorderCategoriesScreen extends ConsumerStatefulWidget {
  const ReorderCategoriesScreen({super.key});

  @override
  ConsumerState<ReorderCategoriesScreen> createState() => _ReorderCategoriesScreenState();
}

class _ReorderCategoriesScreenState extends ConsumerState<ReorderCategoriesScreen> {
  List<Category>? _items;

  @override
  void initState() {
    super.initState();
    _loadCategories();
  }

  Future<void> _loadCategories() async {
    final cats = await ref.read(orderedCategoriesProvider.future);
    setState(() => _items = List.of(cats));
  }

  Future<void> _save() async {
    if (_items == null) return;
    await CategoryOrderService.saveOrder(_items!.map((c) => c.id).toList());
    ref.invalidate(orderedCategoriesProvider);
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم حفظ الترتيب')),
      );
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: AppBar(
        title: const Text('ترتيب الأقسام'),
        actions: [
          TextButton(
            onPressed: _save,
            child: const Text('حفظ', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
      body: _items == null
          ? const Center(child: CircularProgressIndicator())
          : ReorderableListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: _items!.length,
              onReorder: (old, nw) {
                setState(() {
                  final item = _items!.removeAt(old);
                  _items!.insert(nw > old ? nw - 1 : nw, item);
                });
              },
              itemBuilder: (_, i) {
                final cat = _items![i];
                final color = AppColors.categoryColors[cat.color] ?? AppColors.primary;
                return Container(
                  key: ValueKey(cat.id),
                  margin: const EdgeInsets.only(bottom: 8),
                  decoration: BoxDecoration(
                    color: isDark ? const Color(0xFF1E293B) : Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: isDark ? Colors.white.withOpacity(0.06) : const Color(0xFFE2E8F0)),
                  ),
                  child: ListTile(
                    leading: Container(
                      width: 38, height: 38,
                      decoration: BoxDecoration(
                        color: color.withOpacity(0.12),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      alignment: Alignment.center,
                      child: Text(cat.icon ?? '', style: const TextStyle(fontSize: 18)),
                    ),
                    title: Text(cat.name,
                        style: TextStyle(fontWeight: FontWeight.w700,
                            color: isDark ? Colors.white : AppColors.textLight)),
                    trailing: const Icon(Icons.drag_handle, color: Colors.grey),
                  ),
                );
              },
            ),
    );
  }
}
