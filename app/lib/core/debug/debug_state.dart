import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

/// Global, in-memory trace log. Read by [DebugOverlay] and written from
/// MainShell, ArticleScreen, FlutterError handlers, and the API client.
/// No persistence — opens fresh on every app launch. Designed so a single
/// screenshot of the running app captures everything we need to chase a
/// blank-screen bug without rebuilding for new prints.
class DebugTrace {
  static final ValueNotifier<List<DebugEvent>> events =
      ValueNotifier<List<DebugEvent>>(const []);

  /// Free-form "current state" lines, keyed by their source so the latest
  /// line per source overwrites the previous (otherwise the list bloats).
  /// Examples: 'shell.route', 'shell.isTab', 'article.state'.
  static final ValueNotifier<Map<String, String>> probes =
      ValueNotifier<Map<String, String>>(const {});

  /// Master toggle — `true` shows the overlay. Long-press the bottom nav
  /// to flip it. Persisted across hot-reload via a static field; cold
  /// launches start with [_initiallyOn].
  static const bool _initiallyOn = true;
  static final ValueNotifier<bool> visible = ValueNotifier<bool>(_initiallyOn);

  static void log(String source, String message, {DebugLevel level = DebugLevel.info}) {
    final ev = DebugEvent(
      timestamp: DateTime.now(),
      source: source,
      message: message,
      level: level,
    );
    // Keep at most 40 events — enough for a multi-step repro screenshot.
    final next = [...events.value, ev];
    if (next.length > 40) next.removeRange(0, next.length - 40);
    events.value = next;
    if (kDebugMode) debugPrint('[trace][${ev.source}] ${ev.message}');
  }

  static void probe(String key, String value) {
    final next = Map<String, String>.from(probes.value);
    next[key] = value;
    probes.value = next;
  }

  static void clear() {
    events.value = const [];
    probes.value = const {};
  }
}

enum DebugLevel { info, warn, error }

class DebugEvent {
  const DebugEvent({
    required this.timestamp,
    required this.source,
    required this.message,
    required this.level,
  });
  final DateTime timestamp;
  final String source;
  final String message;
  final DebugLevel level;
}
