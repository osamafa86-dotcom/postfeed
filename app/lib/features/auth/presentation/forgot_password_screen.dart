import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../data/auth_repository.dart';

/// Two-step recovery: email → emailed 6-digit code + new password.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});
  @override
  ConsumerState<ForgotPasswordScreen> createState() =>
      _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState
    extends ConsumerState<ForgotPasswordScreen> {
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _newPass = TextEditingController();
  bool _busy = false;
  String? _err;
  String? _info;
  bool _codeSent = false;

  static final _emailRe = RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$');
  static final _codeRe = RegExp(r'^\d{6}$');

  @override
  void dispose() {
    _email.dispose();
    _code.dispose();
    _newPass.dispose();
    super.dispose();
  }

  Future<void> _sendCode() async {
    final email = _email.text.trim();
    if (!_emailRe.hasMatch(email)) {
      setState(() => _err = 'بريد إلكتروني غير صالح');
      return;
    }
    setState(() {
      _busy = true;
      _err = null;
      _info = null;
    });
    try {
      await ref.read(authRepositoryProvider).requestPasswordReset(email);
      if (!mounted) return;
      setState(() {
        _codeSent = true;
        _info = 'أرسلنا رمز التحقّق إلى بريدك. تحقّق من البريد الوارد '
            '(وملف الـ Spam إن لزم).';
      });
    } catch (e) {
      if (mounted) setState(() => _err = '$e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resetPassword() async {
    final code = _code.text.trim();
    final newPass = _newPass.text;
    if (!_codeRe.hasMatch(code)) {
      setState(() => _err = 'الرمز يجب أن يكون 6 أرقام');
      return;
    }
    if (newPass.length < 6) {
      setState(() => _err = 'كلمة المرور يجب أن تكون 6 أحرف فأكثر');
      return;
    }
    setState(() {
      _busy = true;
      _err = null;
      _info = null;
    });
    try {
      await ref.read(authRepositoryProvider).resetPassword(
            email: _email.text.trim(),
            code: code,
            newPassword: newPass,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('تم تعيين كلمة مرور جديدة. سجّل دخولك الآن.')),
      );
      context.go('/login');
    } catch (e) {
      if (mounted) setState(() => _err = '$e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('استعادة كلمة المرور')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          if (!_codeSent) ...[
            const Text(
              'أدخل بريدك الإلكتروني وسنرسل لك رمزاً لإعادة تعيين كلمة المرور.',
              style: TextStyle(fontSize: 14, height: 1.6),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.email],
              decoration: const InputDecoration(
                labelText: 'البريد الإلكتروني',
                border: OutlineInputBorder(),
              ),
            ),
          ] else ...[
            Text(
              'أرسلنا رمزاً مكوّناً من 6 أرقام إلى:\n${_email.text.trim()}',
              style: const TextStyle(fontSize: 14, height: 1.6),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              maxLength: 6,
              autofillHints: const [AutofillHints.oneTimeCode],
              decoration: const InputDecoration(
                labelText: 'الرمز (6 أرقام)',
                border: OutlineInputBorder(),
                counterText: '',
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _newPass,
              obscureText: true,
              autofillHints: const [AutofillHints.newPassword],
              decoration: const InputDecoration(
                labelText: 'كلمة المرور الجديدة',
                helperText: '6 أحرف فأكثر',
                border: OutlineInputBorder(),
              ),
            ),
          ],
          if (_info != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(_info!, style: const TextStyle(color: Colors.green)),
            ),
          if (_err != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(_err!, style: const TextStyle(color: Colors.red)),
            ),
          const SizedBox(height: 16),
          ElevatedButton(
            onPressed:
                _busy ? null : (_codeSent ? _resetPassword : _sendCode),
            child: _busy
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(_codeSent ? 'تعيين كلمة المرور' : 'إرسال الرمز'),
          ),
          if (_codeSent)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: TextButton(
                onPressed: _busy
                    ? null
                    : () => setState(() {
                          _codeSent = false;
                          _code.clear();
                          _newPass.clear();
                          _err = null;
                          _info = null;
                        }),
                child: const Text('تغيير البريد الإلكتروني'),
              ),
            ),
        ],
      ),
    );
  }
}
