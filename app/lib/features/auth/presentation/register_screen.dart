import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../user/data/user_repository.dart';
import '../data/auth_repository.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});
  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _pass = TextEditingController();
  bool _busy = false;
  String? _err;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _pass.dispose();
    super.dispose();
  }

  static final _emailRe = RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$');

  String? _validate() {
    final name = _name.text.trim();
    final email = _email.text.trim();
    final pass = _pass.text;
    if (name.length < 2) return 'الاسم قصير جداً';
    if (!_emailRe.hasMatch(email)) return 'بريد إلكتروني غير صالح';
    if (pass.length < 6) return 'كلمة المرور يجب أن تكون 6 أحرف فأكثر';
    return null;
  }

  Future<void> _submit() async {
    final err = _validate();
    if (err != null) {
      setState(() => _err = err);
      return;
    }
    setState(() { _busy = true; _err = null; });
    try {
      await ref.read(authRepositoryProvider).register(
        name: _name.text.trim(),
        email: _email.text.trim(),
        password: _pass.text,
      );
      ref.invalidate(currentUserProvider);
      ref.invalidate(followedIdsProvider);
      if (mounted) context.go('/');
    } catch (e) {
      setState(() => _err = e is ApiException ? e.userMessage : '$e');
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
      if (mounted) setState(() => _err = e is ApiException ? e.userMessage : '$e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('إنشاء حساب')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          TextField(controller: _name, decoration: const InputDecoration(labelText: 'الاسم', border: OutlineInputBorder())),
          const SizedBox(height: 12),
          TextField(controller: _email, decoration: const InputDecoration(labelText: 'البريد الإلكتروني', border: OutlineInputBorder())),
          const SizedBox(height: 12),
          TextField(controller: _pass, decoration: const InputDecoration(labelText: 'كلمة المرور', border: OutlineInputBorder()), obscureText: true),
          if (_err != null) Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Text(_err!, style: const TextStyle(color: Colors.red)),
          ),
          const SizedBox(height: 16),
          ElevatedButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('إنشاء الحساب'),
          ),

          // ── Social sign-up — same providers as login ──
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
                onPressed: _busy
                    ? null
                    : () => _runSocial(() async => ref
                        .read(authRepositoryProvider)
                        .signInWithApple()),
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
                onPressed: _busy
                    ? null
                    : () => _runSocial(() async => ref
                        .read(authRepositoryProvider)
                        .signInWithGoogle()),
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
        ],
      ),
    );
  }
}
