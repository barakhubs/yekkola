import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/i18n/formatters.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import 'api_error_text.dart';
import 'sign_in_controller.dart';
import 'widgets/auth_scaffold.dart';

/// Step 2: the 6-digit code (PRD-01 FR-02/FR-03). Resend unlocks after the API's cooldown.
class CodeScreen extends ConsumerStatefulWidget {
  const CodeScreen({super.key});

  @override
  ConsumerState<CodeScreen> createState() => _CodeScreenState();
}

class _CodeScreenState extends ConsumerState<CodeScreen> {
  final _code = TextEditingController();
  Timer? _timer;
  int _resendIn = 0;
  int _resends = 0;

  @override
  void initState() {
    super.initState();
    _startCountdown();
  }

  void _startCountdown() {
    _timer?.cancel();
    final wait = ref.read(signInControllerProvider).challenge?.resendAfter ?? Duration.zero;
    setState(() => _resendIn = wait.inSeconds);
    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) return;
      setState(() => _resendIn = _resendIn > 0 ? _resendIn - 1 : 0);
      if (_resendIn == 0) timer.cancel();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _code.dispose();
    super.dispose();
  }

  Future<void> _verify() async {
    final code = _code.text.trim();
    if (code.length != 6) return;
    final outcome = await ref.read(signInControllerProvider.notifier).verify(code);
    if (!mounted) return;
    switch (outcome) {
      case VerifyOutcome.deviceLimit:
        unawaited(context.push(Routes.signInDevices));
      case VerifyOutcome.failed || VerifyOutcome.codeUnusable:
        _code.clear();
      case VerifyOutcome.signedIn || VerifyOutcome.newUser:
        // The router's redirect takes over once the session exists.
        break;
    }
  }

  Future<void> _resend() async {
    final sent = await ref.read(signInControllerProvider.notifier).resend();
    if (!mounted) return;
    if (sent) {
      _code.clear();
      setState(() => _resends++);
    }
    _startCountdown();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final flow = ref.watch(signInControllerProvider);

    return AuthScaffold(
      title: l10n.authCodeTitle,
      subtitle: l10n.authCodeSentTo(Formatters.phone(flow.phone)),
      showBack: true,
      onBack: () => context.pop(),
      children: [
        TextField(
          controller: _code,
          enabled: !flow.busy,
          autofocus: true,
          keyboardType: TextInputType.number,
          autofillHints: const [AutofillHints.oneTimeCode],
          inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
          style: Theme.of(context).textTheme.headlineMedium?.copyWith(letterSpacing: 8),
          textAlign: TextAlign.center,
          onChanged: (value) {
            if (value.length == 6) _verify();
          },
          decoration: InputDecoration(labelText: l10n.authCodeLabel, counterText: ''),
        ),
        const SizedBox(height: 16),
        if (flow.error != null) FormError(apiErrorText(l10n, flow.error!)),
        FilledButton(
          onPressed: flow.busy ? null : _verify,
          child: flow.busy
              ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
              : Text(l10n.authVerify),
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: flow.busy || _resendIn > 0 ? null : _resend,
          child: Text(_resendIn > 0 ? l10n.authResendIn(_resendIn) : l10n.authResend),
        ),
        TextButton(onPressed: flow.busy ? null : () => context.pop(), child: Text(l10n.authChangeNumber)),
        if (_resends > 0) ...[
          const SizedBox(height: 16),
          Text(l10n.authNoCodeTitle, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(l10n.authNoCodeHint),
        ],
      ],
    );
  }
}
