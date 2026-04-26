import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/models/source.dart';
import '../../../../core/theme/app_theme.dart';

class SourceChipsRail extends StatelessWidget {
  const SourceChipsRail({super.key, required this.sources});
  final List<Source> sources;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 90,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        scrollDirection: Axis.horizontal,
        itemCount: sources.length,
        separatorBuilder: (_, __) => const SizedBox(width: 12),
        itemBuilder: (_, i) {
          final s = sources[i];
          Color color;
          try {
            color = Color(int.parse((s.logoColor ?? '#5A85B0').replaceAll('#', '0xFF')));
          } catch (_) {
            color = AppColors.primary;
          }
          return InkWell(
            onTap: () => context.push('/source/${s.slug}'),
            borderRadius: BorderRadius.circular(50),
            child: SizedBox(
              width: 70,
              child: Column(
                children: [
                  Container(
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(
                      color: color.withOpacity(0.15),
                      shape: BoxShape.circle,
                      border: Border.all(color: color.withOpacity(0.3)),
                    ),
                    alignment: Alignment.center,
                    child: Text(
                      s.logoLetter ?? '?',
                      style: TextStyle(color: color, fontSize: 22, fontWeight: FontWeight.w800),
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    s.name,
                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
                    overflow: TextOverflow.ellipsis,
                    maxLines: 1,
                    textAlign: TextAlign.center,
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
