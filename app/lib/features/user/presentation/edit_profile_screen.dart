import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/models/user.dart';
import '../../../core/theme/app_theme.dart';
import '../../auth/data/auth_repository.dart';

class EditProfileScreen extends ConsumerStatefulWidget {
  const EditProfileScreen({super.key, required this.user});
  final AppUser user;

  @override
  ConsumerState<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends ConsumerState<EditProfileScreen> {
  late final TextEditingController _nameCtrl;
  late final TextEditingController _usernameCtrl;
  late final TextEditingController _bioCtrl;
  late bool _notifyBreaking;
  late bool _notifyFollowed;
  late bool _notifyDigest;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _nameCtrl = TextEditingController(text: widget.user.name);
    _usernameCtrl = TextEditingController(text: widget.user.username ?? '');
    _bioCtrl = TextEditingController(text: widget.user.bio);
    _notifyBreaking = widget.user.notifyBreaking;
    _notifyFollowed = widget.user.notifyFollowed;
    _notifyDigest = widget.user.notifyDigest;
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _usernameCtrl.dispose();
    _bioCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _nameCtrl.text.trim();
    if (name.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('الاسم مطلوب')),
      );
      return;
    }

    setState(() => _saving = true);
    try {
      await ref.read(authRepositoryProvider).updateProfile({
        'name': name,
        'username': _usernameCtrl.text.trim(),
        'bio': _bioCtrl.text.trim(),
        'notify': {
          'breaking': _notifyBreaking ? 1 : 0,
          'followed': _notifyFollowed ? 1 : 0,
          'digest': _notifyDigest ? 1 : 0,
        },
      });
      ref.invalidate(currentUserProvider);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تم حفظ التعديلات')),
        );
        context.pop();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$e')),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: AppBar(
        title: const Text('تعديل الملف الشخصي'),
        actions: [
          TextButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox(width: 18, height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('حفظ', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Avatar
          Center(
            child: CircleAvatar(
              radius: 44,
              backgroundColor: AppColors.primary,
              child: Text(
                widget.user.avatarLetter ??
                    (_nameCtrl.text.isNotEmpty
                        ? _nameCtrl.text.substring(0, 1).toUpperCase()
                        : 'م'),
                style: const TextStyle(color: Colors.white, fontSize: 36, fontWeight: FontWeight.w800),
              ),
            ),
          ),
          const SizedBox(height: 24),

          // Name
          _field('الاسم', _nameCtrl, Icons.person_outline, isDark),
          const SizedBox(height: 14),

          // Username
          _field('اسم المستخدم', _usernameCtrl, Icons.alternate_email, isDark),
          const SizedBox(height: 14),

          // Bio
          _field('نبذة', _bioCtrl, Icons.edit_note, isDark, maxLines: 3),
          const SizedBox(height: 14),

          // Email (read-only)
          TextField(
            readOnly: true,
            controller: TextEditingController(text: widget.user.email ?? '—'),
            decoration: InputDecoration(
              labelText: 'البريد الإلكتروني',
              prefixIcon: const Icon(Icons.email_outlined),
              filled: true,
              fillColor: isDark ? Colors.white.withOpacity(0.04) : Colors.grey.withOpacity(0.06),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
              suffixIcon: const Icon(Icons.lock_outline, size: 16),
            ),
          ),

          const SizedBox(height: 28),

          // Notification preferences
          Text('تفضيلات الإشعارات',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800,
              color: isDark ? Colors.white : AppColors.textLight)),
          const SizedBox(height: 10),

          SwitchListTile(
            title: const Text('الأخبار العاجلة'),
            subtitle: const Text('إشعار فوري عند ورود خبر عاجل'),
            value: _notifyBreaking,
            onChanged: (v) => setState(() => _notifyBreaking = v),
            activeColor: AppColors.primary,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
          SwitchListTile(
            title: const Text('المصادر المتابَعة'),
            subtitle: const Text('إشعار عند نشر خبر من مصدر تتابعه'),
            value: _notifyFollowed,
            onChanged: (v) => setState(() => _notifyFollowed = v),
            activeColor: AppColors.primary,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
          SwitchListTile(
            title: const Text('الملخص اليومي'),
            subtitle: const Text('ملخص صباحي بأهم أخبار اليوم'),
            value: _notifyDigest,
            onChanged: (v) => setState(() => _notifyDigest = v),
            activeColor: AppColors.primary,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ],
      ),
    );
  }

  Widget _field(String label, TextEditingController ctrl, IconData icon, bool isDark, {int maxLines = 1}) {
    return TextField(
      controller: ctrl,
      maxLines: maxLines,
      decoration: InputDecoration(
        labelText: label,
        prefixIcon: Icon(icon),
        filled: true,
        fillColor: isDark ? Colors.white.withOpacity(0.04) : Colors.grey.withOpacity(0.06),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
      ),
    );
  }
}
