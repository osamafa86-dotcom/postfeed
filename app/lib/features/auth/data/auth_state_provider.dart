import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth_storage.dart';

/// Bridges `AuthStorage` (a static, non-reactive token cache) into
/// Riverpod so ConsumerWidgets actually rebuild when the user signs
/// in or out.
///
/// WHY: The original auth flow used `AuthStorage.isAuthenticated`
/// directly inside ConsumerWidget.build(). That static getter is not
/// observable — Riverpod has no idea its value changed. Combined with
/// MainShell's `IndexedStack` (which keeps tab widgets alive
/// indefinitely), screens like FollowScreen and NotificationsScreen
/// would render their "please sign in" branch ONCE at app start and
/// never refresh, even after the user successfully logged in. Apple
/// reviewers hit this on 2.2.1(19) — "App required us to sign in
/// again when we tapped on 'My follows'" — because the reviewer
/// signed in, then tapped the tab, and saw the cached signed-out UI.
///
/// HOW: A simple StateNotifier seeded from AuthStorage. `LoginScreen`
/// / `RegisterScreen` / `SettingsScreen` call `notifier.refresh()`
/// after save/clear, which broadcasts to every watching widget.
class AuthStateNotifier extends StateNotifier<bool> {
  AuthStateNotifier() : super(AuthStorage.isAuthenticated);

  /// Re-read AuthStorage and broadcast the current value. Call after
  /// any code path that mutates the underlying token (login, logout,
  /// account delete, 401 interceptor wipe).
  void refresh() {
    state = AuthStorage.isAuthenticated;
  }
}

final authStateProvider =
    StateNotifierProvider<AuthStateNotifier, bool>((ref) => AuthStateNotifier());
