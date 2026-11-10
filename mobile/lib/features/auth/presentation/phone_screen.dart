import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../domain/auth_state.dart';
import 'api_error_text.dart';
import 'auth_controller.dart';
import 'sign_in_controller.dart';
import 'widgets/auth_scaffold.dart';

/// Step 1: phone number (PRD-01 FR-01/FR-02). DRC numbers can be typed nationally (0812 345 678).
class PhoneScreen extends ConsumerStatefulWidget {
  const PhoneScreen({super.key});

  @override
  ConsumerState<PhoneScreen> createState() => _PhoneScreenState();
}

class _PhoneScreenState extends ConsumerState<PhoneScreen> {
  late final _phone = TextEditingController(text: _prefill());

  String _prefill() {
    final phone = ref.read(signInControllerProvider).phone;
    return phone.startsWith('+243') ? '0${phone.substring(4)}' : phone;
  }

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_phone.text.trim().isEmpty) return;
    final sent = await ref.read(signInControllerProvider.notifier).requestCode(_phone.text);
    if (sent && mounted) unawaited(context.push(Routes.signInCode));
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final flow = ref.watch(signInControllerProvider);
    final auth = ref.watch(authControllerProvider);
    final expired = auth is SignedOut && auth.sessionExpired;

    return AuthScaffold(
      title: l10n.authTitle,
      subtitle: l10n.authSubtitle,
      children: [
        if (expired) ...[
          Card(
            child: Padding(padding: const EdgeInsets.all(16), child: Text(l10n.authSessionExpired)),
          ),
          const SizedBox(height: 16),
        ],
        TextField(
          controller: _phone,
          enabled: !flow.busy,
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.telephoneNumber],
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ \-]'))],
          textInputAction: TextInputAction.done,
          onSubmitted: (_) => _submit(),
          decoration: InputDecoration(
            labelText: l10n.authPhoneLabel,
            helperText: l10n.authPhoneHint,
            prefixIcon: const Icon(Icons.phone_outlined),
          ),
        ),
        const SizedBox(height: 16),
        if (flow.error != null) FormError(apiErrorText(l10n, flow.error!)),
        FilledButton(
          onPressed: flow.busy ? null : _submit,
          child: flow.busy
              ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
              : Text(l10n.authSendCode),
        ),
      ],
    );
  }
}
