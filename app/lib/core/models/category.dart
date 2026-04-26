class Category {
  const Category({
    required this.id,
    required this.name,
    required this.slug,
    this.icon,
    this.color,
    this.sortOrder = 0,
  });

  final int id;
  final String name;
  final String slug;
  final String? icon;
  final String? color;
  final int sortOrder;

  factory Category.fromJson(Map<String, dynamic> j) => Category(
        id: (j['id'] as num).toInt(),
        name: j['name'] as String,
        slug: j['slug'] as String,
        icon: j['icon'] as String?,
        color: j['color'] as String?,
        sortOrder: (j['sort_order'] as num?)?.toInt() ?? 0,
      );
}
