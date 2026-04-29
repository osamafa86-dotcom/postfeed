import 'package:flutter/material.dart';

/// Brand colors mirror the website (نيوز فيد / فيد نيوز).
class AppColors {
  static const Color primary       = Color(0xFF0D6EFD);
  static const Color primaryDark   = Color(0xFF0A58CA);
  static const Color accent        = Color(0xFFD97706);
  static const Color breaking      = Color(0xFFDC2626);
  static const Color surfaceLight  = Color(0xFFF8FAFC);
  static const Color surfaceDark   = Color(0xFF0B1220);
  static const Color cardLight     = Colors.white;
  static const Color cardDark      = Color(0xFF111827);
  static const Color textLight     = Color(0xFF0F172A);
  static const Color textDark      = Color(0xFFE5E7EB);
  static const Color textMutedLight = Color(0xFF64748B);
  static const Color textMutedDark  = Color(0xFF94A3B8);
  static const Color borderLight   = Color(0xFFE2E8F0);
  static const Color borderDark    = Color(0xFF1F2937);

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
        backgroundColor: AppColors.cardLight,
        elevation: 0,
        scrolledUnderElevation: 0.5,
        centerTitle: true,
        titleTextStyle: TextStyle(
          fontFamily: _fontFamily,
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: AppColors.textLight,
        ),
        iconTheme: const IconThemeData(color: AppColors.textLight),
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
