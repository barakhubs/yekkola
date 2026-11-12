import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yekkola/features/media/data/hls_stream_proxy.dart';

const _api = 'https://api.test/api/v1';
const _lesson = '01JLESSON00000000000000000';
final _key = Uint8List.fromList(List.generate(16, (i) => 0x30 + i));

/// The API: serves the master, the media playlist and the key, recording what our client sent.
class _Api implements HttpClientAdapter {
  _Api({this.keyUri = '$_api/lessons/$_lesson/hls/key', this.refuseKey = false, this.keyBytes});

  final String keyUri;
  final bool refuseKey;
  final List<int>? keyBytes;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? _, Future<void>? _) async {
    requests.add(options);
    final path = options.uri.path;
    if (path.endsWith('/master.m3u8')) {
      return ResponseBody.fromString(
        '#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=600000\n$_api/lessons/$_lesson/hls/360p.m3u8\n'
        '#EXT-X-STREAM-INF:BANDWIDTH=2000000\n720p.m3u8\n',
        200,
      );
    }
    if (path.endsWith('.m3u8')) {
      return ResponseBody.fromString(
        '#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI="$keyUri"\n#EXTINF:6,\nhttps://r2.test/seg_0.ts?X-Amz-Signature=abc\n#EXT-X-ENDLIST\n',
        200,
      );
    }
    if (path.endsWith('/key')) {
      if (refuseKey) {
        return ResponseBody.fromString(
          jsonEncode({'status': 403, 'code': 'enrollment.required', 'title': 'Inscription requise.'}),
          403,
          headers: {
            Headers.contentTypeHeader: ['application/json'],
          },
        );
      }
      await Future<void>.delayed(const Duration(milliseconds: 20));
      return ResponseBody.fromBytes(Uint8List.fromList(keyBytes ?? _key), 200);
    }
    return ResponseBody.fromString('', 404);
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late HttpClient client;
  setUp(() => client = HttpClient());
  tearDown(() => client.close(force: true));

  Future<(int, List<int>)> get(Uri uri) async {
    final response = await (await client.getUrl(uri)).close();
    return (response.statusCode, await response.expand((b) => b).toList());
  }

  (Dio, _Api) api({String? keyUri, bool refuseKey = false, List<int>? keyBytes}) {
    final adapter = _Api(keyUri: keyUri ?? '$_api/lessons/$_lesson/hls/key', refuseKey: refuseKey, keyBytes: keyBytes);
    final dio = Dio(BaseOptions(baseUrl: _api, headers: {'Authorization': 'Bearer t', 'X-Device-Id': 'install-1'}))
      ..httpClientAdapter = adapter;
    return (dio, adapter);
  }

  test('serves our playlists and key on loopback; segments stay signed storage URLs', () async {
    final (dio, adapter) = api();
    final proxy = await HlsStreamProxy.start(api: dio, masterUrl: '$_api/lessons/$_lesson/hls/master.m3u8');

    final master = utf8.decode((await get(proxy.playlistUri)).$2);
    expect(master, contains('v/0.m3u8'));
    expect(master, contains('v/1.m3u8'));
    expect(master, isNot(contains('api.test')));

    final media = utf8.decode((await get(proxy.playlistUri.resolve('v/1.m3u8'))).$2);
    expect(media, contains('URI="${proxy.playlistUri.resolve('key')}"'));
    expect(media, contains('https://r2.test/seg_0.ts?X-Amz-Signature=abc'));
    expect(media, isNot(contains('/hls/key')));

    expect((await get(proxy.playlistUri.resolve('key'))).$2, _key);
    expect((await get(proxy.playlistUri.resolve('key'))).$2, _key);
    // Fetched from the API once, with our credentials (the relative variant resolved against the master).
    expect(adapter.requests.where((r) => r.uri.path.endsWith('/key')), hasLength(1));
    expect(adapter.requests.map((r) => r.uri.toString()), contains('$_api/lessons/$_lesson/hls/720p.m3u8'));
    expect(adapter.requests.every((r) => r.headers['Authorization'] == 'Bearer t'), isTrue);

    expect((await get(proxy.playlistUri.replace(path: '/wrong/master.m3u8'))).$1, 404);
    await proxy.close();
  });

  test('never sends our token to another host, even if a playlist points the key there', () async {
    final (dio, adapter) = api(keyUri: 'https://evil.test/steal');
    final proxy = await HlsStreamProxy.start(api: dio, masterUrl: '$_api/lessons/$_lesson/hls/master.m3u8');

    await get(proxy.playlistUri);
    await get(proxy.playlistUri.resolve('v/0.m3u8'));
    final (status, _) = await get(proxy.playlistUri.resolve('key'));

    expect(status, isNot(200));
    expect(adapter.requests.any((r) => r.uri.host == 'evil.test'), isFalse);
    await proxy.close();
  });

  test("a refusal keeps the API's code for the student (access ended mid-lesson)", () async {
    final (dio, _) = api(refuseKey: true);
    final proxy = await HlsStreamProxy.start(api: dio, masterUrl: '$_api/lessons/$_lesson/hls/master.m3u8');
    await get(proxy.playlistUri);
    await get(proxy.playlistUri.resolve('v/0.m3u8'));

    expect((await get(proxy.playlistUri.resolve('key'))).$1, 403);
    expect(proxy.lastError?.code, 'enrollment.required');
    expect(proxy.lastError?.title, 'Inscription requise.');
    await proxy.close();
  });

  test('two players asking for the key together cause one fetch; a wrong-length key is refused', () async {
    final (dio, adapter) = api();
    final proxy = await HlsStreamProxy.start(api: dio, masterUrl: '$_api/lessons/$_lesson/hls/master.m3u8');
    await get(proxy.playlistUri);
    await get(proxy.playlistUri.resolve('v/0.m3u8'));
    final both = await Future.wait([get(proxy.playlistUri.resolve('key')), get(proxy.playlistUri.resolve('key'))]);
    expect(both.map((r) => r.$2), everyElement(_key));
    expect(adapter.requests.where((r) => r.uri.path.endsWith('/key')), hasLength(1));
    await proxy.close();

    final (short, _) = api(keyBytes: const [1, 2, 3]);
    final bad = await HlsStreamProxy.start(api: short, masterUrl: '$_api/lessons/$_lesson/hls/master.m3u8');
    await get(bad.playlistUri);
    await get(bad.playlistUri.resolve('v/0.m3u8'));
    expect((await get(bad.playlistUri.resolve('key'))).$1, isNot(200));
    await bad.close();
  });

  test('closing stops serving', () async {
    final (dio, _) = api();
    final proxy = await HlsStreamProxy.start(api: dio, masterUrl: '$_api/lessons/$_lesson/hls/master.m3u8');
    final uri = proxy.playlistUri;
    await proxy.close();
    await expectLater(get(uri), throwsA(isA<SocketException>()));
  });
}
