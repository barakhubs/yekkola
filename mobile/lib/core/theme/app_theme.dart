import 'package:flutter/material.dart';

import 'brand_colors.dart';

/// Material theme from docs/brand.md: indigo primary, amber accent (a ThemeExtension), slate
/// neutrals, Nunito everywhere with the brand type scale.
abstract final class AppTheme {
  static const fontFamily = 'Nunito';

  static ThemeData light() => _build(
    const ColorScheme(
      brightness: Brightness.light,
      primary: Indigo.c800,
      onPrimary: Colors.white,
      primaryContainer: Indigo.c100,
      onPrimaryContainer: Indigo.c900,
      secondary: Slate.c600,
      onSecondary: Colors.white,
      secondaryContainer: Slate.c100,
      onSecondaryContainer: Slate.c900,
      tertiary: Amber.c300,
      onTertiary: Indigo.c800,
      error: Color(0xFFDC2626),
      onError: Colors.white,
      surface: Colors.white,
      onSurface: Slate.c900,
      onSurfaceVariant: Slate.c600,
      surfaceContainerLowest: Colors.white,
      surfaceContainerLow: Slate.c50,
      surfaceContainer: Slate.c100,
      surfaceContainerHigh: Slate.c100,
      surfaceContainerHighest: Slate.c200,
      outline: Slate.c300,
      outlineVariant: Slate.c200,
    ),
    BrandColors.light,
    focus: Indigo.c600,
  );

  static ThemeData dark() => _build(
    const ColorScheme(
      brightness: Brightness.dark,
      primary: Indigo.c300,
      onPrimary: Slate.c900,
      primaryContainer: Indigo.c950,
      onPrimaryContainer: Indigo.c100,
      secondary: Slate.c400,
      onSecondary: Slate.c900,
      secondaryContainer: Slate.c800,
      onSecondaryContainer: Slate.c100,
      tertiary: Amber.c300,
      onTertiary: Indigo.c950,
      error: Color(0xFFF87171),
      onError: Slate.c900,
      surface: Slate.c900,
      onSurface: Slate.c50,
      onSurfaceVariant: Slate.c400,
      surfaceContainerLowest: Slate.c950,
      surfaceContainerLow: Slate.c900,
      surfaceContainer: Slate.c800,
      surfaceContainerHigh: Slate.c800,
      surfaceContainerHighest: Slate.c700,
      outline: Slate.c600,
      outlineVariant: Slate.c700,
    ),
    BrandColors.dark,
    focus: Indigo.c300,
  );

  static ThemeData _build(ColorScheme scheme, BrandColors brand, {required Color focus}) {
    final text = _textTheme(scheme.onSurface);
    const radius = BorderRadius.all(Radius.circular(10));

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      fontFamily: fontFamily,
      textTheme: text,
      scaffoldBackgroundColor: scheme.surface,
      extensions: [brand],
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        elevation: 0,
        scrolledUnderElevation: 1,
        titleTextStyle: text.titleLarge,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          shape: const RoundedRectangleBorder(borderRadius: radius),
          textStyle: text.labelLarge,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          shape: const RoundedRectangleBorder(borderRadius: radius),
          textStyle: text.labelLarge,
        ),
      ),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(textStyle: text.labelLarge)),
      inputDecorationTheme: InputDecorationTheme(
        border: const OutlineInputBorder(borderRadius: radius),
        focusedBorder: OutlineInputBorder(
          borderRadius: radius,
          borderSide: BorderSide(color: focus, width: 2),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
    );
  }

  /// docs/brand.md §2: Display 800, H1–H3 700, body 16/1.5, small 14/1.45, caption/label 12 600.
  /// Body is never below 16 on mobile.
  static TextTheme _textTheme(Color color) => TextTheme(
    displayLarge: TextStyle(fontSize: 48, height: 1.1, fontWeight: FontWeight.w800, color: color),
    displayMedium: TextStyle(fontSize: 40, height: 1.1, fontWeight: FontWeight.w800, color: color),
    displaySmall: TextStyle(fontSize: 36, height: 1.1, fontWeight: FontWeight.w800, color: color),
    headlineLarge: TextStyle(fontSize: 30, height: 1.2, fontWeight: FontWeight.w700, color: color),
    headlineMedium: TextStyle(fontSize: 24, height: 1.25, fontWeight: FontWeight.w700, color: color),
    headlineSmall: TextStyle(fontSize: 20, height: 1.3, fontWeight: FontWeight.w700, color: color),
    titleLarge: TextStyle(fontSize: 20, height: 1.3, fontWeight: FontWeight.w700, color: color),
    titleMedium: TextStyle(fontSize: 16, height: 1.5, fontWeight: FontWeight.w700, color: color),
    titleSmall: TextStyle(fontSize: 14, height: 1.45, fontWeight: FontWeight.w600, color: color),
    bodyLarge: TextStyle(fontSize: 16, height: 1.5, fontWeight: FontWeight.w400, color: color),
    bodyMedium: TextStyle(fontSize: 16, height: 1.5, fontWeight: FontWeight.w400, color: color),
    bodySmall: TextStyle(fontSize: 14, height: 1.45, fontWeight: FontWeight.w400, color: color),
    labelLarge: TextStyle(fontSize: 16, height: 1.25, fontWeight: FontWeight.w700, color: color),
    labelMedium: TextStyle(fontSize: 14, height: 1.4, fontWeight: FontWeight.w600, color: color),
    labelSmall: TextStyle(fontSize: 12, height: 1.4, fontWeight: FontWeight.w600, color: color),
  );
}
