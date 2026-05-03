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
                      color: Theme.of(context).brightness == Brightness.dark
                          ? AppColors.neoDarkMid : AppColors.neoSurface,
                      shape: BoxShape.circle,
                      boxShadow: Theme.of(context).brightness == Brightness.dark
                          ? [
                              BoxShadow(color: AppColors.neoDarkShadow.withOpacity(0.4),
                                offset: const Offset(2, 2), blurRadius: 6),
                              BoxShadow(color: AppColors.neoDarkHighlight.withOpacity(0.15),
                                offset: const Offset(-2, -2), blurRadius: 6),
                            ]
                          : [
                              BoxShadow(color: AppColors.neoShadowDark.withOpacity(0.3),
                                offset: const Offset(2, 2), blurRadius: 6),
                              BoxShadow(color: AppColors.neoShadowLight.withOpacity(0.6),
                                offset: const Offset(-2, -2), blurRadius: 6),
                            ],
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
