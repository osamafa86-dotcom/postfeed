import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../../core/models/home_payload.dart';
import '../../../../core/theme/app_theme.dart';

/// Mirrors the dismissable green-teal banner shown under the stats strip on
/// Sundays:
///   [📅] مراجعة الأسبوع: <title>      [اقرأ →]   ✕
///        <subtitle>
///
/// The website keys dismissal on `wr_dismissed_<year_week>` in localStorage —
/// we persist the same key in SharedPreferences so dismissals survive across
/// sessions and the banner stays gone until the next weekly rewind ships.
class WeeklyRewindBanner extends StatefulWidget {
  const WeeklyRewindBanner({super.key, required this.cover});
  final WeeklyRewindCover cover;

  @override
  State<WeeklyRewindBanner> createState() => _WeeklyRewindBannerState();
}

class _WeeklyRewindBannerState extends State<WeeklyRewindBanner> {
  bool _dismissed = false;
  bool _checked = false;

  String get _key => 'wr_dismissed_${widget.cover.yearWeek}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final prefs = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() {
      _dismissed = prefs.getBool(_key) ?? false;
      _checked = true;
    });
  }

  Future<void> _dismiss() async {
    setState(() => _dismissed = true);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_key, true);
  }

  @override
  Widget build(BuildContext context) {
    if (!_checked || _dismissed) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () => context.push('/weekly'),
          child: Stack(
            children: [
              Container(
                padding: const EdgeInsetsDirectional.fromSTEB(14, 14, 14, 14),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: Alignment.topRight,
                    end: Alignment.bottomLeft,
                    colors: [Color(0xFF0F172A), Color(0xFF1A5C5C)],
                  ),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AppColors.goldBright.withOpacity(0.35)),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.accent2.withOpacity(0.35),
                      blurRadius: 28,
                      offset: const Offset(0, 10),
                    ),
                  ],
                ),
                child: Row(
                  children: [
                    Container(
                      width: 48,
                      height: 48,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: AppColors.goldBright.withOpacity(0.95),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Text('📅', style: TextStyle(fontSize: 24)),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'مراجعة الأسبوع: ${widget.cover.coverTitle}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 14,
                              fontWeight: FontWeight.w800,
                              height: 1.35,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            widget.cover.coverSubtitle,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Color(0xCCFFFFFF),
                              fontSize: 12,
                              fontWeight: FontWeight.w500,
                              height: 1.4,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                      decoration: BoxDecoration(
                        color: AppColors.goldBright,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Text(
                        'اقرأ ←',
                        style: TextStyle(
                          color: Color(0xFF1A1A2E),
                          fontWeight: FontWeight.w800,
                          fontSize: 12,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              PositionedDirectional(
                top: 2,
                start: 2,
                child: IconButton(
                  tooltip: 'إغلاق',
                  onPressed: _dismiss,
                  iconSize: 18,
                  visualDensity: VisualDensity.compact,
                  padding: const EdgeInsets.all(4),
                  icon: const Text(
                    '×',
                    style: TextStyle(
                      color: Color(0xCCFFFFFF),
                      fontSize: 22,
                      fontWeight: FontWeight.w400,
                      height: 1,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
