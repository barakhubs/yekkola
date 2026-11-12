import 'dart:async';
import 'dart:convert';

import 'package:drift/drift.dart';

import '../api/api_exception.dart';
import '../db/app_database.dart';

/// A cached API answer and how fresh it is.
class Cached<T> {
  const Cached(this.data, {required this.fetchedAt, this.refreshError});

  final T data;
  final DateTime fetchedAt;

  /// The last refresh failed (offline, server error): the screen shows the copy with a notice.
  final ApiException? refreshError;

  bool get isOffline => refreshError?.isNetwork ?? false;

  Cached<T> withError(ApiException? error) => Cached(data, fetchedAt: fetchedAt, refreshError: error);
}

/// Offline copies of read-only API answers (docs/architecture.md §6): screens read the copy at once and the
/// network refreshes it ("stale while revalidate"). Every screen therefore has an offline state: the last
/// copy, or a clear message when there is none. Bounded: copies older than [maxAge] or beyond [maxEntries]
/// (least recently read first) are dropped.
class ResponseCache {
  ResponseCache(
    this._db, {
    this.maxEntries = 300,
    this.maxAge = const Duration(days: 30),
    this.freshFor = const Duration(minutes: 10),
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  final AppDatabase _db;
  final int maxEntries;
  final Duration maxAge;

  /// Younger copies are not refreshed again (saves data on quick back-and-forth).
  final Duration freshFor;
  final DateTime Function() _clock;

  Future<CachedResponseRow?> read(String key) async {
    try {
      final row = await (_db.select(_db.cachedResponses)..where((c) => c.key.equals(key))).getSingleOrNull();
      if (row != null) {
        await (_db.update(
          _db.cachedResponses,
        )..where((c) => c.key.equals(key))).write(CachedResponsesCompanion(usedAt: Value(_clock().toUtc())));
      }
      return row;
    } catch (_) {
      return null; // storage trouble: behave as if nothing was saved
    }
  }

  Future<void> write(String key, Map<String, Object?> body) async {
    final now = _clock().toUtc();
    await _db
        .into(_db.cachedResponses)
        .insertOnConflictUpdate(
          CachedResponsesCompanion.insert(key: key, body: jsonEncode(body), fetchedAt: now, usedAt: now),
        );
    await prune();
  }

  Future<void> prune() async {
    final now = _clock().toUtc();
    await (_db.delete(_db.cachedResponses)..where((c) => c.fetchedAt.isSmallerThanValue(now.subtract(maxAge)))).go();
    final keep = _db.selectOnly(_db.cachedResponses)
      ..addColumns([_db.cachedResponses.key])
      ..orderBy([OrderingTerm.desc(_db.cachedResponses.usedAt)])
      ..limit(maxEntries);
    await (_db.delete(_db.cachedResponses)..where((c) => c.key.isNotInQuery(keep))).go();
  }

  /// The copy (if any) first, then the refreshed answer. Never throws: a failed refresh with no copy is
  /// emitted as an error ([ApiException]); with a copy, as the copy carrying [Cached.refreshError].
  Stream<Cached<T>> watch<T>(
    String key, {
    required Future<Map<String, Object?>> Function() fetch,
    required T Function(Map<String, Object?> json) parse,
    bool force = false,
  }) async* {
    Cached<T>? copy;
    final row = await read(key);
    if (row != null) {
      try {
        copy = Cached(parse((jsonDecode(row.body) as Map).cast()), fetchedAt: row.fetchedAt);
        yield copy;
      } catch (_) {
        copy = null; // an old shape the app can't read any more: refetch
      }
    }
    if (!force && copy != null && _clock().toUtc().difference(copy.fetchedAt) < freshFor) return;

    final Map<String, Object?> body;
    final Cached<T> fresh;
    try {
      body = await fetch();
      fresh = Cached(parse(body), fetchedAt: _clock().toUtc());
    } catch (e) {
      // A reply we can't read counts as a failed refresh too.
      final error = e is ApiException ? e : const ApiException(code: 'cache.unreadable', status: 0);
      if (error.status == 404 || error.status == 410) {
        // Gone (taken down, unpublished): never keep showing the old copy.
        await _forget(key);
        yield* Stream.error(error);
      } else if (copy == null) {
        yield* Stream.error(error);
      } else {
        yield copy.withError(error);
      }
      return;
    }

    // Show the answer even if it can't be saved (e.g. a full phone).
    yield fresh;
    try {
      await write(key, body);
    } catch (_) {}
  }

  Future<void> _forget(String key) async {
    try {
      await (_db.delete(_db.cachedResponses)..where((c) => c.key.equals(key))).go();
    } catch (_) {}
  }
}
