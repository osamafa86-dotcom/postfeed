import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

// ═══════════════════════════════════════════════════════════════
// App Theme Mode — light / dark / system / scheduled (auto night)
// ═══════════════════════════════════════════════════════════════

/// Extended theme mode that includes a scheduled (time-based) option.
enum AppThemeMode {
  light,
  dark,
  system,
  scheduled, // Dark at night, light during day
}

class ThemeModeController extends Notifier<ThemeMode> {
  static const _modeKey = 'theme_mode';
  static const _darkFromKey = 'night_from_hour'; // 0–23
  static const _darkToKey = 'night_to_hour';     // 0–23

  Timer? _scheduledTimer;

  /// The raw app-level mode (includes 'scheduled').
  AppThemeMode _appMode = AppThemeMode.system;
  AppThemeMode get appMode => _appMode;

  /// Night window — default 19:00 → 06:00
  int _darkFromHour = 19;
  int _darkToHour = 6;
  int get darkFromHour => _darkFromHour;
  int get darkToHour => _darkToHour;

  @override
  ThemeMode build() {
    _load();
    return ThemeMode.system;
  }

  Future<void> _load() async {
    final p = await SharedPreferences.getInstance();
    _darkFromHour = p.getInt(_darkFromKey) ?? 19;
    _darkToHour = p.getInt(_darkToKey) ?? 6;

    switch (p.getString(_modeKey)) {
      case 'light':
        _appMode = AppThemeMode.light;
        state = ThemeMode.light;
      case 'dark':
        _appMode = AppThemeMode.dark;
        state = ThemeMode.dark;
      case 'scheduled':
        _appMode = AppThemeMode.scheduled;
        _applyScheduled();
        _startScheduledTimer();
      default:
        _appMode = AppThemeMode.system;
        state = ThemeMode.system;
    }
  }

  Future<void> setMode(AppThemeMode mode) async {
    _appMode = mode;
    _stopScheduledTimer();

    final p = await SharedPreferences.getInstance();
    final modeStr = switch (mode) {
      AppThemeMode.light => 'light',
      AppThemeMode.dark => 'dark',
      AppThemeMode.system => 'auto',
      AppThemeMode.scheduled => 'scheduled',
    };
    await p.setString(_modeKey, modeStr);

    switch (mode) {
      case AppThemeMode.light:
        state = ThemeMode.light;
      case AppThemeMode.dark:
        state = ThemeMode.dark;
      case AppThemeMode.system:
        state = ThemeMode.system;
      case AppThemeMode.scheduled:
        _applyScheduled();
        _startScheduledTimer();
    }
  }

  /// Legacy setter for backward compat.
  Future<void> set(ThemeMode mode) async {
    final appMode = switch (mode) {
      ThemeMode.light => AppThemeMode.light,
      ThemeMode.dark => AppThemeMode.dark,
      ThemeMode.system => AppThemeMode.system,
    };
    await setMode(appMode);
  }

  /// Update the night window hours.
  Future<void> setNightWindow(int fromHour, int toHour) async {
    _darkFromHour = fromHour.clamp(0, 23);
    _darkToHour = toHour.clamp(0, 23);

    final p = await SharedPreferences.getInstance();
    await p.setInt(_darkFromKey, _darkFromHour);
    await p.setInt(_darkToKey, _darkToHour);

    if (_appMode == AppThemeMode.scheduled) {
      _applyScheduled();
    }
  }

  bool _isNightTime() {
    final hour = DateTime.now().hour;
    if (_darkFromHour > _darkToHour) {
      // e.g. 19 → 6: night = hour >= 19 OR hour < 6
      return hour >= _darkFromHour || hour < _darkToHour;
    } else {
      // e.g. 22 → 23 (unusual but valid)
      return hour >= _darkFromHour && hour < _darkToHour;
    }
  }

  void _applyScheduled() {
    state = _isNightTime() ? ThemeMode.dark : ThemeMode.light;
  }

  void _startScheduledTimer() {
    _stopScheduledTimer();
    // Check every minute to switch at the right time
    _scheduledTimer = Timer.periodic(const Duration(minutes: 1), (_) {
      if (_appMode == AppThemeMode.scheduled) {
        final newMode = _isNightTime() ? ThemeMode.dark : ThemeMode.light;
        if (state != newMode) {
          state = newMode;
        }
      }
    });
  }

  void _stopScheduledTimer() {
    _scheduledTimer?.cancel();
    _scheduledTimer = null;
  }
}

final themeModeControllerProvider =
    NotifierProvider<ThemeModeController, ThemeMode>(ThemeModeController.new);
