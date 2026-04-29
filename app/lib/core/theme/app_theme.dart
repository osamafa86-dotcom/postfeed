import 'package:flutter/material.dart';

/// Brand colors mirror the website (نيوز فيد / فيد نيوز).
/// CSS variable values copied from `assets/css/home.min.css :root`.
class AppColors {
  // Brand accents
  static const Color accent        = Color(0xFF1A73E8); // --accent (blue)
  static const Color accent2       = Color(0xFF0D9488); // --accent2 (teal)
  static const Color accent3       = Color(0xFF16A34A); // --accent3 (green)
  static const Color gold          = Color(0xFFD97706); // --gold
  static const Color goldBright    = Color(0xFFF59E0B); // weekly rewind CTA
  static const Color breaking      = Color(0xFFDC2626); // --red
  static const Color purple        = Color(0xFF7C3AED); // stat-chip-purple

  // Backwards-compat aliases — keep `primary` so existing widgets compile.
  static const Color primary       = accent;
  static const Color primaryDark   = Color(0xFF1453A8);

  // Surfaces (light = warm cream like the website)
  static const Color surfaceLight  = Color(0xFFFAF6EC); // --bg
  static const Color surfaceLight2 = Color(0xFFFDFAF2); // --bg2
  static const Color surfaceDark   = Color(0xFF0B1220);
  static const Color cardLight     = Colors.white;
  static const Color cardDark      = Color(0xFF111827);

  // Site header (dark navy bar, used as appBar background)
  static const Color headerLight   = Color(0xFF1A1A2E); // --header-bg
  static const Color headerDark    = Color(0xFF0B1020);
  static const Color headerText    = Color(0xFFE5E7EB); // --header-text

  // Text
  static const Color textLight     = Color(0xFF1A1A2E); // --text
  static const Color textLight2    = Color(0xFF374151); // --text2
  static const Color textDark      = Color(0xFFE5E7EB);
  static const Color textMutedLight = Color(0xFF6B7280); // --muted
  static const Color textMutedLight2 = Color(0xFF9CA3AF); // --muted2
  static const Color textMutedDark  = Color(0xFF94A3B8);

  // Borders
  static const Color borderLight   = Color(0xFFE0E3E8); // --border
  static const Color borderDark    = Color(0xFF1F2937);

  // Stat chip backgrounds (very light tints).
  static const Color chipBlueBg    = Color(0xFFE8F0FE);
  static const Color chipTealBg    = Color(0xFFE6F7F4);
  static const Color chipPurpleBg  = Color(0xFFF1E9FE);
  static const Color chipOrangeBg  = Color(0xFFFEF3E2);
  static const Color chipRedBg     = Color(0xFFFDE7E7);

  // Category colors mirror the website's css_class palette.
  static const Map<String, Color> categoryColors = {
    'cat-breaking':  Color(0xFFDC2626),
    'cat-political': Color(0xFF1D4ED8),
    'cat-economic':  Color(0xFF059669),
    'cat-sports':    Color(0xFF0891B2),
    'cat-arts':      Color(0xFFA21CAF),
    'cat-media':     Color(0xFFB91C1C),
    'cat-reports':   Color(0xFF7C3AED),
  };
}

class AppTheme {
  static const String _fontFamily = 'IBMPlexSansArabic';

  static ThemeData light() {
    final base = ThemeData.light(useMaterial3: true);
    return base.copyWith(
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.primary,
        brightness: Brightness.light,
        surface: AppColors.surfaceLight,
      ),
      scaffoldBackgroundColor: AppColors.surfaceLight,
      cardColor: AppColors.cardLight,
      textTheme: _textTheme(base.textTheme, AppColors.textLight, AppColors.textMutedLight),
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.headerLight,
        foregroundColor: AppColors.headerText,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          fontFamily: _fontFamily,
          fontSize: 18,
          fontWeight: FontWeight.w800,
          color: AppColors.headerText,
        ),
        iconTheme: const IconThemeData(color: AppColors.headerText),
        actionsIconTheme: const IconThemeData(color: AppColors.headerText),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        type: BottomNavigationBarType.fixed,
        selectedItemColor: AppColors.primary,
        unselectedItemColor: AppColors.textMutedLight,
        showUnselectedLabels: true,
        backgroundColor: AppColors.cardLight,
        elevation: 8,
      ),
      cardTheme: const CardThemeData(
        color: AppColors.cardLight,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(14)),
          side: BorderSide(color: AppColors.borderLight, width: 1),
        ),
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
        surface: AppColors.surfaceDark,
      ),
      scaffoldBackgroundColor: AppColors.surfaceDark,
      cardColor: AppColors.cardDark,
      textTheme: _textTheme(base.textTheme, AppColors.textDark, AppColors.textMutedDark),
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.cardDark,
        elevation: 0,
        scrolledUnderElevation: 0.5,
        centerTitle: true,
        titleTextStyle: TextStyle(
          fontFamily: _fontFamily,
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: AppColors.textDark,
        ),
        iconTheme: const IconThemeData(color: AppColors.textDark),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        type: BottomNavigationBarType.fixed,
        selectedItemColor: AppColors.primary,
        unselectedItemColor: AppColors.textMutedDark,
        showUnselectedLabels: true,
        backgroundColor: AppColors.cardDark,
        elevation: 8,
      ),
      cardTheme: const CardThemeData(
        color: AppColors.cardDark,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(14)),
          side: BorderSide(color: AppColors.borderDark, width: 1),
        ),
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
