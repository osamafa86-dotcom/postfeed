import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../user/data/user_repository.dart';
import '../data/auth_repository.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});
  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _email = TextEditingController();
  final _pass = TextEditingController();
  bool _busy = false;
  String? _err;

  @override
  void dispose() {
    _email.dispose();
    _pass.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() { _busy = true; _err = null; });
    try {
      await ref.read(authRepositoryProvider).login(email: _email.text, password: _pass.text);
      ref.invalidate(currentUserProvider);
      ref.invalidate(followedIdsProvider);
      if (mounted) context.go('/');
    } catch (e) {
      setState(() => _err = '$e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _runSocial(Future<void> Function() fn) async {
    setState(() { _busy = true; _err = null; });
    try {
      await fn();
      ref.invalidate(currentUserProvider);
      ref.invalidate(followedIdsProvider);
      if (mounted) context.go('/');
    } catch (e) {
      if (mounted) setState(() => _err = '$e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _appleSignIn() => _runSocial(
      () async => ref.read(authRepositoryProvider).signInWithApple());

  Future<void> _googleSignIn() => _runSocial(
      () async => ref.read(authRepositoryProvider).signInWithGoogle());

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('تسجيل الدخول')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const SizedBox(height: 8),
          TextField(
            controller: _email,
            decoration: const InputDecoration(labelText: 'البريد الإلكتروني', border: OutlineInputBorder()),
            keyboardType: TextInputType.emailAddress,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _pass,
            decoration: const InputDecoration(labelText: 'كلمة المرور', border: OutlineInputBorder()),
            obscureText: true,
          ),
          if (_err != null) Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Text(_err!, style: const TextStyle(color: Colors.red)),
          ),
          const SizedBox(height: 16),
          ElevatedButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('دخول'),
          ),
          const SizedBox(height: 4),
          TextButton(
            onPressed: () => context.push('/forgot-password'),
            child: const Text('نسيت كلمة المرور؟'),
          ),

          // ── Social sign-in ──
          if (defaultTargetPlatform == TargetPlatform.iOS ||
              kGoogleSignInEnabled) ...[
            const SizedBox(height: 16),
            Row(children: const [
              Expanded(child: Divider()),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: 10),
                child: Text('أو', style: TextStyle(color: Colors.grey)),
              ),
              Expanded(child: Divider()),
            ]),
            const SizedBox(height: 12),
          ],
          if (defaultTargetPlatform == TargetPlatform.iOS)
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _busy ? null : _appleSignIn,
                icon: const Icon(Icons.apple, size: 22),
                label: const Text('متابعة بـ Apple'),
                style: FilledButton.styleFrom(
                  backgroundColor: Colors.black,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 13),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ),
          if (kGoogleSignInEnabled) ...[
            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: _busy ? null : _googleSignIn,
                icon: const Icon(Icons.g_mobiledata,
                    size: 28, color: Color(0xFF4285F4)),
                label: const Text('متابعة بـ Google'),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 13),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ),
          ],

          TextButton(
            onPressed: () => context.go('/register'),
            child: const Text('ليس لديك حساب؟ سجّل الآن'),
          ),
        ],
      ),
    );
  }
}
