import '../../../core/api/api_exception.dart';
import '../../../l10n/generated/app_localizations.dart';

/// User-facing text for an API failure. Known sign-in codes use the app's own copy; anything else
/// falls back to the API's translated title.
String apiErrorText(AppLocalizations l10n, ApiException e) {
  final wait = e.retryAfter;
  String withWait(String message) => wait != null && wait > 0 ? '$message ${l10n.errorTryAgainIn(wait)}' : message;

  return switch (e.code) {
    ApiException.networkCode => l10n.errorNetwork,
    'phone.invalid' => l10n.errorPhoneInvalid,
    'phone.country_not_supported' => l10n.errorPhoneCountry,
    'otp.invalid' => l10n.errorOtpInvalid((e.context['attempts_remaining'] as int?) ?? 0),
    'otp.expired' => l10n.errorOtpExpired,
    'otp.too_many_attempts' => l10n.errorOtpTooManyAttempts,
    'otp.locked' => withWait(l10n.errorOtpLocked),
    'otp.rate_limited' || 'rate_limited' => withWait(l10n.errorOtpRateLimited),
    'otp.cooldown' => wait != null ? l10n.errorTryAgainIn(wait) : l10n.errorOtpRateLimited,
    'otp.temporarily_unavailable' => withWait(l10n.errorOtpUnavailable),
    'account.banned' => l10n.errorAccountBanned,
    'validation.failed' => e.fieldError('phone') ?? e.fieldError('code') ?? e.fieldError('name') ?? l10n.errorGeneric,
    _ => e.title ?? l10n.errorGeneric,
  };
}
