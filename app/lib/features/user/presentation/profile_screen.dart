import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/models/user.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/loading_state.dart';
import '../../auth/data/auth_repository.dart';
import '../../auth/data/auth_storage.dart';
import '../data/user_repository.dart';
import 'edit_profile_screen.dart';
import 'reorder_categories_screen.dart';
import 'notification_settings_screen.dart';

// ═══════════════════════════════════════════════════════════════
// PROFILE SCREEN — Social Media Style Dashboard
// ═══════════════════════════════════════════════════════════════

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    return Scaffold(
      body: user.when(
        loading: () => const LoadingShimmerList(),
        error: (e, _) => ErrorRetryView(
          message: '$e',
          onRetry: () => ref.invalidate(currentUserProvider),
        ),
        data: (u) => u == null ? const _GuestView() : _AuthedProfile(user: u),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// GUEST VIEW — Not logged in
// ═══════════════════════════════════════════════════════════════

class _GuestView extends StatelessWidget {
  const _GuestView();

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return SafeArea(
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Avatar placeholder
              Container(
                width: 100, height: 100,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [AppColors.primary, AppColors.accent],
                    begin: Alignment.topRight,
                    end: Alignment.bottomLeft,
                  ),
                  shape: BoxShape.circle,
                  boxShadow: [BoxShadow(
                    color: AppColors.primary.withOpacity(0.3),
                    blurRadius: 20, offset: const Offset(0, 8))],
                ),
                child: const Icon(Icons.person_outline, size: 48, color: Colors.white),
              ),
              const SizedBox(height: 24),
              Text('أهلاً بك في فيد نيوز',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900,
                  color: isDark ? AppColors.textDark : AppColors.textLight)),
              const SizedBox(height: 10),
              Text('سجّل دخولك لتخصيص تجربتك\nاحفظ المقالات، تابع المصادر، واحصل على إشعارات ذكية',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 14, height: 1.7,
                  color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
              const SizedBox(height: 28),
              SizedBox(
                width: double.infinity,
                height: 52,
                child: ElevatedButton.icon(
                  onPressed: () => context.push('/login'),
                  icon: const Icon(Icons.login),
                  label: const Text('تسجيل الدخول', style: TextStyle(fontSize: 16)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.primary,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              TextButton(
                onPressed: () => context.push('/register'),
                child: const Text('إنشاء حساب جديد',
                  style: TextStyle(fontWeight: FontWeight.w700)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// AUTHENTICATED PROFILE — Full Social-Media Style
// ═══════════════════════════════════════════════════════════════

class _AuthedProfile extends ConsumerStatefulWidget {
  const _AuthedProfile({required this.user});
  final AppUser user;

  @override
  ConsumerState<_AuthedProfile> createState() => _AuthedProfileState();
}

class _AuthedProfileState extends ConsumerState<_AuthedProfile>
    with SingleTickerProviderStateMixin {
  late final TabController _tabCtrl;

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: 4, vsync: this);
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final u = widget.user;
    final follows = ref.watch(followedIdsProvider);
    final bookmarkCount = ref.watch(bookmarkedIdsProvider).length;

    final followingCount = follows.values.fold<int>(0, (a, b) => a + b.length);

    return NestedScrollView(
      headerSliverBuilder: (context, _) => [
        // ── Hero Header ──
        SliverToBoxAdapter(
          child: _ProfileHeader(
            user: u,
            isDark: isDark,
            onEdit: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => EditProfileScreen(user: u)),
            ),
            onSettings: () => context.push('/settings'),
          ),
        ),

        // ── Stats Row ──
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: Container(
              padding: const EdgeInsets.symmetric(vertical: 16),
              decoration: NeoDecoration.raised(isDark: isDark, radius: 20, intensity: 0.6),
              child: Row(
                children: [
                  _StatItem(value: '${u.readingStreak}', label: 'يوم متواصل', emoji: '🔥'),
                  _divider(isDark),
                  _StatItem(value: '$bookmarkCount', label: 'محفوظ', emoji: '🔖'),
                  _divider(isDark),
                  _StatItem(value: '$followingCount', label: 'متابَع', emoji: '👁'),
                ],
              ),
            ),
          ),
        ),

        // ── Achievements ──
        SliverToBoxAdapter(
          child: _AchievementsSection(user: u, isDark: isDark,
            bookmarkCount: bookmarkCount, followingCount: followingCount),
        ),

        // ── Tab Bar ──
        SliverPersistentHeader(
          pinned: true,
          delegate: _TabBarDelegate(
            tabBar: TabBar(
              controller: _tabCtrl,
              isScrollable: false,
              labelColor: AppColors.primary,
              unselectedLabelColor: isDark ? AppColors.textMutedDark : AppColors.textMutedLight,
              indicatorColor: AppColors.primary,
              indicatorWeight: 3,
              labelStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12),
              unselectedLabelStyle: const TextStyle(fontWeight: FontWeight.w500, fontSize: 12),
              tabs: const [
                Tab(text: '📂 اهتماماتي'),
                Tab(text: '🔖 المحفوظات'),
                Tab(text: '💬 نشاطي'),
                Tab(text: '⚙️ أدواتي'),
              ],
            ),
            isDark: isDark,
          ),
        ),
      ],
      body: TabBarView(
        controller: _tabCtrl,
        children: [
          _InterestsTab(follows: follows, isDark: isDark),
          _BookmarksTab(isDark: isDark),
          _ActivityTab(user: u, isDark: isDark),
          _ToolsTab(user: u, isDark: isDark, ref: ref),
        ],
      ),
    );
  }

  Widget _divider(bool isDark) => Container(
    width: 1, height: 32,
    color: isDark ? Colors.white.withOpacity(0.08) : AppColors.borderLight,
  );
}

// ═══════════════════════════════════════════════════════════════
// PROFILE HEADER — Cover + Avatar + Name + Bio
// ═══════════════════════════════════════════════════════════════

class _ProfileHeader extends StatelessWidget {
  const _ProfileHeader({
    required this.user, required this.isDark,
    required this.onEdit, required this.onSettings,
  });
  final AppUser user;
  final bool isDark;
  final VoidCallback onEdit;
  final VoidCallback onSettings;

  @override
  Widget build(BuildContext context) {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        // Cover gradient
        Container(
          height: 180,
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topRight,
              end: Alignment.bottomLeft,
              colors: [
                AppColors.primaryDark,
                const Color(0xFF2C2416),
                AppColors.primary.withOpacity(0.8),
              ],
            ),
          ),
          child: Stack(
            children: [
              // Decorative circles
              Positioned(top: -30, left: -30,
                child: Container(width: 120, height: 120,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Colors.white.withOpacity(0.05)))),
              Positioned(bottom: -20, right: -40,
                child: Container(width: 160, height: 160,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: AppColors.primary.withOpacity(0.15)))),
              // Top actions
              Positioned(
                top: MediaQuery.of(context).padding.top + 8,
                right: 12,
                child: CircleAvatar(
                  backgroundColor: Colors.white.withOpacity(0.15),
                  child: IconButton(
                    icon: const Icon(Icons.settings_outlined, color: Colors.white, size: 20),
                    onPressed: onSettings,
                  ),
                ),
              ),
              Positioned(
                top: MediaQuery.of(context).padding.top + 8,
                left: 12,
                child: CircleAvatar(
                  backgroundColor: Colors.white.withOpacity(0.15),
                  child: IconButton(
                    icon: const Icon(Icons.edit_outlined, color: Colors.white, size: 20),
                    onPressed: onEdit,
                  ),
                ),
              ),
            ],
          ),
        ),

        // Avatar + info card overlapping cover
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 120, 16, 0),
          child: Column(
            children: [
              // Avatar
              Container(
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: isDark ? AppColors.neoDarkSurface : AppColors.neoSurface,
                  boxShadow: [BoxShadow(
                    color: Colors.black.withOpacity(0.2),
                    blurRadius: 16, offset: const Offset(0, 4))],
                ),
                child: CircleAvatar(
                  radius: 44,
                  backgroundColor: AppColors.primary,
                  child: Text(
                    user.avatarLetter ?? user.name.isNotEmpty ? user.name[0] : 'م',
                    style: const TextStyle(
                      color: Colors.white, fontSize: 38, fontWeight: FontWeight.w900),
                  ),
                ),
              ),
              const SizedBox(height: 12),

              // Name
              Text(user.name,
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900,
                  color: isDark ? AppColors.textDark : AppColors.textLight)),

              // Username
              if (user.username != null && user.username!.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text('@${user.username}',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w500,
                    color: AppColors.primary)),
              ],

              // Bio
              if (user.bio.isNotEmpty) ...[
                const SizedBox(height: 8),
                Text(user.bio,
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 14, height: 1.6,
                    color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
              ],

              // Plan badge
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
                decoration: BoxDecoration(
                  gradient: user.plan == 'pro'
                      ? LinearGradient(colors: [Colors.amber.shade600, Colors.orange.shade700])
                      : null,
                  color: user.plan == 'pro' ? null
                      : (isDark ? AppColors.neoDarkMid : AppColors.neoSurfaceMid),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  user.plan == 'pro' ? '⭐ عضوية مميزة' : '📖 قارئ',
                  style: TextStyle(
                    fontSize: 12, fontWeight: FontWeight.w700,
                    color: user.plan == 'pro' ? Colors.white
                        : (isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
                ),
              ),
              const SizedBox(height: 16),
            ],
          ),
        ),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// STAT ITEM
// ═══════════════════════════════════════════════════════════════

class _StatItem extends StatelessWidget {
  const _StatItem({required this.value, required this.label, required this.emoji});
  final String value;
  final String label;
  final String emoji;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Expanded(
      child: Column(
        children: [
          Text(emoji, style: const TextStyle(fontSize: 20)),
          const SizedBox(height: 4),
          Text(value, style: TextStyle(
            fontSize: 22, fontWeight: FontWeight.w900,
            color: isDark ? AppColors.textDark : AppColors.textLight)),
          const SizedBox(height: 2),
          Text(label, style: TextStyle(
            fontSize: 11, fontWeight: FontWeight.w500,
            color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// ACHIEVEMENTS SECTION — Badges & Milestones
// ═══════════════════════════════════════════════════════════════

class _AchievementsSection extends StatelessWidget {
  const _AchievementsSection({
    required this.user, required this.isDark,
    required this.bookmarkCount, required this.followingCount,
  });
  final AppUser user;
  final bool isDark;
  final int bookmarkCount;
  final int followingCount;

  @override
  Widget build(BuildContext context) {
    final badges = _computeBadges();

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Text('🏆', style: TextStyle(fontSize: 18)),
              const SizedBox(width: 8),
              Text('إنجازاتي',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800,
                  color: isDark ? AppColors.textDark : AppColors.textLight)),
              const Spacer(),
              Text('${badges.where((b) => b.unlocked).length}/${badges.length}',
                style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700,
                  color: AppColors.primary)),
            ],
          ),
          const SizedBox(height: 10),
          SizedBox(
            height: 90,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: badges.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (_, i) => _BadgeCard(badge: badges[i], isDark: isDark),
            ),
          ),
        ],
      ),
    );
  }

  List<_Badge> _computeBadges() => [
    _Badge('🔥', 'قارئ مثابر', 'اقرأ 3 أيام متواصلة', user.readingStreak >= 3),
    _Badge('⚡', 'سلسلة نارية', 'اقرأ 7 أيام متواصلة', user.readingStreak >= 7),
    _Badge('🔖', 'جامع المقالات', 'احفظ 10 مقالات', bookmarkCount >= 10),
    _Badge('👁', 'متابع وفي', 'تابع 5 مصادر أو أقسام', followingCount >= 5),
    _Badge('💬', 'صوت فعّال', 'اكتب أول تعليق', true), // placeholder
    _Badge('🌟', 'نجم الأسبوع', 'اقرأ 30 يوم متواصل', user.readingStreak >= 30),
    _Badge('🏅', 'خبير الأخبار', 'تابع 15 مصدر', followingCount >= 15),
    _Badge('🎯', 'قنّاص الترند', 'شارك 5 أخبار', false), // placeholder
  ];
}

class _Badge {
  const _Badge(this.emoji, this.title, this.requirement, this.unlocked);
  final String emoji;
  final String title;
  final String requirement;
  final bool unlocked;
}

class _BadgeCard extends StatelessWidget {
  const _BadgeCard({required this.badge, required this.isDark});
  final _Badge badge;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    final unlocked = badge.unlocked;
    return Container(
      width: 80,
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: unlocked
            ? (isDark ? AppColors.primaryDark.withOpacity(0.3) : AppColors.primarySurface)
            : (isDark ? AppColors.neoDarkMid : AppColors.neoSurfaceMid),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: unlocked ? AppColors.primary.withOpacity(0.4) : Colors.transparent,
          width: 1.5,
        ),
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(badge.emoji,
            style: TextStyle(fontSize: 26,
              color: unlocked ? null : Colors.grey.withOpacity(0.4))),
          const SizedBox(height: 4),
          Text(badge.title,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 9, fontWeight: FontWeight.w700,
              color: unlocked
                  ? (isDark ? AppColors.textDark : AppColors.textLight)
                  : (isDark ? Colors.white24 : Colors.black26)),
            maxLines: 2, overflow: TextOverflow.ellipsis),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// TAB BAR DELEGATE
// ═══════════════════════════════════════════════════════════════

class _TabBarDelegate extends SliverPersistentHeaderDelegate {
  const _TabBarDelegate({required this.tabBar, required this.isDark});
  final TabBar tabBar;
  final bool isDark;

  @override
  double get minExtent => 48;
  @override
  double get maxExtent => 48;

  @override
  Widget build(BuildContext ctx, double shrink, bool overlaps) {
    return Container(
      color: isDark ? AppColors.neoDarkSurface : AppColors.neoSurface,
      child: tabBar,
    );
  }

  @override
  bool shouldRebuild(covariant _TabBarDelegate old) => false;
}

// ═══════════════════════════════════════════════════════════════
// TAB 1 — INTERESTS (اهتماماتي)
// ═══════════════════════════════════════════════════════════════

class _InterestsTab extends StatelessWidget {
  const _InterestsTab({required this.follows, required this.isDark});
  final Map<String, Set<int>> follows;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    final categories = follows['category'] ?? {};
    final sources = follows['source'] ?? {};
    final stories = follows['story'] ?? {};
    final hasAny = categories.isNotEmpty || sources.isNotEmpty || stories.isNotEmpty;

    if (!hasAny) {
      return _EmptyTab(
        emoji: '🎯',
        title: 'لم تتابع شيئاً بعد',
        subtitle: 'تابع أقسام ومصادر لتخصيص تجربتك',
        actionLabel: 'استكشف الأقسام',
        onAction: () => context.go('/'),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Visual interest map
        _InterestCloud(
          categories: categories.length,
          sources: sources.length,
          stories: stories.length,
          isDark: isDark,
        ),
        const SizedBox(height: 16),

        if (categories.isNotEmpty)
          _InterestGroup(emoji: '📂', label: 'أقسام', count: categories.length, isDark: isDark),
        if (sources.isNotEmpty) ...[
          const SizedBox(height: 8),
          _InterestGroup(emoji: '📰', label: 'مصادر', count: sources.length, isDark: isDark),
        ],
        if (stories.isNotEmpty) ...[
          const SizedBox(height: 8),
          _InterestGroup(emoji: '📖', label: 'قصص', count: stories.length, isDark: isDark),
        ],
      ],
    );
  }
}

class _InterestCloud extends StatelessWidget {
  const _InterestCloud({
    required this.categories, required this.sources,
    required this.stories, required this.isDark,
  });
  final int categories;
  final int sources;
  final int stories;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    final total = categories + sources + stories;
    if (total == 0) return const SizedBox();

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: NeoDecoration.raised(isDark: isDark, radius: 18, intensity: 0.5),
      child: Column(
        children: [
          Text('خريطة اهتماماتك',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800,
              color: isDark ? AppColors.textDark : AppColors.textLight)),
          const SizedBox(height: 16),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: [
              _InterestBubble(
                emoji: '📂', label: 'أقسام', count: categories,
                color: AppColors.primary, total: total),
              _InterestBubble(
                emoji: '📰', label: 'مصادر', count: sources,
                color: AppColors.accent, total: total),
              _InterestBubble(
                emoji: '📖', label: 'قصص', count: stories,
                color: AppColors.info, total: total),
            ],
          ),
        ],
      ),
    );
  }
}

class _InterestBubble extends StatelessWidget {
  const _InterestBubble({
    required this.emoji, required this.label,
    required this.count, required this.color, required this.total,
  });
  final String emoji;
  final String label;
  final int count;
  final Color color;
  final int total;

  @override
  Widget build(BuildContext context) {
    final ratio = total > 0 ? count / total : 0.0;
    final size = 50.0 + (ratio * 40);
    return Column(
      children: [
        Container(
          width: size, height: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: color.withOpacity(0.15),
            border: Border.all(color: color.withOpacity(0.4), width: 2),
          ),
          alignment: Alignment.center,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(emoji, style: TextStyle(fontSize: size > 70 ? 22 : 18)),
              Text('$count', style: TextStyle(
                fontSize: 14, fontWeight: FontWeight.w900, color: color)),
            ],
          ),
        ),
        const SizedBox(height: 6),
        Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600,
          color: Theme.of(context).brightness == Brightness.dark
              ? AppColors.textMutedDark : AppColors.textMutedLight)),
      ],
    );
  }
}

class _InterestGroup extends StatelessWidget {
  const _InterestGroup({
    required this.emoji, required this.label,
    required this.count, required this.isDark,
  });
  final String emoji;
  final String label;
  final int count;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: NeoDecoration.soft(isDark: isDark, radius: 14),
      child: Row(
        children: [
          Text(emoji, style: const TextStyle(fontSize: 20)),
          const SizedBox(width: 10),
          Text('$count $label تتابعها',
            style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14,
              color: isDark ? AppColors.textDark : AppColors.textLight)),
          const Spacer(),
          Icon(Icons.chevron_left, size: 18,
            color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// TAB 2 — BOOKMARKS (المحفوظات)
// ═══════════════════════════════════════════════════════════════

class _BookmarksTab extends ConsumerWidget {
  const _BookmarksTab({required this.isDark});
  final bool isDark;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ids = ref.watch(bookmarkedIdsProvider);
    if (ids.isEmpty) {
      return _EmptyTab(
        emoji: '🔖',
        title: 'لا توجد محفوظات',
        subtitle: 'احفظ مقالات لقراءتها لاحقاً',
        actionLabel: 'تصفح الأخبار',
        onAction: () => context.go('/'),
      );
    }
    // Show bookmarks count + link to full page
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Container(
          padding: const EdgeInsets.all(20),
          decoration: NeoDecoration.raised(isDark: isDark, radius: 18, intensity: 0.5),
          child: Column(
            children: [
              const Text('🔖', style: TextStyle(fontSize: 48)),
              const SizedBox(height: 12),
              Text('${ids.length} مقال محفوظ',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900,
                  color: isDark ? AppColors.textDark : AppColors.textLight)),
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: () => context.push('/bookmarks'),
                  icon: const Icon(Icons.bookmark_rounded, size: 18),
                  label: const Text('عرض المحفوظات'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// TAB 3 — ACTIVITY (نشاطي)
// ═══════════════════════════════════════════════════════════════

class _ActivityTab extends StatelessWidget {
  const _ActivityTab({required this.user, required this.isDark});
  final AppUser user;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Reading streak card
        _ActivityCard(
          emoji: '🔥',
          title: 'سلسلة القراءة',
          value: '${user.readingStreak} يوم',
          subtitle: user.readingStreak >= 7
              ? 'ممتاز! حافظ على سلسلتك 💪'
              : user.readingStreak >= 3
                  ? 'أداء جيد! استمر 📈'
                  : 'ابدأ بقراءة يومية لبناء سلسلتك',
          color: const Color(0xFFFF6B35),
          isDark: isDark,
        ),
        const SizedBox(height: 10),

        // Streak visual (week dots)
        _WeekStreakVisual(streak: user.readingStreak, isDark: isDark),
        const SizedBox(height: 16),

        // Activity items
        _ActivityTile(
          emoji: '💬', label: 'تعليقاتي',
          onTap: () {},
          isDark: isDark,
        ),
        const SizedBox(height: 8),
        _ActivityTile(
          emoji: '❤️', label: 'تفاعلاتي',
          onTap: () {},
          isDark: isDark,
        ),
        const SizedBox(height: 8),
        _ActivityTile(
          emoji: '📤', label: 'مشاركاتي',
          onTap: () {},
          isDark: isDark,
        ),
        const SizedBox(height: 8),
        _ActivityTile(
          emoji: '📜', label: 'سجل القراءة',
          onTap: () {},
          isDark: isDark,
        ),
      ],
    );
  }
}

class _ActivityCard extends StatelessWidget {
  const _ActivityCard({
    required this.emoji, required this.title,
    required this.value, required this.subtitle,
    required this.color, required this.isDark,
  });
  final String emoji;
  final String title;
  final String value;
  final String subtitle;
  final Color color;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [color.withOpacity(0.12), color.withOpacity(0.04)],
        ),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: color.withOpacity(0.2)),
      ),
      child: Row(
        children: [
          Text(emoji, style: const TextStyle(fontSize: 36)),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600,
                  color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
                Text(value, style: TextStyle(fontSize: 26, fontWeight: FontWeight.w900,
                  color: color)),
                Text(subtitle, style: TextStyle(fontSize: 12,
                  color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _WeekStreakVisual extends StatelessWidget {
  const _WeekStreakVisual({required this.streak, required this.isDark});
  final int streak;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    final days = ['ح', 'ن', 'ث', 'ر', 'خ', 'ج', 'س'];
    final today = DateTime.now().weekday % 7; // 0=Sun
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
      decoration: NeoDecoration.soft(isDark: isDark, radius: 14),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceAround,
        children: List.generate(7, (i) {
          final isActive = i <= today && (today - i) < streak;
          return Column(
            children: [
              Container(
                width: 32, height: 32,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: isActive
                      ? AppColors.primary
                      : (isDark ? AppColors.neoDarkMid : AppColors.neoSurfaceMid),
                  border: i == today
                      ? Border.all(color: AppColors.primary, width: 2)
                      : null,
                ),
                alignment: Alignment.center,
                child: isActive
                    ? const Icon(Icons.check, size: 16, color: Colors.white)
                    : null,
              ),
              const SizedBox(height: 4),
              Text(days[i], style: TextStyle(fontSize: 10, fontWeight: FontWeight.w600,
                color: isActive ? AppColors.primary
                    : (isDark ? AppColors.textMutedDark : AppColors.textMutedLight))),
            ],
          );
        }),
      ),
    );
  }
}

class _ActivityTile extends StatelessWidget {
  const _ActivityTile({
    required this.emoji, required this.label,
    required this.onTap, required this.isDark,
  });
  final String emoji;
  final String label;
  final VoidCallback onTap;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        decoration: NeoDecoration.soft(isDark: isDark, radius: 14),
        child: Row(
          children: [
            Text(emoji, style: const TextStyle(fontSize: 20)),
            const SizedBox(width: 12),
            Text(label, style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600,
              color: isDark ? AppColors.textDark : AppColors.textLight)),
            const Spacer(),
            Icon(Icons.chevron_left, size: 18,
              color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight),
          ],
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// TAB 4 — TOOLS (أدواتي)
// ═══════════════════════════════════════════════════════════════

class _ToolsTab extends StatelessWidget {
  const _ToolsTab({required this.user, required this.isDark, required this.ref});
  final AppUser user;
  final bool isDark;
  final WidgetRef ref;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _ToolItem(emoji: '🔔', label: 'الإشعارات الذكية',
          subtitle: 'خصّص الأخبار التي تصلك',
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const NotificationSettingsScreen()))),
        const SizedBox(height: 8),
        _ToolItem(emoji: '📂', label: 'ترتيب الأقسام',
          subtitle: 'رتّب الأقسام حسب اهتمامك',
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const ReorderCategoriesScreen()))),
        const SizedBox(height: 8),
        _ToolItem(emoji: '🔖', label: 'المقالات المحفوظة',
          subtitle: 'مقالات حفظتها للقراءة لاحقاً',
          onTap: () => context.push('/bookmarks')),
        const SizedBox(height: 8),
        _ToolItem(emoji: '🔔', label: 'الإشعارات',
          subtitle: 'كل الإشعارات الواردة',
          onTap: () => context.push('/notifications')),
        const SizedBox(height: 8),
        _ToolItem(emoji: '⚙️', label: 'الإعدادات',
          subtitle: 'السمة، الخصوصية، معلومات التطبيق',
          onTap: () => context.push('/settings')),

        const SizedBox(height: 24),

        // Logout
        Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: Colors.red.withOpacity(0.3)),
          ),
          child: ListTile(
            leading: const Icon(Icons.logout, color: Colors.red),
            title: const Text('تسجيل الخروج',
              style: TextStyle(color: Colors.red, fontWeight: FontWeight.w700)),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            onTap: () async {
              await ref.read(authRepositoryProvider).logout();
              ref.invalidate(currentUserProvider);
            },
          ),
        ),
      ],
    );
  }
}

class _ToolItem extends StatelessWidget {
  const _ToolItem({
    required this.emoji, required this.label,
    required this.subtitle, required this.onTap,
  });
  final String emoji;
  final String label;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: NeoDecoration.soft(isDark: isDark, radius: 14),
        child: Row(
          children: [
            Container(
              width: 40, height: 40,
              decoration: BoxDecoration(
                color: AppColors.primary.withOpacity(0.1),
                borderRadius: BorderRadius.circular(12),
              ),
              alignment: Alignment.center,
              child: Text(emoji, style: const TextStyle(fontSize: 20)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700,
                    color: isDark ? AppColors.textDark : AppColors.textLight)),
                  Text(subtitle, style: TextStyle(fontSize: 12,
                    color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
                ],
              ),
            ),
            Icon(Icons.chevron_left, size: 18,
              color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight),
          ],
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// EMPTY TAB — Shared empty state
// ═══════════════════════════════════════════════════════════════

class _EmptyTab extends StatelessWidget {
  const _EmptyTab({
    required this.emoji, required this.title,
    required this.subtitle, this.actionLabel, this.onAction,
  });
  final String emoji;
  final String title;
  final String subtitle;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(emoji, style: const TextStyle(fontSize: 56)),
            const SizedBox(height: 16),
            Text(title, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800,
              color: isDark ? AppColors.textDark : AppColors.textLight)),
            const SizedBox(height: 8),
            Text(subtitle, textAlign: TextAlign.center,
              style: TextStyle(fontSize: 14,
                color: isDark ? AppColors.textMutedDark : AppColors.textMutedLight)),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: 20),
              ElevatedButton(onPressed: onAction, child: Text(actionLabel!)),
            ],
          ],
        ),
      ),
    );
  }
}
