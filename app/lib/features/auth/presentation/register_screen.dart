import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

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

  Future<void> _submit() async {
    setState(() { _busy = true; _err = null; });
    try {
      await ref.read(authRepositoryProvider).register(
        name: _name.text, email: _email.text, password: _pass.text,
      );
      ref.invalidate(currentUserProvider);
      if (mounted) context.go('/');
    } catch (e) {
      setState(() => _err = '$e');
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
        ],
      ),
    );
  }
}
