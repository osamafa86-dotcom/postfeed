import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persisted JWT for the current user. Reads happen synchronously after
/// `init()` completes, so request interceptors don't need to await.
class AuthStorage {
  AuthStorage._();
  static const _kToken = 'auth_token';
  static const _kUserId = 'auth_user_id';
  static const _store = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  static String? _cachedToken;
  static int? _cachedUserId;

  static Future<void> init() async {
    _cachedToken = await _store.read(key: _kToken);
    final uid = await _store.read(key: _kUserId);
    _cachedUserId = (uid != null) ? int.tryParse(uid) : null;
  }

  static String? get token => _cachedToken;
  static int? get userId => _cachedUserId;
  static bool get isAuthenticated => _cachedToken != null && _cachedToken!.isNotEmpty;

  static Future<void> save(String token, int userId) async {
    _cachedToken = token;
    _cachedUserId = userId;
    await _store.write(key: _kToken, value: token);
    await _store.write(key: _kUserId, value: userId.toString());
  }

  static Future<void> clear() async {
    _cachedToken = null;
    _cachedUserId = null;
    await _store.delete(key: _kToken);
    await _store.delete(key: _kUserId);
  }
}
