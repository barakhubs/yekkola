import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/api/api_exception.dart';
import '../../../../core/cache/response_cache.dart';
import '../../../../core/i18n/formatters.dart';
import '../../../../core/theme/brand_colors.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../auth/presentation/api_error_text.dart';

/// A cover or avatar from the public bucket, cached on disk (it shows offline) and decoded at display size
/// so low-end phones don't hold full-size bitmaps.
class NetImage extends StatelessWidget {
  const NetImage(this.url, {super.key, required this.width, required this.height, this.radius = 8, this.icon});

  final String? url;
  final double width;
  final double height;
  final double radius;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final placeholder = ColoredBox(
      color: scheme.surfaceContainerHighest,
      child: Icon(icon ?? Icons.school_outlined, color: scheme.onSurfaceVariant),
    );
    final ratio = MediaQuery.devicePixelRatioOf(context);

    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: SizedBox(
        width: width,
        height: height,
        child: url == null
            ? placeholder
            : CachedNetworkImage(
                imageUrl: url!,
                fit: BoxFit.cover,
                memCacheWidth: width.isFinite ? (width * ratio).round() : null,
                placeholder: (_, _) => placeholder,
                errorWidget: (_, _, _) => placeholder,
              ),
      ),
    );
  }
}

/// "Gratuit" on the amber accent (never white on amber), or the price in its own currency.
class PriceTag extends StatelessWidget {
  const PriceTag({super.key, required this.isFree, this.amountMinor, this.currency, this.large = false});

  final bool isFree;
  final int? amountMinor;
  final String? currency;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final style = large ? theme.textTheme.titleLarge : theme.textTheme.labelLarge;

    // The API says what is free; a paid course without a price shows none rather than "Gratuit".
    if (!isFree && (amountMinor == null || currency == null)) return const SizedBox.shrink();
    if (isFree) {
      final brand = theme.extension<BrandColors>()!;
      return DecoratedBox(
        decoration: BoxDecoration(color: brand.accent, borderRadius: BorderRadius.circular(6)),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
          child: Text(l10n.priceFree, style: style?.copyWith(color: brand.onAccent)),
        ),
      );
    }
    final locale = Localizations.localeOf(context).languageCode;
    return Text(
      Formatters.money(amountMinor!, currency!, locale),
      style: style?.copyWith(fontFeatures: const [FontFeature.tabularFigures()]),
    );
  }
}

class Rating extends StatelessWidget {
  const Rating({super.key, required this.rating, required this.count});

  final num? rating;
  final int count;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    // No rating yet: "Nouveau" rather than a misleading score (PRD-04 BR-02).
    if (rating == null || count == 0) {
      return Text(l10n.ratingNew, style: TextStyle(color: Theme.of(context).extension<BrandColors>()!.accentText));
    }
    final locale = Localizations.localeOf(context).languageCode;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Icon(Icons.star_rounded, size: 18, color: Amber.c300),
        const SizedBox(width: 2),
        Text(l10n.courseRating(Formatters.decimal(rating!, locale), count)),
      ],
    );
  }
}

/// Shown above a page read from the phone's copy because the network failed.
class OfflineNotice extends StatelessWidget {
  const OfflineNotice({super.key, required this.cached});

  final Cached<Object?> cached;

  @override
  Widget build(BuildContext context) {
    final error = cached.refreshError;
    if (error == null) return const SizedBox.shrink();
    final l10n = AppLocalizations.of(context);
    final locale = Localizations.localeOf(context).languageCode;
    final theme = Theme.of(context);

    return Semantics(
      liveRegion: true,
      child: Material(
        color: theme.colorScheme.secondaryContainer,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              Icon(cached.isOffline ? Icons.cloud_off : Icons.error_outline, size: 18),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  cached.isOffline
                      ? l10n.offlineCopy(Formatters.dateTime(cached.fetchedAt, locale))
                      : l10n.refreshFailedCopy(Formatters.dateTime(cached.fetchedAt, locale)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Loading / nothing-saved-and-offline / content, for a page backed by [ResponseCache].
class CachedView<T> extends StatelessWidget {
  const CachedView({super.key, required this.value, required this.builder, required this.onRetry});

  final AsyncValue<Cached<T>> value;
  final Widget Function(BuildContext context, Cached<T> cached) builder;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return switch (value) {
      AsyncData(:final value) => builder(context, value),
      AsyncError(:final error) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                error is ApiException && error.isNetwork ? Icons.cloud_off : Icons.error_outline,
                size: 40,
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
              const SizedBox(height: 12),
              Text(
                error is ApiException && error.isNetwork
                    ? l10n.offlineNothing
                    : (error is ApiException ? apiErrorText(l10n, error) : l10n.errorGeneric),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              OutlinedButton(onPressed: onRetry, child: Text(l10n.commonRetry)),
            ],
          ),
        ),
      ),
      _ => const Center(child: CircularProgressIndicator()),
    };
  }
}

/// A course in a list: cover, title, professor, rating, price.
class CourseTile extends StatelessWidget {
  const CourseTile({
    super.key,
    required this.title,
    required this.professor,
    required this.coverUrl,
    required this.isFree,
    required this.price,
    required this.rating,
    required this.ratingCount,
    required this.onTap,
    this.compact = false,
  });

  final String title;
  final String professor;
  final String? coverUrl;
  final bool isFree;
  final ({int amountMinor, String currency})? price;
  final num? rating;
  final int ratingCount;
  final VoidCallback onTap;

  /// Narrow card for horizontal shelves; otherwise a full-width row.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final details = <Widget>[
      Text(title, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.titleSmall),
      const SizedBox(height: 2),
      Text(
        professor,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
      ),
      const SizedBox(height: 4),
      Rating(rating: rating, count: ratingCount),
      const SizedBox(height: 4),
      PriceTag(isFree: isFree, amountMinor: price?.amountMinor, currency: price?.currency),
    ];

    if (compact) {
      return SizedBox(
        width: 200,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [NetImage(coverUrl, width: 200, height: 112), const SizedBox(height: 8), ...details],
          ),
        ),
      );
    }
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            NetImage(coverUrl, width: 120, height: 68),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: details),
            ),
          ],
        ),
      ),
    );
  }
}
