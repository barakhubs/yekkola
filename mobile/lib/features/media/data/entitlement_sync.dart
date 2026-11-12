import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../../../core/db/app_database.dart';
import 'download_store.dart';
import 'hls_downloader.dart';

class EntitlementSyncResult {
  const EntitlementSyncResult({this.deleted = const [], this.renewed = const [], this.skipped = false});

  final List<String> deleted;
  final List<String> renewed;

  /// Nothing to check (no downloads), or checked recently.
  final bool skipped;
}

/// Entitlement sync (PRD-07 FR-10, BR-02, BR-04): asks the API what to do with each of this account's
/// downloads and does it. The API decides; the app follows:
/// - `delete` (revoked, refund/takedown/no access, new media) and downloads the API doesn't list → deleted;
/// - `renew` (expired or past half the license) → a new offline license (fresh expiry, same files).
///
/// Runs when online: at app start, on reconnect and on resume, at most every [minInterval]; never when the
/// account has no downloads (no request at all).
class EntitlementSync {
  EntitlementSync({
    required this.api,
    required this.store,
    required this.downloader,
    required this.db,
    this.minInterval = const Duration(minutes: 30),
    bool Function(String lessonUid)? isDownloading,
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now,
       _isDownloading = isDownloading ?? ((_) => false);

  /// A lesson being downloaded right now is left for the next check (its files are still being written).
  final bool Function(String lessonUid) _isDownloading;

  final YekkolaApi api;
  final DownloadStore store;
  final HlsDownloader downloader;
  final AppDatabase db;
  final Duration minInterval;
  final DateTime Function() _clock;

  static String _checkedKey(String userId) => 'entitlements_checked_at:$userId';

  /// [force]: ignore [minInterval] (e.g. pull-to-refresh on the downloads screen).
  Future<EntitlementSyncResult> run(String userId, {bool force = false}) async {
    final mine = (await store.all()).where((d) => d.userId == userId && !_isDownloading(d.lessonUid)).toList();
    if (mine.isEmpty) return const EntitlementSyncResult(skipped: true);

    final now = _clock().toUtc();
    if (!force) {
      final last = await (db.select(db.syncState)..where((s) => s.key.equals(_checkedKey(userId)))).getSingleOrNull();
      final at = last == null ? null : DateTime.tryParse(last.value);
      if (at != null && now.difference(at) < minInterval) return const EntitlementSyncResult(skipped: true);
    }

    final response = await ApiException.guard(() => api.playback.meEntitlements());
    final byLesson = {for (final l in response.data.licenses) l.lessonUid: l};

    final deleted = <String>[];
    final renewed = <String>[];
    for (final download in mine) {
      final entitlement = byLesson[download.lessonUid];
      // Not listed, or another license than the one we hold: the API no longer knows this download.
      final unknown = entitlement == null || entitlement.licenseId != download.licenseId;
      if (unknown || entitlement.action == 'delete') {
        await store.delete(download.lessonUid);
        deleted.add(download.lessonUid);
      } else if (entitlement.action == 'renew') {
        try {
          await downloader.renew(download);
          renewed.add(download.lessonUid);
        } on MediaChanged {
          deleted.add(download.lessonUid);
        } on ApiException catch (e) {
          // Access ended between the two calls: the next sync deletes it. Network trouble: try next time.
          if (e.isNetwork) rethrow;
        }
      }
    }

    await db
        .into(db.syncState)
        .insertOnConflictUpdate(SyncStateCompanion.insert(key: _checkedKey(userId), value: now.toIso8601String()));
    return EntitlementSyncResult(deleted: deleted, renewed: renewed);
  }
}
