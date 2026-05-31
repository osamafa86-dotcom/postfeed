import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/models/user.dart';
import 'auth_storage.dart';

/// Flip to true once you've created the iOS OAuth client in Google
/// Cloud Console AND added GIDClientID + reversed-client-id URL scheme
/// in app/ios/Runner/Info.plist. Until then the Google button is hidden
/// so it can't be tapped and crash.
///
/// IMPORTANT (App Store Guideline 4.8): on iOS, "Sign in with Apple"
/// MUST remain visible on the same screen whenever Google (or any
/// third-party login) is offered. login_screen.dart and register_screen.dart
/// show the Apple button unconditionally when `defaultTargetPlatform`
/// is iOS — keep it that way.
const bool kGoogleSignInEnabled = false;

class AuthRepository {
  AuthRepository(this._api);
  final ApiClient _api;

  Future<AppUser> register({
    required String name,
    required String email,
    required String password,
    String? username,
  }) async {
    final res = await _api.post<Map<String, dynamic>>(
      '/auth/register',
      body: {
        'name': name,
        'email': email,
        'password': password,
        if (username != null && username.isNotEmpty) 'username': username,
      },
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return _persistAuthFromResponse(res.data);
  }

  Future<AppUser> login({required String email, required String password}) async {
    final res = await _api.post<Map<String, dynamic>>(
      '/auth/login',
      body: {'email': email, 'password': password},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return _persistAuthFromResponse(res.data);
  }

  /// Native Sign in with Apple. iOS only — Android falls through the
  /// package's web flow which we don't wire up here.
  Future<AppUser> signInWithApple() async {
    final cred = await _getAppleCredential();
    final idToken = cred.identityToken;
    if (idToken == null || idToken.isEmpty) {
      throw const ApiException('apple_signin_no_token',
          'لم تُرسل Apple رمز التحقّق — تأكّد من تسجيل الدخول إلى حساب Apple ID على الجهاز');
    }
    // Apple sends name + email ONLY on the first authorization; we
    // forward them so the server can populate the new account. On
    // subsequent sign-ins Apple omits both, but the id_token still
    // carries the email claim (incl. the privaterelay address when
    // Hide My Email is enabled), so the server can still resolve the
    // account by it.
    final name = [cred.givenName, cred.familyName]
        .where((s) => s != null && s.isNotEmpty)
        .join(' ');
    final res = await _api.post<Map<String, dynamic>>(
      '/auth/oauth/apple',
      body: {
        'id_token': idToken,
        if (name.isNotEmpty) 'name': name,
        if (cred.email != null && cred.email!.isNotEmpty) 'email': cred.email,
      },
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return _persistAuthFromResponse(res.data);
  }

  /// Wraps `SignInWithApple.getAppleIDCredential` so each failure mode
  /// becomes a distinct Arabic `ApiException`. The default plugin
  /// errors are opaque (e.g. `SignInWithAppleAuthorizationException
  /// (code: canceled, ...)`), which the user/reviewer can't act on —
  /// the most likely cause of the 2.2.1(20) iPad rejection was the
  /// reviewer hitting "cancel" or the sheet failing, then seeing a
  /// generic error and reporting "we were unable to login".
  Future<AuthorizationCredentialAppleID> _getAppleCredential() async {
    try {
      return await SignInWithApple.getAppleIDCredential(scopes: [
        AppleIDAuthorizationScopes.email,
        AppleIDAuthorizationScopes.fullName,
      ]);
    } on SignInWithAppleAuthorizationException catch (e) {
      // Default branch matters here — newer sign_in_with_apple platform
      // versions added codes like `notInteractive` / `credentialImport`
      // that Dart's exhaustive-switch checker now flags. Falling back to
      // a generic apology preserves forward-compat without breaking the
      // build every time the plugin gains a code.
      switch (e.code) {
        case AuthorizationErrorCode.canceled:
          throw const ApiException(
              'apple_signin_cancelled', 'تم إلغاء تسجيل الدخول');
        case AuthorizationErrorCode.notHandled:
          throw const ApiException('apple_signin_unavailable',
              'تسجيل الدخول بـ Apple غير متاح على هذا الجهاز — جرّب البريد الإلكتروني');
        case AuthorizationErrorCode.failed:
          throw ApiException('apple_signin_failed',
              'فشل تسجيل الدخول بـ Apple: ${e.message}');
        case AuthorizationErrorCode.invalidResponse:
          throw const ApiException('apple_signin_invalid',
              'استجابة غير صالحة من Apple — أعد المحاولة');
        case AuthorizationErrorCode.unknown:
          throw ApiException('apple_signin_unknown',
              'حدث خطأ من Apple: ${e.message}');
        default:
          throw ApiException('apple_signin_other',
              'لم يكتمل تسجيل الدخول بـ Apple (${e.code.name}): ${e.message}');
      }
    } on SignInWithAppleNotSupportedException catch (_) {
      throw const ApiException('apple_signin_unsupported',
          'تسجيل الدخول بـ Apple يحتاج iOS 13 أو أحدث');
    }
  }

  /// Google Sign-In. Requires the iOS client to be set up in Google
  /// Cloud Console and its config wired into Info.plist (GIDClientID +
  /// reversed-client-id URL scheme). Guarded by kGoogleSignInEnabled so
  /// the button is hidden until that setup is done.
  Future<AppUser> signInWithGoogle() async {
    final google = GoogleSignIn(scopes: const ['email']);
    final account = await google.signIn();
    if (account == null) {
      throw const ApiException(
          'google_signin_cancelled', 'تم إلغاء تسجيل الدخول');
    }
    final auth = await account.authentication;
    final idToken = auth.idToken;
    if (idToken == null || idToken.isEmpty) {
      throw const ApiException(
          'google_signin_failed', 'تعذّر الحصول على توكن من Google');
    }
    final res = await _api.post<Map<String, dynamic>>(
      '/auth/oauth/google',
      body: {
        'id_token': idToken,
        if (account.displayName != null) 'name': account.displayName,
        'email': account.email,
      },
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return _persistAuthFromResponse(res.data);
  }

  Future<AppUser> _persistAuthFromResponse(Map<String, dynamic>? data) async {
    if (data == null) {
      throw const ApiException('invalid_response', 'استجابة غير متوقعة من الخادم');
    }
    final userMap = data['user'];
    final token = data['token'];
    if (userMap is! Map || token is! String || token.isEmpty) {
      throw const ApiException('invalid_response', 'استجابة غير متوقعة من الخادم');
    }
    final user = AppUser.fromJson(userMap.cast<String, dynamic>());
    await AuthStorage.save(token, user.id);
    return user;
  }

  Future<AppUser?> me() async {
    if (!AuthStorage.isAuthenticated) return null;
    try {
      final res = await _api.get<Map<String, dynamic>>(
        '/auth/me',
        decode: (d) => (d as Map).cast<String, dynamic>(),
      );
      final userMap = res.data?['user'];
      if (userMap is! Map) return null;
      return AppUser.fromJson(userMap.cast<String, dynamic>());
    } catch (_) {
      return null;
    }
  }

  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } catch (_) {}
    // Unregister this device's FCM token so pushes destined for the
    // logged-out user don't continue to arrive on this device after
    // a different user signs in (or no one does).
    try {
      await _api.delete('/user/devices');
    } catch (_) {}
    await AuthStorage.clear();
  }

  Future<void> deleteAccount() async {
    await _api.delete('/auth/account');
    await AuthStorage.clear();
  }

  /// Step 1 of password recovery: emails a 6-digit code to the user.
  /// Always succeeds — the server returns 200 even for unknown emails
  /// (to prevent account enumeration).
  Future<void> requestPasswordReset(String email) async {
    await _api.post('/auth/forgot', body: {'email': email});
  }

  /// Step 2 of password recovery: consumes the emailed code and sets
  /// a new password. Throws ApiException on bad/expired code.
  Future<void> resetPassword({
    required String email,
    required String code,
    required String newPassword,
  }) async {
    await _api.post('/auth/reset', body: {
      'email': email,
      'code': code,
      'password': newPassword,
    });
  }

  Future<AppUser> updateProfile(Map<String, dynamic> patch) async {
    final res = await _api.patch<Map<String, dynamic>>(
      '/user/profile',
      body: patch,
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    final userMap = res.data?['user'];
    if (userMap is! Map) {
      throw const ApiException('invalid_response', 'تعذّر تحديث الملف الشخصي');
    }
    return AppUser.fromJson(userMap.cast<String, dynamic>());
  }
}

final authRepositoryProvider =
    Provider<AuthRepository>((ref) => AuthRepository(ref.watch(apiClientProvider)));

final currentUserProvider = FutureProvider<AppUser?>((ref) async {
  return ref.watch(authRepositoryProvider).me();
});
