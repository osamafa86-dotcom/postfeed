import 'package:flutter/material.dart';

// ═══════════════════════════════════════════════════════════════
// فيد نيوز — Palestinian Identity + Neomorphism Design System
// ═══════════════════════════════════════════════════════════════

/// 🫒 زيتوني ترابي — Olive & Earth palette
/// Inspired by Palestinian olive groves and earth.
class AppColors {
  // ── Palestinian Identity ──
  static const Color palestineGreen  = Color(0xFF1B7A3D);
  static const Color palestineRed    = Color(0xFFCE1126);
  static const Color palestineBlack  = Color(0xFF2C2416);
  static const Color palestineWhite  = Color(0xFFF2EEE8);

  // ── Primary (Olive Green scale) ──
  static const Color primary         = Color(0xFF5B7F3B);
  static const Color primaryLight    = Color(0xFF7BA05A);
  static const Color primaryDark     = Color(0xFF3D5A28);
  static const Color primarySurface  = Color(0xFFE8EFE0);

  // ── Accent & Status ──
  static const Color accent          = Color(0xFF9C7B5B);
  static const Color breaking        = Color(0xFFCE1126);
  static const Color success         = Color(0xFF5B7F3B);
  static const Color warning         = Color(0xFFB8860B);
  static const Color error           = Color(0xFFCE1126);
  static const Color info            = Color(0xFF5A7FA0);

  // ── Neomorphism Light Surfaces (warm) ──
  static const Color neoSurface      = Color(0xFFF2EEE8);
  static const Color neoSurfaceMid   = Color(0xFFE8E3DB);
  static const Color neoShadowDark   = Color(0xFFC5BFAB);
  static const Color neoShadowLight  = Color(0xFFFFFFFF);
  static const Color neoCardBg       = Color(0xFFF7F3ED);

  // ── Neomorphism Dark Surfaces (olive-tinted) ──
  static const Color neoDarkSurface  = Color(0xFF1E1F18);
  static const Color neoDarkMid      = Color(0xFF282A20);
  static const Color neoDarkShadow   = Color(0xFF141510);
  static const Color neoDarkHighlight = Color(0xFF323428);

  // ── Legacy aliases (for backward compat) ──
  static const Color surfaceLight    = neoSurface;
  static const Color surfaceDark     = neoDarkSurface;
  static const Color cardLight       = neoCardBg;
  static const Color cardDark        = neoDarkMid;
  static const Color textLight       = Color(0xFF2C2416);
  static const Color textDark        = Color(0xFFE5E2D9);
  static const Color textMutedLight  = Color(0xFF7A6E5D);
  static const Color textMutedDark   = Color(0xFFA09882);
  static const Color borderLight     = Color(0xFFDDD5C7);
  static const Color borderDark      = Color(0xFF3A3828);

  // ── Category colors ──
  static const Map<String, Color> categoryColors = {
    'cat-breaking':  palestineRed,
    'cat-political': Color(0xFF5B7F3B),
    'cat-economic':  Color(0xFF5A7FA0),
    'cat-sports':    Color(0xFFCE1126),
    'cat-arts':      Color(0xFF8B6E9B),
    'cat-media':     Color(0xFFB8860B),
    'cat-reports':   Color(0xFF5A8F8F),
  };
}

// ═══════════════════════════════════════════════════════════════
// Neomorphism Design Helpers
// ═══════════════════════════════════════════════════════════════

class NeoDecoration {
  /// Raised (embossed) neomorphism box — appears to float above the surface.
  static BoxDecoration raised({
    bool isDark = false,
    double radius = 18,
    Color? color,
    double intensity = 1.0,
  }) {
    final bg = color ?? (isDark ? AppColors.neoDarkMid : AppColors.neoSurface);
    return BoxDecoration(
      color: bg,
      borderRadius: BorderRadius.circular(radius),
      boxShadow: isDark
          ? [
              BoxShadow(
                color: AppColors.neoDarkShadow.withOpacity(0.6 * intensity),
                offset: Offset(5 * intensity, 5 * intensity),
                blurRadius: 14 * intensity,
              ),
              BoxShadow(
                color: AppColors.neoDarkHighlight.withOpacity(0.25 * intensity),
                offset: Offset(-5 * intensity, -5 * intensity),
                blurRadius: 14 * intensity,
              ),
            ]
          : [
              BoxShadow(
                color: AppColors.neoShadowDark.withOpacity(0.4 * intensity),
                offset: Offset(5 * intensity, 5 * intensity),
                blurRadius: 14 * intensity,
              ),
              BoxShadow(
                color: AppColors.neoShadowLight.withOpacity(0.8 * intensity),
                offset: Offset(-5 * intensity, -5 * intensity),
                blurRadius: 14 * intensity,
              ),
            ],
    );
  }

  /// Pressed (inset) neomorphism — appears sunken into the surface.
  static BoxDecoration inset({
    bool isDark = false,
    double radius = 14,
    Color? color,
  }) {
    final bg = color ?? (isDark ? AppColors.neoDarkMid : AppColors.neoSurface);
    return BoxDecoration(
      color: bg,
      borderRadius: BorderRadius.circular(radius),
      boxShadow: [], // outer shadows removed for inset
    );
    // Note: Flutter doesn't support inner shadow natively.
    // Use a Container with gradient overlay for true inset effect.
  }

  /// Soft raised with a single subtle shadow — for smaller elements like chips.
  static BoxDecoration soft({
    bool isDark = false,
    double radius = 14,
    Color? color,
  }) {
    final bg = color ?? (isDark ? AppColors.neoDarkMid : AppColors.neoSurface);
    return BoxDecoration(
      color: bg,
      borderRadius: BorderRadius.circular(radius),
      boxShadow: isDark
          ? [
              BoxShadow(
                color: AppColors.neoDarkShadow.withOpacity(0.4),
                offset: const Offset(3, 3),
                blurRadius: 8,
              ),
              BoxShadow(
                color: AppColors.neoDarkHighlight.withOpacity(0.15),
                offset: const Offset(-3, -3),
                blurRadius: 8,
              ),
            ]
          : [
              BoxShadow(
                color: AppColors.neoShadowDark.withOpacity(0.3),
                offset: const Offset(3, 3),
                blurRadius: 8,
              ),
              BoxShadow(
                color: AppColors.neoShadowLight.withOpacity(0.6),
                offset: const Offset(-3, -3),
                blurRadius: 8,
              ),
            ],
    );
  }

  /// Primary button decoration with brand glow.
  static BoxDecoration primaryButton({double radius = 14}) {
    return BoxDecoration(
      color: AppColors.primary,
      borderRadius: BorderRadius.circular(radius),
      boxShadow: [
        BoxShadow(
          color: AppColors.primary.withOpacity(0.35),
          offset: const Offset(0, 4),
          blurRadius: 14,
        ),
        BoxShadow(
          color: AppColors.primaryDark.withOpacity(0.2),
          offset: const Offset(0, 8),
          blurRadius: 24,
        ),
      ],
    );
  }

  /// Flag-inspired gradient (green → red → black).
  static LinearGradient palestineGradient({
    AlignmentGeometry begin = Alignment.centerRight,
    AlignmentGeometry end = Alignment.centerLeft,
  }) {
    return LinearGradient(
      begin: begin,
      end: end,
      colors: const [
        AppColors.palestineGreen,
        AppColors.palestineRed,
        AppColors.palestineBlack,
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// Neomorphism Card Widget
// ═══════════════════════════════════════════════════════════════

class NeoCard extends StatelessWidget {
  const NeoCard({
    super.key,
    required this.child,
    this.radius = 18,
    this.padding,
    this.margin,
    this.intensity = 1.0,
    this.color,
    this.onTap,
  });

  final Widget child;
  final double radius;
  final EdgeInsetsGeometry? padding;
  final EdgeInsetsGeometry? margin;
  final double intensity;
  final Color? color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: margin,
        padding: padding,
        decoration: NeoDecoration.raised(
          isDark: isDark,
          radius: radius,
          color: color,
          intensity: intensity,
        ),
        child: child,
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════
// App Theme
// ═══════════════════════════════════════════════════════════════

class AppTheme {
  static const String _fontFamily = 'IBMPlexSansArabic';

  static ThemeData light() {
    final base = ThemeData.light(useMaterial3: true);
    return base.copyWith(
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.primary,
        brightness: Brightness.light,
        surface: AppColors.neoSurface,
        primary: AppColors.primary,
      ),
      scaffoldBackgroundColor: AppColors.neoSurface,
      cardColor: AppColors.neoCardBg,
      textTheme: _textTheme(base.textTheme, AppColors.textLight, AppColors.textMutedLight),
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.neoSurface,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: true,
        titleTextStyle: TextStyle(
          fontFamily: _fontFamily,
          fontSize: 18,
          fontWeight: FontWeight.w800,
          color: AppColors.primary,
        ),
        iconTheme: const IconThemeData(color: AppColors.textLight),
      ),
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        type: BottomNavigationBarType.fixed,
        selectedItemColor: AppColors.primary,
        unselectedItemColor: AppColors.textMutedLight,
        showUnselectedLabels: true,
        backgroundColor: AppColors.neoSurface,
        elevation: 0,
        selectedLabelStyle: const TextStyle(fontFamily: _fontFamily, fontWeight: FontWeight.w700, fontSize: 10),
        unselectedLabelStyle: const TextStyle(fontFamily: _fontFamily, fontWeight: FontWeight.w500, fontSize: 10),
      ),
      cardTheme: CardThemeData(
        color: AppColors.neoCardBg,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: const BorderRadius.all(Radius.circular(18)),
          side: BorderSide.none,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: Colors.white,
          elevation: 0,
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 24),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontFamily: _fontFamily, fontWeight: FontWeight.w700, fontSize: 15),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: AppColors.neoSurface,
        selectedColor: AppColors.primarySurface,
        labelStyle: const TextStyle(fontFamily: _fontFamily, fontWeight: FontWeight.w600, fontSize: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        side: BorderSide.none,
        elevation: 0,
      ),
      dividerTheme: const DividerThemeData(color: AppColors.borderLight, space: 1, thickness: 1),
      snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
    );
  }

  static ThemeData dark() {
    final base = ThemeData.dark(useMaterial3: true);
    return base.copyWith(
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.primary,
        brightness: Brightness.dark,
        surface: AppColors.neoDarkSurface,
        primary: AppColors.primaryLight,
      ),
      scaffoldBackgroundColor: AppColors.neoDarkSurface,
      cardColor: AppColors.neoDarkMid,
      textTheme: _textTheme(base.textTheme, AppColors.textDark, AppColors.textMutedDark),
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.neoDarkSurface,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: true,
        titleTextStyle: TextStyle(
          fontFamily: _fontFamily,
          fontSize: 18,
          fontWeight: FontWeight.w800,
          color: AppColors.primaryLight,
        ),
        iconTheme: const IconThemeData(color: AppColors.textDark),
      ),
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        type: BottomNavigationBarType.fixed,
        selectedItemColor: AppColors.primaryLight,
        unselectedItemColor: AppColors.textMutedDark,
        showUnselectedLabels: true,
        backgroundColor: AppColors.neoDarkSurface,
        elevation: 0,
        selectedLabelStyle: const TextStyle(fontFamily: _fontFamily, fontWeight: FontWeight.w700, fontSize: 10),
        unselectedLabelStyle: const TextStyle(fontFamily: _fontFamily, fontWeight: FontWeight.w500, fontSize: 10),
      ),
      cardTheme: CardThemeData(
        color: AppColors.neoDarkMid,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: const BorderRadius.all(Radius.circular(18)),
          side: BorderSide.none,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primaryLight,
          foregroundColor: Colors.white,
          elevation: 0,
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 24),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontFamily: _fontFamily, fontWeight: FontWeight.w700, fontSize: 15),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: AppColors.neoDarkMid,
        selectedColor: AppColors.primaryDark,
        labelStyle: const TextStyle(fontFamily: _fontFamily, fontWeight: FontWeight.w600, fontSize: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        side: BorderSide.none,
        elevation: 0,
      ),
      dividerTheme: const DividerThemeData(color: AppColors.borderDark, space: 1, thickness: 1),
    );
  }

  static TextTheme _textTheme(TextTheme base, Color text, Color muted) {
    return base.copyWith(
      displayLarge:  base.displayLarge?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w800),
      displayMedium: base.displayMedium?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w800),
      headlineLarge: base.headlineLarge?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w800, fontSize: 26),
      headlineMedium: base.headlineMedium?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w800, fontSize: 22),
      headlineSmall: base.headlineSmall?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w700, fontSize: 18),
      titleLarge:    base.titleLarge?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w700),
      titleMedium:   base.titleMedium?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w600),
      titleSmall:    base.titleSmall?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w600),
      bodyLarge:     base.bodyLarge?.copyWith(fontFamily: _fontFamily, color: text, height: 1.6),
      bodyMedium:    base.bodyMedium?.copyWith(fontFamily: _fontFamily, color: text, height: 1.6),
      bodySmall:     base.bodySmall?.copyWith(fontFamily: _fontFamily, color: muted, height: 1.5),
      labelLarge:    base.labelLarge?.copyWith(fontFamily: _fontFamily, color: text, fontWeight: FontWeight.w600),
      labelMedium:   base.labelMedium?.copyWith(fontFamily: _fontFamily, color: muted),
      labelSmall:    base.labelSmall?.copyWith(fontFamily: _fontFamily, color: muted),
    );
  }
}
