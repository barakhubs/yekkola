import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:drift/drift.dart' hide isNotNull, isNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/api/api_exception.dart';
import 'package:yekkola/core/db/app_database.dart';
import 'package:yekkola/core/providers.dart';
import 'package:yekkola/core/storage/secure_store.dart';
import 'package:yekkola/features/auth/domain/app_user.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/media/data/download_store.dart';
import 'package:yekkola/features/media/data/entitlement_sync.dart';
import 'package:yekkola/features/media/data/hls_downloader.dart';
import 'package:yekkola/features/media/data/offline_key_vault.dart';
import 'package:yekkola/features/media/domain/offline_download.dart';

import '../../support/fake_api.dart';

const _keep = '01JLESSONKEEP0000000000000';
const _renew = '01JLESSONRENEW000000000000';
const _gone = '01JLESSONGONE000000000000A';
const _unlisted = '01JLESSONUNLISTED000000000';
const _media = '01JMEDIA000000000000000000';

Map<String, Object?> _entitlement(String uid, String action, {String? reason}) => {
  'license_id': 'license-$uid',
  'lesson_uid': uid,
  'media_id': _media,
  'current_media_id': _media,
  'rendition': '360p',
  'expires_at': '2026-11-20T10:00:00Z',
  'action': action,
  'reason': reason,
};

FakeResponse _renewal(String uid) => FakeResponse(200, {
  'data': {
    'license_id': 'license-$uid',
    'lesson_uid': uid,
    'media_id': _media,
    'kind': 'video',
    'rendition': {'name': '360p', 'size_bytes': 1000, 'width': 640, 'height': 360},
    'renditions': <Object>[],
    'playlist': '#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI="k"\n#EXTINF:6,\nhttps://media.test/s0.ts\n#EXT-X-ENDLIST',
    'segments_expire_at': '2026-11-12T13:00:00Z',
    'key': base64Encode(List.filled(16, 7)),
    'expires_at': '2026-11-26T10:00:00Z',
    'watermark': {'text': 'Amani K.'},
  },
});

void main() {
  late Directory root;
  late DownloadStore store;
  late AppDatabase db;
  late DateTime now;

  setUp(() async {
    root = await Directory.systemTemp.createTemp('yekkola_entitlements_');
    store = DownloadStore(root);
    db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
    now = DateTime.utc(2026, 11, 12, 10);
  });

  tearDown(() async {
    await db.close();
    await root.delete(recursive: true);
  });

  Future<void> seed(String uid, {String userId = 'user-a'}) async {
    await store.save(
      OfflineDownload(
        userId: userId,
        lessonUid: uid,
        mediaId: _media,
        licenseId: 'license-$uid',
        kind: 'video',
        rendition: '360p',
        sizeBytes: 1000,
        segmentCount: 1,
        expiresAt: DateTime.utc(2026, 11, 20, 10),
        wrappedKey: const WrappedKey(nonce: [], cipherText: [], mac: []),
        watermark: 'w',
        completedSegments: 1,
      ),
    );
    await store.savePlaylist(uid, '#EXTM3U\n');
  }

  (EntitlementSync, FakeApi) subject(FakeResponse Function(RequestOptions request) answer) {
    final api = FakeApi((request, _) => answer(request));
    final container = makeContainer(
      api,
      initial: SignedIn(user: AppUser.fromJson(userJson()), token: 't'),
    );
    final yekkola = container.read(yekkolaApiProvider);
    return (
      EntitlementSync(
        api: yekkola,
        store: store,
        downloader: HlsDownloader(
          api: yekkola,
          segments: Dio(),
          store: store,
          vault: OfflineKeyVault(MemorySecureStore()),
        ),
        db: db,
        clock: () => now,
      ),
      api,
    );
  }

  FakeResponse entitlements(RequestOptions request) {
    if (request.path == '/me/entitlements') {
      return FakeResponse(200, {
        'data': {
          'checked_at': '2026-11-12T10:00:00Z',
          'licenses': [
            _entitlement(_keep, 'keep'),
            _entitlement(_renew, 'renew', reason: 'expiring'),
            _entitlement(_gone, 'delete', reason: 'no_access'),
          ],
        },
      });
    }
    return _renewal(request.path.split('/')[2]);
  }

  test('deletes what the API says (and what it no longer lists), renews what it asks (PRD-07 FR-10)', () async {
    for (final uid in [_keep, _renew, _gone, _unlisted]) {
      await seed(uid);
    }
    final (sync, api) = subject(entitlements);

    final result = await sync.run('user-a');

    expect(result.deleted, unorderedEquals([_gone, _unlisted]));
    expect(result.renewed, [_renew]);
    expect(await store.read(_gone), isNull);
    expect(await store.read(_unlisted), isNull);
    expect((await store.read(_keep))!.expiresAt, DateTime.utc(2026, 11, 20, 10));
    expect((await store.read(_renew))!.expiresAt, DateTime.utc(2026, 11, 26, 10));
    expect(api.requests.map((r) => r.path), ['/me/entitlements', '/lessons/$_renew/offline-license']);
  });

  test("leaves other accounts' downloads alone, and asks nothing when this account has none", () async {
    await seed(_gone, userId: 'user-b');
    final (sync, api) = subject(entitlements);

    final result = await sync.run('user-a');

    expect(result.skipped, isTrue);
    expect(api.requests, isEmpty);
    expect(await store.read(_gone), isNotNull);
  });

  test('checks at most every 30 minutes unless forced; offline changes nothing', () async {
    await seed(_keep);
    final (offline, _) = subject((_) => throw StateError('no network'));
    await expectLater(offline.run('user-a'), throwsA(isA<ApiException>()));
    expect(await store.read(_keep), isNotNull);

    final (sync, api) = subject(entitlements);
    await sync.run('user-a');
    now = now.add(const Duration(minutes: 10));
    expect((await sync.run('user-a')).skipped, isTrue);
    await sync.run('user-a', force: true);
    now = now.add(const Duration(minutes: 31));
    await sync.run('user-a');

    expect(api.requests.where((r) => r.path == '/me/entitlements'), hasLength(3));
  });
}
