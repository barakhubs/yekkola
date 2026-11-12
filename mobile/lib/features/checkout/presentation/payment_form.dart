import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../auth/presentation/sign_in_controller.dart';
import 'checkout_providers.dart';

/// Operators the API knows, in the order shown. Unknown ones (added later) still appear, by their code.
const knownRails = ['orange', 'airtel', 'mpesa'];

String railLabel(AppLocalizations l10n, String rail) => switch (rail) {
  'orange' => l10n.railOrange,
  'airtel' => l10n.railAirtel,
  'mpesa' => l10n.railMpesa,
  _ => rail,
};

final railsProvider = FutureProvider.autoDispose.family<Map<String, bool>, String>(
  (ref, currency) => ref.watch(checkoutRepositoryProvider).rails(currency),
  retry: (_, _) => null,
);

/// Operator + payer number (PRD-05 FR-05, FR-19): only operators the API says can take a payment now are
/// selectable; the number defaults to the account's phone and can be changed (a parent's number, PRD-05 §7).
class PaymentForm extends ConsumerStatefulWidget {
  const PaymentForm({
    super.key,
    required this.currency,
    required this.submitLabel,
    required this.onSubmit,
    this.busy = false,
  });

  final String currency;
  final String submitLabel;
  final bool busy;

  /// Rail code and the payer number (E.164).
  final Future<void> Function(String rail, String msisdn) onSubmit;

  @override
  ConsumerState<PaymentForm> createState() => _PaymentFormState();
}

class _PaymentFormState extends ConsumerState<PaymentForm> {
  String? _rail;
  late final _phone = TextEditingController(text: _defaultPhone());

  String _defaultPhone() {
    final auth = ref.read(authControllerProvider);
    final phone = auth is SignedIn ? auth.user.phone : '';
    return phone.startsWith('+243') ? '0${phone.substring(4)}' : phone;
  }

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final rails = ref.watch(railsProvider(widget.currency));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(l10n.checkoutOperator, style: theme.textTheme.titleMedium),
        const SizedBox(height: 8),
        ...switch (rails) {
          AsyncData(:final value) => _railTiles(l10n, value),
          AsyncError(:final error) => [
            Text(error is ApiException ? apiErrorText(l10n, error) : l10n.errorGeneric),
            TextButton(onPressed: () => ref.invalidate(railsProvider(widget.currency)), child: Text(l10n.commonRetry)),
          ],
          _ => [const Center(child: CircularProgressIndicator())],
        },
        const SizedBox(height: 16),
        TextField(
          controller: _phone,
          enabled: !widget.busy,
          keyboardType: TextInputType.phone,
          onChanged: (_) => setState(() {}),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ \-]'))],
          decoration: InputDecoration(
            labelText: l10n.checkoutPayer,
            helperText: l10n.checkoutPayerHint,
            helperMaxLines: 2,
          ),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: widget.busy || _rail == null || _phone.text.trim().isEmpty
              ? null
              : () => widget.onSubmit(_rail!, normalizePhoneInput(_phone.text)),
          child: widget.busy
              ? Semantics(
                  label: widget.submitLabel,
                  child: const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5)),
                )
              : Text(widget.submitLabel),
        ),
        const SizedBox(height: 8),
        Text(l10n.checkoutSecure, style: theme.textTheme.bodySmall),
      ],
    );
  }

  List<Widget> _railTiles(AppLocalizations l10n, Map<String, bool> rails) {
    final codes = [...knownRails.where(rails.containsKey), ...rails.keys.where((r) => !knownRails.contains(r))];
    if (!codes.any((r) => rails[r] == true)) return [Text(l10n.checkoutNoRails)];

    return [
      RadioGroup<String>(
        groupValue: _rail,
        onChanged: (value) => setState(() => _rail = value),
        child: Column(
          children: [
            for (final rail in codes)
              RadioListTile<String>(
                value: rail,
                enabled: rails[rail] == true && !widget.busy,
                title: Text(railLabel(l10n, rail)),
                subtitle: rails[rail] == true ? null : Text(l10n.checkoutRailUnavailable),
              ),
          ],
        ),
      ),
    ];
  }
}
