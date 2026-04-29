import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../../core/models/home_payload.dart';
import '../../../../core/theme/app_theme.dart';

/// Mirrors the website's `<div class="ticker-wrap">`:
/// - Solid red label "عاجل" with a pulsing dot.
/// - Horizontally-scrolling marquee of breaking headlines next to it.
///
/// On mobile we keep it as a horizontal scroll list (no auto-marquee yet) so
/// users can swipe through items deliberately. The dot uses a continuous
/// scale-pulse to match the website's `liveDotPulse` keyframes.
class RedBreakingTicker extends StatefulWidget {
  const RedBreakingTicker({super.key, required this.items});
  final List<TickerItem> items;

  @override
  State<RedBreakingTicker> createState() => _RedBreakingTickerState();
}

class _RedBreakingTickerState extends State<RedBreakingTicker>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1100),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.items.isEmpty) return const SizedBox.shrink();

    return Container(
      height: 40,
      margin: const EdgeInsets.fromLTRB(0, 0, 0, 0),
      decoration: BoxDecoration(
        color: AppColors.breaking,
        boxShadow: [
          BoxShadow(
            color: AppColors.breaking.withOpacity(0.25),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            color: Colors.black.withOpacity(0.18),
            alignment: Alignment.center,
            child: Row(
              children: [
                FadeTransition(
                  opacity: Tween<double>(begin: 0.4, end: 1).animate(_pulse),
                  child: Container(
                    width: 8,
                    height: 8,
                    decoration: const BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                const Text(
                  'عاجل',
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                    fontSize: 13,
                    letterSpacing: 0.3,
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              itemCount: widget.items.length,
              separatorBuilder: (_, __) => Container(
                width: 1,
                margin: const EdgeInsets.symmetric(vertical: 10, horizontal: 12),
                color: Colors.white24,
              ),
              itemBuilder: (_, i) {
                final item = widget.items[i];
                return InkWell(
                  onTap: () async {
                    final link = item.link;
                    if (link == null || link.isEmpty) return;
                    final uri = Uri.tryParse(link);
                    if (uri == null) return;
                    await launchUrl(uri, mode: LaunchMode.externalApplication);
                  },
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 320),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      child: Text(
                        item.text,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w700,
                          fontSize: 13,
                        ),
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
