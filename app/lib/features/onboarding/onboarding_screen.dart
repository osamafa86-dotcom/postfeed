import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../core/theme/app_theme.dart';

class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key});

  static const _seenKey = 'onboarding_seen';

  static Future<bool> hasBeenSeen() async {
    final p = await SharedPreferences.getInstance();
    return p.getBool(_seenKey) ?? false;
  }

  static Future<void> markSeen() async {
    final p = await SharedPreferences.getInstance();
    await p.setBool(_seenKey, true);
  }

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _ctl = PageController();
  int _page = 0;

  static const _pages = [
    _PageData(
      icon: Icons.auto_awesome,
      color: Color(0xFF7C3AED),
      title: 'أخبار ذكية بالـ AI',
      subtitle: 'كل خبر يأتي مع ملخص ذكاء اصطناعي\nونقاط رئيسية — اقرأ أسرع وافهم أعمق',
      illustration: '🤖',
    ),
    _PageData(
      icon: Icons.chat_bubble_outline,
      color: Color(0xFF0EA5E9),
      title: 'اسأل الأخبار',
      subtitle: 'اسأل أي سؤال واحصل على إجابة فورية\nمن أرشيف آلاف الأخبار والمصادر',
      illustration: '💬',
    ),
    _PageData(
      icon: Icons.cell_tower,
      color: Color(0xFF059669),
      title: 'كل المنصات في مكان واحد',
      subtitle: 'أخبار تلغرام وتويتر ويوتيوب\nمجمّعة من عشرات المصادر الفلسطينية والعربية',
      illustration: '📡',
    ),
    _PageData(
      icon: Icons.wb_sunny_outlined,
      color: Color(0xFFD97706),
      title: 'بريفينغ الصباح',
      subtitle: 'كل صباح ملخص مخصص لاهتماماتك\nمع خيار الاستماع صوتياً وأنت في طريقك',
      illustration: '☀️',
    ),
  ];

  void _next() {
    if (_page < _pages.length - 1) {
      _ctl.nextPage(duration: const Duration(milliseconds: 350), curve: Curves.easeOutCubic);
    } else {
      _finish();
    }
  }

  void _finish() async {
    await OnboardingScreen.markSeen();
    if (mounted) context.go('/');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          // Pages
          PageView.builder(
            controller: _ctl,
            itemCount: _pages.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (_, i) => _OnboardingPage(data: _pages[i]),
          ),

          // Skip button
          Positioned(
            top: MediaQuery.of(context).padding.top + 12,
            left: 16,
            child: _page < _pages.length - 1
                ? TextButton(
                    onPressed: _finish,
                    child: Text('تخطي', style: TextStyle(
                      color: _pages[_page].color.withOpacity(0.6),
                      fontWeight: FontWeight.w600,
                    )),
                  )
                : const SizedBox.shrink(),
          ),

          // Bottom controls
          Positioned(
            left: 0, right: 0,
            bottom: MediaQuery.of(context).padding.bottom + 32,
            child: Column(
              children: [
                // Page indicators
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: List.generate(_pages.length, (i) {
                    final isActive = i == _page;
                    return AnimatedContainer(
                      duration: const Duration(milliseconds: 250),
                      margin: const EdgeInsets.symmetric(horizontal: 4),
                      width: isActive ? 28 : 8,
                      height: 8,
                      decoration: BoxDecoration(
                        color: isActive ? _pages[_page].color : _pages[_page].color.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(4),
                      ),
                    );
                  }),
                ),
                const SizedBox(height: 32),
                // Action button
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 40),
                  child: SizedBox(
                    width: double.infinity,
                    height: 56,
                    child: ElevatedButton(
                      onPressed: _next,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: _pages[_page].color,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(16),
                        ),
                        elevation: 4,
                        shadowColor: _pages[_page].color.withOpacity(0.4),
                      ),
                      child: Text(
                        _page < _pages.length - 1 ? 'التالي' : 'ابدأ الآن',
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _OnboardingPage extends StatelessWidget {
  const _OnboardingPage({required this.data});
  final _PageData data;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(32, MediaQuery.of(context).padding.top + 60, 32, 160),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          // Large illustration
          Container(
            width: 160,
            height: 160,
            decoration: BoxDecoration(
              color: data.color.withOpacity(0.08),
              shape: BoxShape.circle,
            ),
            alignment: Alignment.center,
            child: Text(data.illustration, style: const TextStyle(fontSize: 72)),
          ),
          const SizedBox(height: 48),
          // Icon + title
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                width: 36, height: 36,
                decoration: BoxDecoration(
                  color: data.color,
                  borderRadius: BorderRadius.circular(10),
                ),
                alignment: Alignment.center,
                child: Icon(data.icon, color: Colors.white, size: 20),
              ),
              const SizedBox(width: 12),
              Text(
                data.title,
                style: TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w900,
                  color: data.color,
                ),
              ),
            ],
          ),
          const SizedBox(height: 20),
          Text(
            data.subtitle,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 16,
              height: 1.7,
              color: Theme.of(context).textTheme.bodyMedium?.color?.withOpacity(0.7),
            ),
          ),
        ],
      ),
    );
  }
}

class _PageData {
  const _PageData({
    required this.icon,
    required this.color,
    required this.title,
    required this.subtitle,
    required this.illustration,
  });
  final IconData icon;
  final Color color;
  final String title;
  final String subtitle;
  final String illustration;
}
