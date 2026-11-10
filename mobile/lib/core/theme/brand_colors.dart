import 'package:flutter/material.dart';

/// Brand scales from docs/brand.md (same values as packages/ui's theme tokens).
abstract final class Indigo {
  static const c50 = Color(0xFFF4F6FF);
  static const c100 = Color(0xFFE7ECFE);
  static const c200 = Color(0xFFCEDAFE);
  static const c300 = Color(0xFFB1C1FB);
  static const c400 = Color(0xFF95A7E8);
  static const c500 = Color(0xFF788BD2);
  static const c600 = Color(0xFF5C6EBC);
  static const c700 = Color(0xFF4455A8);
  static const c800 = Color(0xFF2C3892); // brand primary
  static const c900 = Color(0xFF212982);
  static const c950 = Color(0xFF130F68);
}

abstract final class Amber {
  static const c50 = Color(0xFFFEF6E9);
  static const c100 = Color(0xFFFAEAD4);
  static const c200 = Color(0xFFFFD391);
  static const c300 = Color(0xFFFDB73B); // brand secondary
  static const c400 = Color(0xFFE09C0F);
  static const c500 = Color(0xFFBC8309);
  static const c600 = Color(0xFF9A6A04);
  static const c700 = Color(0xFF7D5501);
  static const c800 = Color(0xFF644302);
  static const c900 = Color(0xFF4C3200);
  static const c950 = Color(0xFF322001);
}

/// Tailwind slate, the neutral scale (docs/brand.md "Neutrals").
abstract final class Slate {
  static const c50 = Color(0xFFF8FAFC);
  static const c100 = Color(0xFFF1F5F9);
  static const c200 = Color(0xFFE2E8F0);
  static const c300 = Color(0xFFCBD5E1);
  static const c400 = Color(0xFF94A3B8);
  static const c500 = Color(0xFF64748B);
  static const c600 = Color(0xFF475569);
  static const c700 = Color(0xFF334155);
  static const c800 = Color(0xFF1E293B);
  static const c900 = Color(0xFF0F172A);
  static const c950 = Color(0xFF020617);
}

/// The amber accent ("Gratuit", featured, ratings), kept out of Material's `secondary` the same
/// way the web keeps it out of shadcn's `--secondary`. Never white text on amber.
@immutable
class BrandColors extends ThemeExtension<BrandColors> {
  const BrandColors({required this.accent, required this.onAccent, required this.accentText});

  static const light = BrandColors(accent: Amber.c300, onAccent: Indigo.c800, accentText: Amber.c700);
  static const dark = BrandColors(accent: Amber.c300, onAccent: Indigo.c950, accentText: Amber.c300);

  final Color accent;
  final Color onAccent;

  /// Amber used as text (rare): amber-700 on light, amber-300 on dark.
  final Color accentText;

  @override
  BrandColors copyWith({Color? accent, Color? onAccent, Color? accentText}) => BrandColors(
    accent: accent ?? this.accent,
    onAccent: onAccent ?? this.onAccent,
    accentText: accentText ?? this.accentText,
  );

  @override
  BrandColors lerp(BrandColors? other, double t) => other == null
      ? this
      : BrandColors(
          accent: Color.lerp(accent, other.accent, t)!,
          onAccent: Color.lerp(onAccent, other.onAccent, t)!,
          accentText: Color.lerp(accentText, other.accentText, t)!,
        );
}
