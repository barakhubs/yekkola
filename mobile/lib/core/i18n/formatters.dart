import 'package:intl/intl.dart';

/// Shared formatters: dates and phone numbers are never built by string concatenation in widgets.
abstract final class Formatters {
  /// `12 nov. 2026, 14:05` / `Nov 12, 2026, 2:05 PM`.
  static String dateTime(DateTime value, String locale) => DateFormat.yMMMd(locale).add_jm().format(value.toLocal());

  /// E.164 → readable groups: `+243812345678` → `+243 812 345 678`. Other formats are returned as is.
  static String phone(String e164) {
    final match = RegExp(r'^\+243(\d{9})$').firstMatch(e164);
    if (match == null) return e164;
    final n = match.group(1)!;
    return '+243 ${n.substring(0, 3)} ${n.substring(3, 6)} ${n.substring(6)}';
  }
}
