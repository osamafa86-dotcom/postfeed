import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/models/home_payload.dart';
import '../../../../core/theme/app_theme.dart';

class CategoryChipsRail extends StatelessWidget {
  const CategoryChipsRail({super.key, required this.buckets});
  final List<CategoryBucket> buckets;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 92,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        scrollDirection: Axis.horizontal,
        itemCount: buckets.length,
        separatorBuilder: (_, __) => const SizedBox(width: 10),
        itemBuilder: (_, i) {
          final c = buckets[i].category;
          final color = AppColors.categoryColors[c.color] ?? AppColors.primary;
          return InkWell(
            onTap: () => context.push('/category/${c.slug}'),
            borderRadius: BorderRadius.circular(14),
            child: Container(
              width: 88,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: color.withOpacity(0.1),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: color.withOpacity(0.25)),
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(c.icon ?? '📰', style: const TextStyle(fontSize: 28)),
                  const SizedBox(height: 4),
                  Text(
                    c.name,
                    style: TextStyle(color: color, fontWeight: FontWeight.w700),
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
