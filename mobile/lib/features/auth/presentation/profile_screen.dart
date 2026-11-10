import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/i18n/locale_controller.dart';
import '../../../l10n/generated/app_localizations.dart';
import 'api_error_text.dart';
import 'auth_controller.dart';
import 'widgets/auth_scaffold.dart';

/// First sign-in: the name (required) and the language chosen on this phone (PRD-01 FR-04).
/// Email and province are optional and live in the account settings.
class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  final _name = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = AppLocalizations.of(context);
    final name = _name.text.trim();
    // Only emptiness is checked here; the API owns the rule and its translated message.
    if (name.isEmpty) {
      setState(() => _error = l10n.profileNameRequired);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final locale = ref.read(localeControllerProvider).languageCode;
      final user = await ref.read(authRepositoryProvider).updateProfile(name: name, locale: locale);
      await ref.read(authControllerProvider.notifier).userUpdated(user);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.fieldError('name') ?? apiErrorText(l10n, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);

    return AuthScaffold(
      title: l10n.profileTitle,
      subtitle: l10n.profileSubtitle,
      children: [
        TextField(
          controller: _name,
          enabled: !_busy,
          autofocus: true,
          textCapitalization: TextCapitalization.words,
          autofillHints: const [AutofillHints.name],
          maxLength: 100,
          onSubmitted: (_) => _submit(),
          decoration: InputDecoration(labelText: l10n.profileNameLabel, counterText: ''),
        ),
        const SizedBox(height: 16),
        if (_error != null) FormError(_error!),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: _busy
              ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
              : Text(l10n.commonContinue),
        ),
      ],
    );
  }
}
