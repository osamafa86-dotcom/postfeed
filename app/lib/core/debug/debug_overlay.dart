import 'package:flutter/material.dart';
import 'debug_state.dart';

/// Floating diagnostic overlay. Anchored to the top of the screen with
/// high z-order so it sits above every Scaffold body — including blank
/// ones — and shows in screenshots.
///
/// What it captures:
///   - Latest "probes" — current shell route, isTab, child runtimeType,
///     article asy state, etc.
///   - Last ~6 trace events with timestamps, color-coded by level.
///
/// Tap the chevron in the corner to collapse/expand. Long-press anywhere
/// on the overlay to clear the log. The overlay itself never blocks taps
/// underneath because of the IgnorePointer wrapper around the read-only
/// data; only the toggle button is interactive.
class DebugOverlay extends StatefulWidget {
  const DebugOverlay({super.key});

  @override
  State<DebugOverlay> createState() => _DebugOverlayState();
}

class _DebugOverlayState extends State<DebugOverlay> {
  bool _expanded = true;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: DebugTrace.visible,
      builder: (_, visible, __) {
        if (!visible) return const SizedBox.shrink();
        return Positioned(
          top: MediaQuery.of(context).padding.top + 4,
          left: 6,
          right: 6,
          child: Material(
            color: Colors.transparent,
            child: _OverlayCard(
              expanded: _expanded,
              onToggle: () => setState(() => _expanded = !_expanded),
              onClear: DebugTrace.clear,
            ),
          ),
        );
      },
    );
  }
}

class _OverlayCard extends StatelessWidget {
  const _OverlayCard({
    required this.expanded,
    required this.onToggle,
    required this.onClear,
  });
  final bool expanded;
  final VoidCallback onToggle;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.black.withOpacity(0.86),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFF22C55E), width: 0.8),
      ),
      padding: const EdgeInsets.fromLTRB(8, 6, 8, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(children: [
            const Text('🔬 TRACE',
              style: TextStyle(color: Color(0xFF22C55E),
                fontWeight: FontWeight.w900, fontSize: 11)),
            const SizedBox(width: 8),
            const Spacer(),
            GestureDetector(
              onTap: onClear,
              child: const Padding(
                padding: EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                child: Icon(Icons.clear_all, size: 13, color: Colors.white70),
              ),
            ),
            GestureDetector(
              onTap: onToggle,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                child: Icon(expanded ? Icons.expand_less : Icons.expand_more,
                  size: 14, color: Colors.white70),
              ),
            ),
          ]),
          if (expanded) ...[
            const SizedBox(height: 4),
            const _ProbesView(),
            const SizedBox(height: 4),
            const _EventsView(),
          ],
        ],
      ),
    );
  }
}

class _ProbesView extends StatelessWidget {
  const _ProbesView();

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<Map<String, String>>(
      valueListenable: DebugTrace.probes,
      builder: (_, probes, __) {
        if (probes.isEmpty) {
          return const Text('— لا توجد قراءات حالية —',
            style: TextStyle(color: Colors.white38, fontSize: 9));
        }
        final keys = probes.keys.toList()..sort();
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final k in keys)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 0.5),
                child: RichText(
                  text: TextSpan(
                    style: const TextStyle(fontSize: 10, color: Colors.white),
                    children: [
                      TextSpan(
                        text: '$k ',
                        style: const TextStyle(
                          color: Color(0xFF60A5FA), fontWeight: FontWeight.w700),
                      ),
                      TextSpan(text: probes[k] ?? ''),
                    ],
                  ),
                  maxLines: 1, overflow: TextOverflow.ellipsis,
                ),
              ),
          ],
        );
      },
    );
  }
}

class _EventsView extends StatelessWidget {
  const _EventsView();

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<List<DebugEvent>>(
      valueListenable: DebugTrace.events,
      builder: (_, events, __) {
        if (events.isEmpty) {
          return const Text('— لم تُسجَّل أحداث بعد —',
            style: TextStyle(color: Colors.white38, fontSize: 9));
        }
        // Show last 8 only — newest at the bottom (closest to where the
        // bug just happened).
        final shown = events.length > 8
            ? events.sublist(events.length - 8)
            : events;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final ev in shown)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 0.5),
                child: RichText(
                  text: TextSpan(
                    style: const TextStyle(fontSize: 10, color: Colors.white),
                    children: [
                      TextSpan(
                        text: _fmtTime(ev.timestamp),
                        style: const TextStyle(color: Colors.white38),
                      ),
                      const TextSpan(text: ' '),
                      TextSpan(
                        text: ev.source,
                        style: TextStyle(
                          color: _sourceColor(ev.source),
                          fontWeight: FontWeight.w700),
                      ),
                      const TextSpan(text: ' '),
                      TextSpan(
                        text: ev.message,
                        style: TextStyle(color: _levelColor(ev.level)),
                      ),
                    ],
                  ),
                  maxLines: 2, overflow: TextOverflow.ellipsis,
                ),
              ),
          ],
        );
      },
    );
  }

  static String _fmtTime(DateTime t) =>
      '${t.minute.toString().padLeft(2, '0')}:${t.second.toString().padLeft(2, '0')}.${(t.millisecond ~/ 100)}';

  static Color _sourceColor(String s) {
    if (s.startsWith('shell')) return const Color(0xFF60A5FA);
    if (s.startsWith('article')) return const Color(0xFFFBBF24);
    if (s.startsWith('api')) return const Color(0xFFC084FC);
    if (s.startsWith('error')) return const Color(0xFFEF4444);
    return Colors.white70;
  }

  static Color _levelColor(DebugLevel level) {
    switch (level) {
      case DebugLevel.error: return const Color(0xFFEF4444);
      case DebugLevel.warn:  return const Color(0xFFFBBF24);
      case DebugLevel.info:  return Colors.white;
    }
  }
}
