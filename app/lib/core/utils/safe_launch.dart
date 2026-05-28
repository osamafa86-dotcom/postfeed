import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// Opens an external URL safely. If the URL is malformed or no app on the
/// device handles the scheme (e.g. `mailto:` with no mail client), shows
/// an Arabic snackbar instead of failing silently.
Future<bool> safeLaunch(
  BuildContext context,
  String url, {
  LaunchMode mode = LaunchMode.platformDefault,
}) async {
  try {
    final uri = Uri.parse(url);
    // Only allow web + mail/tel schemes. Blocks server-supplied URLs
    // like `javascript:` or `file:` that could otherwise reach
    // launchUrl through a compromised admin row.
    const allowed = {'https', 'http', 'mailto', 'tel'};
    if (!allowed.contains(uri.scheme.toLowerCase())) {
      if (context.mounted) _showError(context);
      return false;
    }
    if (!await canLaunchUrl(uri)) {
      if (context.mounted) _showError(context);
      return false;
    }
    return await launchUrl(uri, mode: mode);
  } catch (_) {
    if (context.mounted) _showError(context);
    return false;
  }
}

void _showError(BuildContext context) {
  ScaffoldMessenger.of(context).showSnackBar(
    const SnackBar(content: Text('تعذّر فتح الرابط')),
  );
}
