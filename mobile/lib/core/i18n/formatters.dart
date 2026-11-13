import 'package:intl/intl.dart';

/// Shared formatters: dates and phone numbers are never built by string concatenation in widgets.
abstract final class Formatters {
  /// `12 nov. 2026, 14:05` / `Nov 12, 2026, 2:05 PM`.
  static String dateTime(DateTime value, String locale) => DateFormat.yMMMd(locale).add_jm().format(value.toLocal());

  /// Prices, like the web's formatMoney: `15,00 $US` / `$15.00`; francs hide centimes when whole.
  /// Money is integer minor units with its own currency — never converted.
  static String money(int amountMinor, String currency, String locale) {
    const digits = 2; // USD and CDF (ISO 4217)
    final whole = currency == 'CDF' && amountMinor % 100 == 0;
    final symbol = switch (currency) {
      'USD' => locale == 'fr' ? r'$US' : r'$',
      // Like the web (Intl): `25 000 FC` in French, `CDF 25,000` in English.
      'CDF' => locale == 'fr' ? 'FC' : 'CDF\u00a0',
      _ => currency,
    };
    return NumberFormat.currency(
      locale: locale == 'fr' ? 'fr' : 'en',
      name: currency,
      symbol: symbol,
      decimalDigits: whole ? 0 : digits,
    ).format(amountMinor / 100);
  }

  /// `12 nov. 2026` / `Nov 12, 2026`.
  static String date(DateTime value, String locale) => DateFormat.yMMMd(locale).format(value.toLocal());

  /// `novembre 2026` / `November 2026`.
  static String monthYear(DateTime value, String locale) => DateFormat.yMMMM(locale).format(value.toLocal());

  /// A countdown: `4:05`.
  static String clock(int seconds) => '${seconds ~/ 60}:${(seconds % 60).toString().padLeft(2, '0')}';

  /// `4,5` / `4.5`.
  static String decimal(num value, String locale) => NumberFormat('#,##0.#', locale).format(value);

  /// Download sizes: `80 Mo` / `80 MB` (decimal units, as phones show storage).
  static String bytes(int value, String locale) {
    const units = ['B', 'KB', 'MB', 'GB'];
    const unitsFr = ['o', 'Ko', 'Mo', 'Go'];
    var size = value.toDouble();
    var unit = 0;
    while (size >= 1000 && unit < units.length - 1) {
      size /= 1000;
      unit++;
    }
    final number = NumberFormat(size >= 10 || unit == 0 ? '#,##0' : '#,##0.0', locale).format(size);
    return '$number ${(locale == 'fr' ? unitsFr : units)[unit]}';
  }

  /// E.164 → readable groups: `+243812345678` → `+243 812 345 678`. Other formats are returned as is.
  static String phone(String e164) {
    final match = RegExp(r'^\+243(\d{9})$').firstMatch(e164);
    if (match == null) return e164;
    final n = match.group(1)!;
    return '+243 ${n.substring(0, 3)} ${n.substring(3, 6)} ${n.substring(6)}';
  }
}
