import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../core/models/category.dart';
import '../../core/storage/interests_store.dart';
import '../../core/theme/app_theme.dart';
import '../auth/data/auth_storage.dart';
import '../content/data/content_repository.dart';
import '../user/data/user_repository.dart';

class OnboardingScreen extends ConsumerStatefulWidget {
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
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _ctl = PageController();
  int _page = 0;
  final Set<int> _selectedCategories = {};

  // Number of intro pages before the interactive interest picker.
  static const _introCount = 4;
  int get _totalPages => _introCount + 1;
  int get _interestPageIndex => _introCount;

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

  Color get _accentForPage =>
      _page < _introCount ? _pages[_page].color : const Color(0xFFD97706);

  String get _actionLabel {
    if (_page < _totalPages - 1) return 'التالي';
    if (_selectedCategories.isEmpty) return 'تخطّي والبدء';
    return 'ابدأ الآن (${_selectedCategories.length})';
  }

  void _next() {
    if (_page < _totalPages - 1) {
      _ctl.nextPage(duration: const Duration(milliseconds: 350), curve: Curves.easeOutCubic);
    } else {
      _finish();
    }
  }

  void _finish() async {
    await OnboardingScreen.markSeen();

    // Persist locally so the feed can personalize even before login.
    if (_selectedCategories.isNotEmpty) {
      await InterestsStore.save(_selectedCategories);
      // If the user is already signed in, replay picks as server follows.
      if (AuthStorage.isAuthenticated) {
        final notifier = ref.read(followedIdsProvider.notifier);
        for (final id in _selectedCategories) {
          if (!notifier.isFollowing('category', id)) {
            try {
              await notifier.toggle('category', id);
            } catch (_) {}
          }
        }
      }
    }

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
            itemCount: _totalPages,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (_, i) => i < _introCount
                ? _OnboardingPage(data: _pages[i])
                : _InterestPicker(
                    selected: _selectedCategories,
                    onToggle: (id) => setState(() {
                      if (_selectedCategories.contains(id)) {
                        _selectedCategories.remove(id);
                      } else {
                        _selectedCategories.add(id);
                      }
                    }),
                  ),
          ),

          // Skip button
          Positioned(
            top: MediaQuery.of(context).padding.top + 12,
            left: 16,
            child: _page < _totalPages - 1
                ? TextButton(
                    onPressed: _finish,
                    child: Text('تخطي', style: TextStyle(
                      color: _accentForPage.withOpacity(0.6),
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
                  children: List.generate(_totalPages, (i) {
                    final isActive = i == _page;
                    return AnimatedContainer(
                      duration: const Duration(milliseconds: 250),
                      margin: const EdgeInsets.symmetric(horizontal: 4),
                      width: isActive ? 28 : 8,
                      height: 8,
                      decoration: BoxDecoration(
                        color: isActive ? _accentForPage : _accentForPage.withOpacity(0.2),
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
                        backgroundColor: _accentForPage,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(16),
                        ),
                        elevation: 4,
                        shadowColor: _accentForPage.withOpacity(0.4),
                      ),
                      child: Text(
                        _actionLabel,
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

/// Final onboarding step: pick the categories you care about. Selections
/// are held in the parent state and persisted on finish. Encourages (but
/// doesn't force) at least 3 so the feed has something to personalize.
class _InterestPicker extends ConsumerWidget {
  const _InterestPicker({required this.selected, required this.onToggle});
  final Set<int> selected;
  final ValueChanged<int> onToggle;

  static const _accent = Color(0xFFD97706);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final categories = ref.watch(categoriesProvider);

    return Container(
      padding: EdgeInsets.fromLTRB(24, MediaQuery.of(context).padding.top + 64, 24, 170),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            width: 84,
            height: 84,
            decoration: BoxDecoration(
              color: _accent.withOpacity(0.10),
              shape: BoxShape.circle,
            ),
            alignment: Alignment.center,
            child: const Text('🎯', style: TextStyle(fontSize: 38)),
          ),
          const SizedBox(height: 20),
          const Text(
            'ما الذي يهمّك؟',
            style: TextStyle(fontSize: 24, fontWeight: FontWeight.w900, color: _accent),
          ),
          const SizedBox(height: 8),
          Text(
            'اختر المواضيع التي تريد متابعتها لنخصّص لك فيدك.\nيمكنك تغييرها لاحقاً في أي وقت.',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 14,
              height: 1.7,
              color: Theme.of(context).textTheme.bodyMedium?.color?.withOpacity(0.65),
            ),
          ),
          const SizedBox(height: 24),
          Expanded(
            child: categories.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (_, __) => Center(
                child: Text(
                  'تعذّر تحميل المواضيع — يمكنك المتابعة وتخصيصها لاحقاً.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: isDark ? Colors.white54 : Colors.black54),
                ),
              ),
              data: (cats) => SingleChildScrollView(
                child: Wrap(
                  spacing: 10,
                  runSpacing: 10,
                  alignment: WrapAlignment.center,
                  children: [
                    for (final cat in cats)
                      _CategoryChip(
                        category: cat,
                        isSelected: selected.contains(cat.id),
                        accent: _accent,
                        isDark: isDark,
                        onTap: () => onToggle(cat.id),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CategoryChip extends StatelessWidget {
  const _CategoryChip({
    required this.category,
    required this.isSelected,
    required this.accent,
    required this.isDark,
    required this.onTap,
  });
  final Category category;
  final bool isSelected;
  final Color accent;
  final bool isDark;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(
          color: isSelected
              ? accent
              : (isDark ? Colors.white.withOpacity(0.06) : Colors.grey.shade100),
          borderRadius: BorderRadius.circular(28),
          border: Border.all(
            color: isSelected ? accent : (isDark ? Colors.white24 : Colors.grey.shade300),
            width: 1.5,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (category.icon != null && category.icon!.isNotEmpty) ...[
              Text(category.icon!, style: const TextStyle(fontSize: 16)),
              const SizedBox(width: 6),
            ],
            Text(
              category.name,
              style: TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: isSelected
                    ? Colors.white
                    : (isDark ? Colors.white70 : AppColors.textLight),
              ),
            ),
            if (isSelected) ...[
              const SizedBox(width: 6),
              const Icon(Icons.check_circle, size: 16, color: Colors.white),
            ],
          ],
        ),
      ),
    );
  }
}
