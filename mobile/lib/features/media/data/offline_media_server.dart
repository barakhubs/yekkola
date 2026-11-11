import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'download_store.dart';
import 'hls_playlist.dart';

/// Feeds one downloaded lesson to the standard player (ExoPlayer / AVPlayer play AES-128 HLS themselves):
/// a loopback-only HTTP server that serves the local playlist, the encrypted segments from disk and the
/// unwrapped key from memory. The key never touches disk; every URL carries a random per-playback token,
/// and the server (with the key) is gone once the player closes (PRD-07 FR-16).
///
/// Accepted limit, like encrypted HLS on the web: while a lesson plays, code on the same phone that guesses
/// the token could read the key. The per-viewer overlay stays the deterrent (owner decision, 2026-11-09).
class OfflineMediaServer {
  OfflineMediaServer._(this._server, this._store, this._lessonUid, this._key, this._token, this._template);

  static Future<OfflineMediaServer> start({
    required DownloadStore store,
    required String lessonUid,
    required Uint8List key,
    Random? random,
  }) async {
    final template = await store.playlistFile(lessonUid).readAsString();
    final rng = random ?? Random.secure();
    final token = List.generate(24, (_) => rng.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final media = OfflineMediaServer._(server, store, lessonUid, key, token, template);
    server.listen(media._handle);
    return media;
  }

  final HttpServer _server;
  final DownloadStore _store;
  final String _lessonUid;
  final Uint8List _key;
  final String _token;
  final String _template;

  String get _base => 'http://${InternetAddress.loopbackIPv4.address}:${_server.port}/$_token';

  /// What the player opens.
  Uri get playlistUri => Uri.parse('$_base/index.m3u8');

  static final _segment = RegExp(r'^seg/(\d{1,6})\.ts$');

  Future<void> _handle(HttpRequest request) async {
    final response = request.response;
    response.headers.set(HttpHeaders.cacheControlHeader, 'no-store');
    try {
      final segments = request.uri.pathSegments;
      if (request.method != 'GET' || segments.isEmpty || segments.first != _token) {
        response.statusCode = HttpStatus.notFound;
        return;
      }
      final path = segments.skip(1).join('/');

      if (path == 'index.m3u8') {
        response.headers.contentType = ContentType('application', 'vnd.apple.mpegurl');
        response.write(OfflinePlaylist.render(_template, '$_base/key'));
      } else if (path == 'key') {
        response.headers.contentType = ContentType.binary;
        response.add(_key);
      } else if (_segment.firstMatch(path) case final match?) {
        final file = _store.segmentFile(_lessonUid, int.parse(match.group(1)!));
        if (!await file.exists()) {
          response.statusCode = HttpStatus.notFound;
          return;
        }
        response.headers.contentType = ContentType('video', 'mp2t');
        response.contentLength = await file.length();
        await response.addStream(file.openRead());
      } else {
        response.statusCode = HttpStatus.notFound;
      }
    } catch (_) {
      response.statusCode = HttpStatus.internalServerError;
    } finally {
      await response.close();
    }
  }

  /// Stops serving and wipes the key from memory.
  Future<void> close() async {
    _key.fillRange(0, _key.length, 0);
    await _server.close(force: true);
  }
}
