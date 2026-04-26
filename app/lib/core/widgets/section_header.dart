import 'package:flutter/material.dart';

class SectionHeader extends StatelessWidget {
  const SectionHeader({super.key, required this.title, this.icon, this.onMore});

  final String title;
  final IconData? icon;
  final VoidCallback? onMore;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 18, 16, 8),
      child: Row(
        children: [
          if (icon != null) Icon(icon, color: theme.colorScheme.primary, size: 20),
          if (icon != null) const SizedBox(width: 8),
          Expanded(
            child: Text(
              title,
              style: theme.textTheme.titleLarge,
            ),
          ),
          if (onMore != null)
            TextButton(
              onPressed: onMore,
              child: const Text('عرض الكل'),
            ),
        ],
      ),
    );
  }
}
