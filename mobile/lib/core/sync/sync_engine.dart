import 'dart:async';
import 'dart:math';

import '../api/api_exception.dart';
import '../db/app_database.dart';
import 'outbox.dart';

/// What happened to each row of a batch the API answered.
class OutboxResult {
  const OutboxResult({this.sent = const [], this.dropped = const []});

  /// Accepted: removed from the outbox.
  final List<OutboxRow> sent;

  /// Refused for good (e.g. a lesson no longer accessible): removed, never retried.
  final List<OutboxRow> dropped;
}

/// Sends one kind of outbox row (e.g. progress) in batches.
abstract interface class OutboxHandler {
  String get kind;

  /// Largest batch the endpoint takes.
  int get batchSize;

  /// Throws [ApiException] when the batch as a whole failed.
  Future<OutboxResult> send(List<OutboxRow> rows);
}

enum SyncOutcome { idle, synced, offline, failed }

/// Flushes the outbox (docs/architecture.md §6): batched per kind, one request per batch, exponential backoff
/// with jitter on failures. Network trouble stops the run (everything waits for the next trigger); a batch
/// the API refuses as invalid (4xx) is dropped so one bad row can't block the queue forever. Runs are never
/// concurrent. The account is read at run time: only its rows go out.
class SyncEngine {
  SyncEngine({required this.outbox, required List<OutboxHandler> handlers, required this.currentUserId, Random? random})
    : _handlers = {for (final h in handlers) h.kind: h},
      _random = random ?? Random();

  final OutboxStore outbox;
  final Map<String, OutboxHandler> _handlers;
  final String? Function() currentUserId;
  final Random _random;

  Future<SyncOutcome>? _running;
  bool _again = false;

  static const _maxDelay = Duration(minutes: 15);

  /// 5 s, 10 s, 20 s, … up to 15 min, with ±20 % jitter so phones don't retry in lockstep.
  Duration backoff(int attempts) {
    final base = Duration(seconds: 5 * pow(2, max(0, attempts - 1)).toInt());
    final capped = base > _maxDelay ? _maxDelay : base;
    final jitter = 0.8 + _random.nextDouble() * 0.4;
    return Duration(milliseconds: (capped.inMilliseconds * jitter).round());
  }

  /// Sends what is due. Called again while running, it runs once more afterwards (nothing is missed).
  Future<SyncOutcome> flush() {
    if (_running != null) {
      _again = true;
      return _running!;
    }
    return _running = _run().whenComplete(() {
      _running = null;
      if (_again) {
        _again = false;
        unawaited(flush());
      }
    });
  }

  Future<SyncOutcome> _run() async {
    final userId = currentUserId();
    if (userId == null) return SyncOutcome.idle;

    final due = await outbox.due(userId);
    if (due.isEmpty) return SyncOutcome.idle;

    var outcome = SyncOutcome.synced;
    final byKind = <String, List<OutboxRow>>{};
    for (final row in due) {
      byKind.putIfAbsent(row.kind, () => []).add(row);
    }

    for (final MapEntry(key: kind, value: rows) in byKind.entries) {
      final handler = _handlers[kind];
      if (handler == null) continue; // written by a newer app version; left for it

      for (var i = 0; i < rows.length; i += handler.batchSize) {
        final batch = rows.sublist(i, min(i + handler.batchSize, rows.length));
        try {
          final result = await handler.send(batch);
          await outbox.remove([...result.sent, ...result.dropped]);
          final answered = {...result.sent.map((r) => r.id), ...result.dropped.map((r) => r.id)};
          final rest = batch.where((r) => !answered.contains(r.id)).toList();
          if (rest.isNotEmpty) await outbox.retryLater(rest, backoff, 'unanswered');
        } on ApiException catch (e) {
          if (e.isNetwork) {
            await outbox.retryLater(rows.sublist(i), backoff, e.code);
            return SyncOutcome.offline;
          }
          if (e.status == 401 || e.status == 403) {
            // Session over or account blocked: keep the rows for when this account is back.
            await outbox.retryLater(rows.sublist(i), backoff, e.code);
            return SyncOutcome.failed;
          }
          if (e.status >= 400 && e.status < 500 && e.status != 408 && e.status != 429) {
            await outbox.remove(batch);
          } else {
            await outbox.retryLater(batch, backoff, e.code);
          }
          outcome = SyncOutcome.failed;
        }
      }
    }
    return outcome;
  }
}
