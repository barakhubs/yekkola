import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/i18n/formatters.dart';
import '../../../core/router/app_router.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../auth/presentation/api_error_text.dart';
import 'checkout_providers.dart';

/// The course page's action (PRD-05 FR-01, FR-02): open the course if enrolled, one tap for a free course, or
/// buy. Whether it is free and what it costs come from the API.
class CourseActionBar extends ConsumerStatefulWidget {
  const CourseActionBar({super.key, required this.course});

  final CourseDetailResource course;

  @override
  ConsumerState<CourseActionBar> createState() => _CourseActionBarState();
}

class _CourseActionBarState extends ConsumerState<CourseActionBar> {
  bool _busy = false;

  Future<void> _enroll() async {
    final l10n = AppLocalizations.of(context);
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _busy = true);
    try {
      await ref.read(checkoutRepositoryProvider).enrollFree(widget.course.id);
      ref.read(enrollmentsChangedProvider.notifier).changed();
      messenger.showSnackBar(SnackBar(content: Text(l10n.checkoutEnrolled)));
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(apiErrorText(l10n, e))));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final course = widget.course;
    final mine = ref.watch(myCourseIdsProvider).value?.data.contains(course.id) ?? false;
    final locale = Localizations.localeOf(context).languageCode;
    final price = course.price;

    final Widget button;
    if (mine) {
      button = FilledButton(onPressed: () => context.go(Routes.home), child: Text(l10n.checkoutGoToCourse));
    } else if (course.isFree) {
      button = FilledButton(
        onPressed: _busy ? null : _enroll,
        child: _busy
            ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
            : Text(l10n.checkoutGetFree),
      );
    } else if (price != null) {
      button = FilledButton(
        onPressed: () => context.push(Routes.checkout(course.id)),
        child: Text(l10n.checkoutBuy(Formatters.money(price.amountMinor, price.currency, locale))),
      );
    } else {
      return const SizedBox.shrink();
    }

    return SafeArea(
      child: Padding(padding: const EdgeInsets.fromLTRB(16, 8, 16, 8), child: button),
    );
  }
}
