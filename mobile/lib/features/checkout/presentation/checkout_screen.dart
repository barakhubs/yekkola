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
import '../data/checkout_repository.dart';
import '../data/idempotency.dart';
import '../domain/checkout_state.dart';
import 'checkout_providers.dart';
import 'payment_form.dart';

/// Buying one course (PRD-05 FR-02–FR-05, FR-11): the API's quote (owned courses left out, coupon applied),
/// then operator + payer, then the order and the payment request — both under idempotency keys. An order
/// still open for this course on this phone is resumed instead of starting another.
class CheckoutScreen extends ConsumerStatefulWidget {
  const CheckoutScreen({super.key, required this.courseId});

  final String courseId;

  @override
  ConsumerState<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends ConsumerState<CheckoutScreen> {
  CheckoutQuoteResponse200Data? _quote;
  ApiException? _error;
  String? _coupon;
  final _couponText = TextEditingController();
  bool _busy = false;

  /// Nothing left to buy: the student already owns it (`order.nothing_to_buy`, FR-04).
  bool _owned = false;

  /// Null once the session ended (the router then leads to sign-in).
  String? get _userId => switch (ref.read(authControllerProvider)) {
    SignedIn(:final user) => user.id,
    SignedOut() => null,
  };

  @override
  void initState() {
    super.initState();
    unawaited(_start());
  }

  @override
  void dispose() {
    _couponText.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    final userId = _userId;
    if (userId == null) return;
    final repo = ref.read(checkoutRepositoryProvider);
    // Back after closing the app mid-payment: continue that order (PRD-05 §7).
    final open = await repo.openOrder(userId, widget.courseId);
    if (open != null) {
      try {
        final order = await repo.order(open);
        if (!mounted) return;
        if (CheckoutRepository.canBePaid(order) || order.status == 'paid') {
          context.pushReplacement(Routes.order(order.id));
          return;
        }
        await repo.forgetOpenOrder(userId, widget.courseId);
      } on ApiException catch (e) {
        if (e.status == 404) {
          await repo.forgetOpenOrder(userId, widget.courseId);
        } else if (mounted) {
          // Offline: the order screen shows it and keeps trying.
          context.pushReplacement(Routes.order(open));
          return;
        }
      }
    }
    await _requote();
  }

  Future<void> _requote({String? coupon}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final quote = await ref.read(checkoutRepositoryProvider).quote(widget.courseId, coupon: coupon);
      if (!mounted) return;
      setState(() {
        _quote = quote;
        _coupon = quote.coupon?.code;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => e.code == 'order.nothing_to_buy' ? _owned = true : _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _order({String? rail, String? msisdn}) async {
    final userId = _userId;
    if (userId == null) return;
    final l10n = AppLocalizations.of(context);
    final messenger = ScaffoldMessenger.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    final repo = ref.read(checkoutRepositoryProvider);
    try {
      // The open order if there is one (resumed, never a second one), else a new order.
      final result = await repo.orderFor(userId: userId, courseId: widget.courseId, coupon: _coupon);
      final order = result.order;
      if (result.keptOpenOrder && _coupon != null) {
        messenger.showSnackBar(SnackBar(content: Text(l10n.checkoutCouponNotApplied)));
      }
      // A total of 0 (100 % coupon) needs no payment (BR-05); an attempt already waiting isn't repeated.
      final state = stateOf(order);
      if (rail != null && (state == CheckoutState.choose || state == CheckoutState.failed)) {
        try {
          await repo.pay(order, rail: rail, msisdn: msisdn);
        } on ApiException catch (e) {
          // Refused for good (e.g. a wrong number): stay here and say why. Otherwise the request may have
          // reached the operator: the order screen finds out.
          if (IdempotencyKeys.isFinalAnswer(e)) rethrow;
        }
      }
      if (mounted) context.pushReplacement(Routes.order(order.id));
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final quote = _quote;
    String money(int minor, String currency) => Formatters.money(minor, currency, locale);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.checkoutTitle)),
      body: _owned
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(l10n.checkoutAlreadyOwned, style: theme.textTheme.titleMedium, textAlign: TextAlign.center),
                    const SizedBox(height: 12),
                    FilledButton(
                      onPressed: () => context.pushReplacement(Routes.learn(widget.courseId)),
                      child: Text(l10n.checkoutGoToCourse),
                    ),
                  ],
                ),
              ),
            )
          : quote == null
          ? Center(
              child: _error == null
                  ? const CircularProgressIndicator()
                  : Padding(
                      padding: const EdgeInsets.all(24),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(apiErrorText(l10n, _error!), textAlign: TextAlign.center),
                          const SizedBox(height: 12),
                          OutlinedButton(onPressed: _requote, child: Text(l10n.commonRetry)),
                        ],
                      ),
                    ),
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (quote.items.isEmpty || _owned) ...[
                  // Everything selected is already owned (FR-04).
                  Text(l10n.checkoutAlreadyOwned, style: theme.textTheme.titleMedium),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: () => context.pushReplacement(Routes.learn(widget.courseId)),
                    child: Text(l10n.checkoutGoToCourse),
                  ),
                ] else ...[
                  Text(l10n.checkoutSummary, style: theme.textTheme.titleLarge),
                  const SizedBox(height: 8),
                  for (final item in quote.items)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text(item.title ?? ''),
                      trailing: Text(money(item.price.amountMinor, item.price.currency)),
                    ),
                  if (quote.excludedCourseIds.isNotEmpty)
                    Text(l10n.checkoutOwnedExcluded(quote.excludedCourseIds.length)),
                  if (quote.discount.amountMinor > 0)
                    _Line(
                      l10n.checkoutDiscount,
                      l10n.checkoutDiscountAmount(money(quote.discount.amountMinor, quote.discount.currency)),
                    ),
                  _Line(l10n.checkoutTotal, money(quote.total.amountMinor, quote.total.currency), strong: true),
                  const SizedBox(height: 16),
                  _CouponField(
                    controller: _couponText,
                    applied: _coupon,
                    busy: _busy,
                    onApply: (code) => _requote(coupon: code),
                    onRemove: () {
                      _couponText.clear();
                      _requote();
                    },
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 8),
                    Text(apiErrorText(l10n, _error!), style: TextStyle(color: theme.colorScheme.error)),
                  ],
                  const SizedBox(height: 24),
                  if (quote.isFree)
                    FilledButton(onPressed: _busy ? null : () => _order(), child: Text(l10n.checkoutGetFree))
                  else
                    PaymentForm(
                      currency: quote.currency,
                      busy: _busy,
                      submitLabel: l10n.checkoutPay(money(quote.total.amountMinor, quote.total.currency)),
                      onSubmit: (rail, msisdn) => _order(rail: rail, msisdn: msisdn),
                    ),
                ],
              ],
            ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line(this.label, this.value, {this.strong = false});

  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final style = strong ? Theme.of(context).textTheme.titleMedium : null;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: style)),
          Text(value, style: style?.copyWith(fontFeatures: const [FontFeature.tabularFigures()])),
        ],
      ),
    );
  }
}

class _CouponField extends StatelessWidget {
  const _CouponField({
    required this.controller,
    required this.applied,
    required this.busy,
    required this.onApply,
    required this.onRemove,
  });

  final TextEditingController controller;
  final String? applied;
  final bool busy;
  final void Function(String code) onApply;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    if (applied != null) {
      return Row(
        children: [
          const Icon(Icons.local_offer_outlined),
          const SizedBox(width: 8),
          Expanded(child: Text(l10n.checkoutCouponApplied(applied!))),
          TextButton(onPressed: busy ? null : onRemove, child: Text(l10n.checkoutRemoveCoupon)),
        ],
      );
    }
    return Row(
      children: [
        Expanded(
          child: TextField(
            controller: controller,
            enabled: !busy,
            textCapitalization: TextCapitalization.characters,
            decoration: InputDecoration(labelText: l10n.checkoutCoupon),
            onSubmitted: (code) => code.trim().isEmpty ? null : onApply(code),
          ),
        ),
        const SizedBox(width: 8),
        OutlinedButton(
          onPressed: busy ? null : () => controller.text.trim().isEmpty ? null : onApply(controller.text),
          child: Text(l10n.checkoutApplyCoupon),
        ),
      ],
    );
  }
}
