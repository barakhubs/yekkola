import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/i18n/formatters.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/domain/auth_state.dart';
import '../../auth/presentation/api_error_text.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../../core/providers.dart';
import '../data/checkout_repository.dart';
import '../data/idempotency.dart';
import '../domain/checkout_state.dart';
import 'checkout_providers.dart';
import 'payment_form.dart';

/// After the payment request (PRD-05 FR-06–FR-09): "approve on your phone" with the deadline, then success or
/// a clear reason with a retry on the same order until it expires. Success is shown only once the API
/// confirms it. Polls with backoff; stops in the background and checks again on return (or after a restart:
/// the course's open order is remembered).
class OrderScreen extends ConsumerStatefulWidget {
  const OrderScreen({super.key, required this.orderId});

  final String orderId;

  @override
  ConsumerState<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends ConsumerState<OrderScreen> with WidgetsBindingObserver {
  OrderResource? _order;
  ApiException? _error;
  bool _paying = false;
  int _polls = 0;
  Timer? _poll;
  Timer? _clock;

  /// In the background: no polling (data and battery); checked again on return.
  bool _paused = false;

  void _schedule() {
    _poll?.cancel();
    if (!mounted || _paused) return;
    _poll = Timer(pollDelay(_polls++), _load);
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _clock = Timer.periodic(const Duration(seconds: 1), (_) {
      final order = _order;
      // The countdown ticks only while waiting.
      if (mounted && order != null && stateOf(order) == CheckoutState.waiting) setState(() {});
    });
    unawaited(_load());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    _clock?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _paused = false;
      _polls = 0;
      unawaited(_load());
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      _paused = true;
      _poll?.cancel();
    }
  }

  Future<void> _load() async {
    _poll?.cancel();
    try {
      final order = await ref.read(checkoutRepositoryProvider).order(widget.orderId);
      if (!mounted) return;
      setState(() {
        _order = order;
        _error = null;
      });
      await _settle(order);
      if (stateOf(order) == CheckoutState.waiting) _schedule();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e);
      // Offline or a hiccup: keep trying while the student waits (not after a 404).
      if (e.status != 404) _schedule();
    }
  }

  /// A final result: forget the open order; a confirmed payment refreshes "my courses".
  Future<void> _settle(OrderResource order) async {
    final state = stateOf(order);
    if (state != CheckoutState.paid && state != CheckoutState.expired && state != CheckoutState.refunded) return;
    final auth = ref.read(authControllerProvider);
    if (auth is SignedIn) {
      for (final item in order.items) {
        await ref.read(checkoutRepositoryProvider).forgetOpenOrder(auth.user.id, item.courseId);
      }
    }
    if (state == CheckoutState.paid && mounted) ref.read(enrollmentsChangedProvider.notifier).changed();
  }

  Future<void> _pay(String rail, String msisdn) async {
    final order = _order;
    if (order == null) return;
    setState(() => _paying = true);
    try {
      await ref.read(checkoutRepositoryProvider).pay(order, rail: rail, msisdn: msisdn);
      _polls = 0;
      await _load();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
      // Not a final refusal: the request may have reached the operator — look at the order again.
      if (!IdempotencyKeys.isFinalAnswer(e)) {
        _polls = 0;
        await _load();
      }
    } finally {
      if (mounted) setState(() => _paying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final order = _order;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.checkoutTitle)),
      body: SafeArea(
        child: order == null
            ? Center(
                child: _error == null
                    ? const CircularProgressIndicator()
                    : Padding(
                        padding: const EdgeInsets.all(24),
                        child: Text(
                          _error!.status == 404 ? l10n.checkoutUnknownOrder : apiErrorText(l10n, _error!),
                          textAlign: TextAlign.center,
                        ),
                      ),
              )
            : ListView(padding: const EdgeInsets.all(24), children: _content(context, l10n, order)),
      ),
    );
  }

  List<Widget> _content(BuildContext context, AppLocalizations l10n, OrderResource order) {
    final theme = Theme.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final total = Formatters.money(order.total.amountMinor, order.total.currency, locale);
    final latest = order.payments.isEmpty ? null : order.payments.first;
    // The server's clock and status decide, not the phone's (often wrong on low-end phones).
    final now = ref.read(serverClockProvider).now();
    final canPay = CheckoutRepository.canBePaid(order);

    Widget heading(IconData icon, String text, {Color? color}) => Semantics(
      header: true,
      liveRegion: true,
      child: Column(
        children: [
          Icon(icon, size: 56, color: color ?? theme.colorScheme.primary),
          const SizedBox(height: 16),
          Text(text, textAlign: TextAlign.center, style: theme.textTheme.headlineSmall),
        ],
      ),
    );

    switch (stateOf(order)) {
      case CheckoutState.waiting:
        final left = DateTime.parse(order.expiresAt).difference(now);
        return [
          heading(Icons.phone_android, l10n.checkoutWaitingTitle),
          const SizedBox(height: 16),
          Text(
            l10n.checkoutWaitingBody(railLabel(l10n, latest!.rail), total, Formatters.phone(latest.payer)),
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyLarge,
          ),
          const SizedBox(height: 16),
          Text(l10n.checkoutWaitingHint, textAlign: TextAlign.center),
          const SizedBox(height: 24),
          Center(child: CircularProgressIndicator(semanticsLabel: l10n.checkoutWaitingTitle)),
          const SizedBox(height: 16),
          if (!left.isNegative)
            Text(
              l10n.checkoutExpiresIn(l10n.durationMinutesSeconds(left.inMinutes, left.inSeconds % 60)),
              textAlign: TextAlign.center,
            ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(apiErrorText(l10n, _error!), textAlign: TextAlign.center),
          ],
        ];
      case CheckoutState.paid:
        return [
          heading(Icons.check_circle, l10n.checkoutPaidTitle),
          const SizedBox(height: 16),
          Text(l10n.checkoutPaidBody, textAlign: TextAlign.center, style: theme.textTheme.bodyLarge),
          const SizedBox(height: 24),
          FilledButton(onPressed: () => context.go(Routes.home), child: Text(l10n.checkoutStartLearning)),
        ];
      case CheckoutState.refunded:
        return [
          heading(Icons.undo, l10n.checkoutRefundedTitle),
          const SizedBox(height: 16),
          Text(l10n.checkoutRefundedBody, textAlign: TextAlign.center),
        ];
      case CheckoutState.expired:
        return [
          heading(Icons.timer_off_outlined, l10n.checkoutExpiredTitle, color: theme.colorScheme.error),
          const SizedBox(height: 16),
          Text(l10n.checkoutExpiredBody, textAlign: TextAlign.center),
          const SizedBox(height: 24),
          FilledButton(onPressed: () => context.pop(), child: Text(l10n.checkoutStartOver)),
        ];
      case CheckoutState.failed || CheckoutState.choose:
        final failed = latest != null;
        return [
          if (failed) ...[
            heading(Icons.error_outline, l10n.checkoutFailedTitle, color: theme.colorScheme.error),
            if (latest.failureReason != null) ...[
              const SizedBox(height: 12),
              Text(latest.failureReason!, textAlign: TextAlign.center, style: theme.textTheme.bodyLarge),
            ],
            const SizedBox(height: 8),
            Text(l10n.checkoutRetryOther, textAlign: TextAlign.center),
          ] else
            Text(l10n.checkoutChooseTitle, style: theme.textTheme.titleLarge),
          const SizedBox(height: 24),
          if (_error != null) ...[
            Text(apiErrorText(l10n, _error!), style: TextStyle(color: theme.colorScheme.error)),
            const SizedBox(height: 8),
          ],
          if (canPay)
            PaymentForm(
              currency: order.currency,
              busy: _paying,
              submitLabel: failed ? l10n.commonRetry : l10n.checkoutPay(total),
              onSubmit: _pay,
            )
          else
            FilledButton(onPressed: () => context.pop(), child: Text(l10n.checkoutStartOver)),
        ];
    }
  }
}
