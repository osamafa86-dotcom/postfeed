import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/models/article.dart';
import '../../../../core/theme/app_theme.dart';

class BreakingStrip extends StatelessWidget {
  const BreakingStrip({super.key, required this.items});
  final List<Article> items;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.breaking.withOpacity(0.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.breaking.withOpacity(0.3)),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            decoration: BoxDecoration(
              color: AppColors.breaking,
              borderRadius: BorderRadius.circular(6),
            ),
            child: const Text(
              'عاجل',
              style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: SizedBox(
              height: 28,
              child: PageView.builder(
                controller: PageController(viewportFraction: 1),
                scrollDirection: Axis.vertical,
                itemCount: items.length,
                itemBuilder: (_, i) {
                  final a = items[i];
                  return InkWell(
                    onTap: () => context.push('/article/${a.id}'),
                    child: Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: Text(
                        a.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ),
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}
