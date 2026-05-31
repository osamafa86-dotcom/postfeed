import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Persisted reading text-scale for the article view. Lets the reader make
/// article text larger/smaller without affecting the rest of the app's UI.
/// Range 0.85–1.60 in 0.15 steps; default 1.0 (100%).
class ReaderTextScaleController extends Notifier<double> {
  static const _key = 'reader_text_scale';
  static const double min = 0.85;
  static const double max = 1.60;
  static const double step = 0.15;

  @override
  double build() {
    _load();
    return 1.0;
  }

  Future<void> _load() async {
    final p = await SharedPreferences.getInstance();
    final v = p.getDouble(_key);
    if (v != null) state = v.clamp(min, max).toDouble();
  }

  Future<void> _save(double v) async {
    final p = await SharedPreferences.getInstance();
    await p.setDouble(_key, v);
  }

  /// Set an explicit scale (clamped + rounded to 2 decimals).
  void set(double v) {
    final clamped = double.parse(v.clamp(min, max).toStringAsFixed(2));
    if (clamped == state) return;
    state = clamped;
    _save(clamped);
  }

  void increase() => set(state + step);
  void decrease() => set(state - step);
  void reset() => set(1.0);
}

final readerTextScaleProvider =
    NotifierProvider<ReaderTextScaleController, double>(
        ReaderTextScaleController.new);
