import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/core/providers.dart';
import 'package:yekkola/core/storage/secure_store.dart';
import 'package:yekkola/features/auth/domain/app_user.dart';
import 'package:yekkola/features/auth/domain/auth_state.dart';
import 'package:yekkola/features/media/data/download_store.dart';
import 'package:yekkola/features/media/data/hls_downloader.dart';
import 'package:yekkola/features/media/data/hls_playlist.dart';
import 'package:yekkola/features/media/data/offline_key_vault.dart';
import 'package:yekkola/features/media/data/offline_media_server.dart';
import 'package:yekkola/features/media/domain/offline_download.dart';

import '../../support/fake_api.dart';

const _lesson = '01JLESSON00000000000000000';
final _key = Uint8List.fromList(List.generate(16, (i) => 0xA0 + i));

String _playlist(String sig, {int segments = 3}) => [
  '#EXTM3U',
  '#EXT-X-VERSION:3',
  '#EXT-X-TARGETDURATION:6',
  '#EXT-X-PLAYLIST-TYPE:VOD',
  '#EXT-X-KEY:METHOD=AES-128,URI="https://api.test/api/v1/lessons/$_lesson/hls/key",IV=0x0123456789abcdef0123456789abcdef',
  for (var i = 0; i < segments; i++) ...['#EXTINF:6.000000,', 'https://media.test/seg_$i.ts?sig=$sig'],
  '#EXT-X-ENDLIST',
].join('\n');

FakeResponse _license({String sig = 'one', String mediaId = '01JMEDIA000000000000000000', int segments = 3}) =>
    FakeResponse(200, {
      'data': {
        'license_id': '01JLICENSE0000000000000000',
        'lesson_uid': _lesson,
        'media_id': mediaId,
        'kind': 'video',
        'rendition': {'name': '360p', 'size_bytes': 80000000, 'width': 640, 'height': 360},
        'renditions': [
          {'name': '360p', 'size_bytes': 80000000, 'width': 640, 'height': 360},
        ],
        'playlist': _playlist(sig, segments: segments),
        'segments_expire_at': '2026-11-11T16:00:00Z',
        'key': base64Encode(_key),
        'expires_at': '2026-11-25T10:00:00Z',
        'watermark': {'text': 'Amani K. · +243 8•• ••• 678'},
      },
    });

/// Storage host: serves fake encrypted segment bytes; can refuse expired signatures.
class _Segments implements HttpClientAdapter {
  _Segments({this.refuseSig, this.truncateOnce = false});

  final String? refuseSig;
  bool truncateOnce;
  final fetched = <String>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? _, Future<void>? _) async {
    fetched.add(options.uri.toString());
    if (refuseSig != null && options.uri.queryParameters['sig'] == refuseSig) {
      return ResponseBody.fromString('expired', 403);
    }
    // Headers of ours must never reach the storage host.
    expect(options.headers.keys.map((k) => k.toLowerCase()), isNot(contains('authorization')));
    expect(options.headers.keys.map((k) => k.toLowerCase()), isNot(contains('x-device-id')));
    final body = utf8.encode('ENCRYPTED:${options.uri.pathSegments.last}');
    if (truncateOnce) {
      // Announces the full length but the link drops halfway.
      truncateOnce = false;
      return ResponseBody.fromBytes(
        body.sublist(0, 4),
        200,
        headers: {
          Headers.contentLengthHeader: ['${body.length}'],
        },
      );
    }
    return ResponseBody.fromBytes(
      body,
      200,
      headers: {
        Headers.contentLengthHeader: ['${body.length}'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late Directory root;
  late DownloadStore store;
  late OfflineKeyVault vault;

  setUp(() async {
    root = await Directory.systemTemp.createTemp('yekkola_downloads_');
    store = DownloadStore(root);
    vault = OfflineKeyVault(MemorySecureStore());
  });

  tearDown(() => root.delete(recursive: true));

  (HlsDownloader, FakeApi, _Segments) downloader(
    FakeResponse Function(int call) license, {
    String? refuseSig,
    bool truncateOnce = false,
  }) {
    var calls = 0;
    final api = FakeApi((request, _) => license(calls++));
    final container = makeContainer(
      api,
      initial: SignedIn(user: AppUser.fromJson(userJson()), token: 't'),
    );
    final segments = _Segments(refuseSig: refuseSig, truncateOnce: truncateOnce);
    final dio = Dio()..httpClientAdapter = segments;
    return (
      HlsDownloader(api: container.read(yekkolaApiProvider), segments: dio, store: store, vault: vault),
      api,
      segments,
    );
  }

  group('OfflinePlaylist', () {
    test('keeps tags, maps segments to local files and hides the key URI', () {
      final playlist = OfflinePlaylist.parse(_playlist('one'));

      expect(playlist.segmentUrls, [
        'https://media.test/seg_0.ts?sig=one',
        'https://media.test/seg_1.ts?sig=one',
        'https://media.test/seg_2.ts?sig=one',
      ]);
      expect(playlist.template, contains('seg/0.ts'));
      expect(playlist.template, contains('URI="${OfflinePlaylist.keyPlaceholder}",IV=0x0123456789abcdef'));
      expect(playlist.template, isNot(contains('api.test')));
      expect(playlist.template, isNot(contains('sig=')));
      expect(
        OfflinePlaylist.render(playlist.template, 'http://127.0.0.1:1/t/key'),
        contains('URI="http://127.0.0.1:1/t/key"'),
      );
    });

    test('refuses what it cannot keep offline', () {
      expect(() => OfflinePlaylist.parse('#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nlow.m3u8'), throwsFormatException);
      expect(() => OfflinePlaylist.parse('#EXTM3U\n#EXTINF:6,\nseg.ts'), throwsFormatException); // no key
      expect(
        () => OfflinePlaylist.parse('#EXTM3U\n#EXT-X-KEY:METHOD=SAMPLE-AES,URI="k"\n#EXTINF:6,\nseg.ts'),
        throwsFormatException,
      );
    });
  });

  group('OfflineKeyVault', () {
    test('wraps for this device and binding only', () async {
      final wrapped = await vault.wrap(_key, boundTo: 'license|lesson|media|2026-11-25');
      expect(await vault.unwrap(wrapped, boundTo: 'license|lesson|media|2026-11-25'), _key);

      // A longer expiry written into the manifest breaks the key.
      await expectLater(
        vault.unwrap(wrapped, boundTo: 'license|lesson|media|2027-01-01'),
        throwsA(isA<KeyUnwrapFailed>()),
      );
      // Two first wraps at once share one wrapping key.
      final fresh = OfflineKeyVault(MemorySecureStore());
      final both = await Future.wait([fresh.wrap(_key, boundTo: 'a'), fresh.wrap(_key, boundTo: 'b')]);
      expect(await fresh.unwrap(both[0], boundTo: 'a'), _key);
      expect(await fresh.unwrap(both[1], boundTo: 'b'), _key);

      // Another install (another wrapping key) can't use a copied download.
      final other = OfflineKeyVault(MemorySecureStore());
      await expectLater(
        other.unwrap(wrapped, boundTo: 'license|lesson|media|2026-11-25'),
        throwsA(isA<KeyUnwrapFailed>()),
      );
    });
  });

  group('HlsDownloader', () {
    test('downloads the encrypted segments, keeps the key only wrapped', () async {
      final (subject, api, segments) = downloader((_) => _license());

      final download = await subject.download(_lesson, userId: 'user-a');

      expect(download.isComplete, isTrue);
      expect(segments.fetched, hasLength(3));
      expect(api.requests.single.path, '/lessons/$_lesson/offline-license');
      expect(await store.segmentFile(_lesson, 2).readAsString(), 'ENCRYPTED:seg_2.ts');
      expect(await vault.unwrap(download.wrappedKey, boundTo: download.keyBinding), _key);

      // The plain key is nowhere on disk, in any form.
      final keyB64 = base64Encode(_key);
      await for (final file in root.list(recursive: true).where((e) => e is File).cast<File>()) {
        final bytes = await file.readAsBytes();
        expect(_contains(bytes, _key), isFalse, reason: file.path);
        expect(utf8.decode(bytes, allowMalformed: true), isNot(contains(keyB64)), reason: file.path);
      }
    });

    test('resumes where it stopped', () async {
      final (first, _, _) = downloader((_) => _license());
      await first.download(_lesson, userId: 'user-a');
      await store.segmentFile(_lesson, 2).delete();

      final (again, _, segments) = downloader((_) => _license());
      await again.download(_lesson, userId: 'user-a');

      expect(segments.fetched, ['https://media.test/seg_2.ts?sig=one']);
    });

    test('renews the license when the signed segment URLs ran out', () async {
      final (subject, api, segments) = downloader(
        (call) => call == 0 ? _license(sig: 'old') : _license(sig: 'fresh'),
        refuseSig: 'old',
      );

      final download = await subject.download(_lesson, userId: 'user-a');

      expect(download.isComplete, isTrue);
      expect(api.requests, hasLength(2));
      expect(segments.fetched.where((u) => u.contains('sig=fresh')), hasLength(3));
    });

    test('media replaced in the middle of a download restarts it from the first segment', () async {
      final (subject, _, segments) = downloader(
        (call) => call == 0 ? _license(sig: 'old') : _license(sig: 'fresh', mediaId: '01JMEDIANEW000000000000000'),
        refuseSig: 'old',
      );

      final download = await subject.download(_lesson, userId: 'user-a');

      expect(download.mediaId, '01JMEDIANEW000000000000000');
      expect(download.isComplete, isTrue);
      for (var i = 0; i < 3; i++) {
        expect(await store.segmentFile(_lesson, i).exists(), isTrue, reason: 'segment $i');
      }
      expect(segments.fetched.where((u) => u.contains('sig=fresh')), hasLength(3));
    });

    test('a segment cut short is not kept', () async {
      final (subject, _, _) = downloader((_) => _license(), truncateOnce: true);

      await expectLater(subject.download(_lesson, userId: 'user-a'), throwsA(isA<DioException>()));
      expect(await store.segmentFile(_lesson, 0).exists(), isFalse);

      final (again, _, _) = downloader((_) => _license());
      expect((await again.download(_lesson, userId: 'user-a')).isComplete, isTrue);
      expect(await store.segmentFile(_lesson, 0).readAsString(), 'ENCRYPTED:seg_0.ts');
    });

    test('another account on the phone starts its own download', () async {
      final (first, _, _) = downloader((_) => _license());
      await first.download(_lesson, userId: 'user-a');

      final (other, _, segments) = downloader((_) => _license());
      final download = await other.download(_lesson, userId: 'user-b');

      expect(download.userId, 'user-b');
      expect(segments.fetched, hasLength(3), reason: "user A's files are not reused");
    });

    test('new media for the lesson starts over', () async {
      final (first, _, _) = downloader((_) => _license());
      await first.download(_lesson, userId: 'user-a');

      final (again, _, segments) = downloader((_) => _license(mediaId: '01JMEDIANEW000000000000000', segments: 2));
      final download = await again.download(_lesson, userId: 'user-a');

      expect(download.mediaId, '01JMEDIANEW000000000000000');
      expect(segments.fetched, hasLength(2));
      expect(await store.segmentFile(_lesson, 2).exists(), isFalse);
    });
  });

  group('OfflineDownload', () {
    OfflineDownload sample({DateTime? lastOpenedAt}) => OfflineDownload(
      userId: 'user-a',
      lessonUid: _lesson,
      mediaId: 'm',
      licenseId: 'l',
      kind: 'video',
      rendition: '360p',
      sizeBytes: 1,
      segmentCount: 1,
      expiresAt: DateTime.utc(2026, 11, 25, 10),
      wrappedKey: const WrappedKey(nonce: [], cipherText: [], mac: []),
      watermark: 'w',
      completedSegments: 1,
      lastOpenedAt: lastOpenedAt,
    );

    test('plays until the license expiry, and not after the clock is set back (PRD-07 FR-11)', () {
      expect(sample().isUsableAt(DateTime.utc(2026, 11, 25, 9)), isTrue);
      expect(sample().isUsableAt(DateTime.utc(2026, 11, 25, 10)), isFalse);

      final opened = sample(lastOpenedAt: DateTime.utc(2026, 11, 20));
      expect(opened.isUsableAt(DateTime.utc(2026, 11, 21)), isTrue);
      expect(opened.isUsableAt(DateTime.utc(2026, 11, 10)), isFalse);

      // Playing with the clock a little behind never moves the guard back.
      expect(opened.openedAt(DateTime.utc(2026, 11, 19, 23, 58)).lastOpenedAt, DateTime.utc(2026, 11, 20));
      expect(opened.openedAt(DateTime.utc(2026, 11, 22)).lastOpenedAt, DateTime.utc(2026, 11, 22));
    });
  });

  group('OfflineMediaServer', () {
    test('serves the playlist, segments and key on loopback, only with its token', () async {
      final (subject, _, _) = downloader((_) => _license());
      await subject.download(_lesson, userId: 'user-a');
      final key = Uint8List.fromList(_key);

      final server = await OfflineMediaServer.start(store: store, lessonUid: _lesson, key: key);
      final client = HttpClient();
      Future<(int, List<int>)> get(Uri uri) async {
        final response = await (await client.getUrl(uri)).close();
        return (response.statusCode, await response.expand((b) => b).toList());
      }

      final uri = server.playlistUri;
      expect(uri.host, '127.0.0.1');
      final (status, body) = await get(uri);
      expect(status, 200);
      final playlist = utf8.decode(body);
      expect(playlist, contains('URI="${uri.resolve('key')}"'));
      expect(playlist, contains('seg/0.ts'));

      expect((await get(uri.resolve('key'))).$2, _key);
      expect(utf8.decode((await get(uri.resolve('seg/1.ts'))).$2), 'ENCRYPTED:seg_1.ts');
      expect((await get(uri.replace(path: '/wrong-token/key'))).$1, 404);
      expect((await get(uri.resolve('seg/9.ts'))).$1, 404);

      await server.close();
      client.close(force: true);
      expect(key.every((b) => b == 0), isTrue, reason: 'the key is wiped when playback ends');
    });
  });
}

bool _contains(List<int> haystack, List<int> needle) {
  for (var i = 0; i + needle.length <= haystack.length; i++) {
    var match = true;
    for (var j = 0; j < needle.length; j++) {
      if (haystack[i + j] != needle[j]) {
        match = false;
        break;
      }
    }
    if (match) return true;
  }
  return false;
}
