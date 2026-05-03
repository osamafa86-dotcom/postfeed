import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../../core/models/home_payload.dart';
import '../../../../core/theme/app_theme.dart';

class TickerBar extends StatefulWidget {
  const TickerBar({super.key, required this.items});
  final List<TickerItem> items;

  @override
  State<TickerBar> createState() => _TickerBarState();
}

class _TickerBarState extends State<TickerBar> {
  late final PageController _ctl = PageController();
  Timer? _timer;
  int _index = 0;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 4), (_) {
      if (!mounted || widget.items.isEmpty) return;
      _index = (_index + 1) % widget.items.length;
      _ctl.animateToPage(_index, duration: const Duration(milliseconds: 400), curve: Curves.easeInOut);
    });
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
      height: 36,
      margin: const EdgeInsets.fromLTRB(16, 8, 16, 0),
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: AppColors.primary.withOpacity(0.08),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          const Icon(Icons.campaign, size: 18, color: AppColors.primary),
          const SizedBox(width: 8),
          Expanded(
            child: PageView.builder(
              controller: _ctl,
              scrollDirection: Axis.vertical,
              itemCount: widget.items.length,
              itemBuilder: (_, i) {
                final t = widget.items[i];
                return Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: GestureDetector(
                    onTap: t.link != null ? () => launchUrl(Uri.parse(t.link!)) : null,
                    child: Text(
                      t.text,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        color: Theme.of(context).brightness == Brightness.dark
                            ? AppColors.textDark
                            : AppColors.textLight,
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
