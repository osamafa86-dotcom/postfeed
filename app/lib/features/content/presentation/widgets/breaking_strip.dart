import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/models/article.dart';
import '../../../../core/theme/app_theme.dart';

class BreakingStrip extends StatefulWidget {
  const BreakingStrip({super.key, required this.items});
  final List<Article> items;

  @override
  State<BreakingStrip> createState() => _BreakingStripState();
}

class _BreakingStripState extends State<BreakingStrip> {
  late final PageController _ctl = PageController();
  Timer? _timer;
  int _index = 0;

  @override
  void initState() {
    super.initState();
    if (widget.items.length > 1) {
      _timer = Timer.periodic(const Duration(seconds: 5), (_) {
        if (!mounted) return;
        _index = (_index + 1) % widget.items.length;
        _ctl.animateToPage(_index,
          duration: const Duration(milliseconds: 400), curve: Curves.easeInOut);
      });
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _ctl.dispose();
    super.dispose();
  }

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
                controller: _ctl,
                scrollDirection: Axis.vertical,
                itemCount: widget.items.length,
                itemBuilder: (_, i) {
                  final a = widget.items[i];
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
