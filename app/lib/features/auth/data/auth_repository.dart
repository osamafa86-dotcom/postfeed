import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/models/user.dart';
import 'auth_storage.dart';

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
    final data = res.data!;
    final user = AppUser.fromJson((data['user'] as Map).cast());
    await AuthStorage.save(data['token'] as String, user.id);
    return user;
  }

  Future<AppUser> login({required String email, required String password}) async {
    final res = await _api.post<Map<String, dynamic>>(
      '/auth/login',
      body: {'email': email, 'password': password},
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    final data = res.data!;
    final user = AppUser.fromJson((data['user'] as Map).cast());
    await AuthStorage.save(data['token'] as String, user.id);
    return user;
  }

  Future<AppUser?> me() async {
    if (!AuthStorage.isAuthenticated) return null;
    try {
      final res = await _api.get<Map<String, dynamic>>(
        '/auth/me',
        decode: (d) => (d as Map).cast<String, dynamic>(),
      );
      return AppUser.fromJson((res.data!['user'] as Map).cast());
    } catch (_) {
      return null;
    }
  }

  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } catch (_) {}
    await AuthStorage.clear();
  }

  Future<void> deleteAccount() async {
    await _api.delete('/auth/account');
    await AuthStorage.clear();
  }

  Future<AppUser> updateProfile(Map<String, dynamic> patch) async {
    final res = await _api.patch<Map<String, dynamic>>(
      '/user/profile',
      body: patch,
      decode: (d) => (d as Map).cast<String, dynamic>(),
    );
    return AppUser.fromJson((res.data!['user'] as Map).cast());
  }
}

final authRepositoryProvider =
    Provider<AuthRepository>((ref) => AuthRepository(ref.watch(apiClientProvider)));

final currentUserProvider = FutureProvider<AppUser?>((ref) async {
  return ref.watch(authRepositoryProvider).me();
});
