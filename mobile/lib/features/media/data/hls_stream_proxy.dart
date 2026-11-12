import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../../../core/api/api_exception.dart';

/// Streams a lesson (PRD-07 FR-02) through the standard player without giving it our credentials.
///
/// The API's playlists and key need the device token and `X-Device-Id`; the segments are pre-signed R2 URLs
/// that must get none of our headers. A player can't tell them apart, so the app fetches the master and media
/// playlists and the key itself (with [api], which adds our headers) and serves them on loopback; the
/// segment URIs stay absolute and go straight to R2. The key is fetched once into memory and wiped on
/// [close]. Every URL carries a random per-playback token. Same accepted limit as offline playback.
class HlsStreamProxy {
  HlsStreamProxy._(this._server, this._api, this._masterUrl, this._token);

  static Future<HlsStreamProxy> start({required Dio api, required String masterUrl, Random? random}) async {
    final rng = random ?? Random.secure();
    final token = List.generate(24, (_) => rng.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final proxy = HlsStreamProxy._(server, api, Uri.parse(masterUrl), token);
    server.listen(proxy._handle);
    return proxy;
  }

  final HttpServer _server;
  final Dio _api;
  final Uri _masterUrl;
  final String _token;

  /// Variant playlists of the master, by index (filled when the master is served).
  final _variants = <Uri>[];
  Uri? _keyUrl;
  Uint8List? _key;

  /// Set when the API refused a playlist or the key (e.g. access ended, stream replaced).
  ApiException? lastError;

  String get _base => 'http://${InternetAddress.loopbackIPv4.address}:${_server.port}/$_token';

  /// What the player opens.
  Uri get playlistUri => Uri.parse('$_base/master.m3u8');

  static final _variant = RegExp(r'^v/(\d{1,3})\.m3u8$');

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

      if (path == 'master.m3u8') {
        response.headers.contentType = ContentType('application', 'vnd.apple.mpegurl');
        response.write(_rewriteMaster(await _text(_masterUrl), _masterUrl));
      } else if (_variant.firstMatch(path) case final match?) {
        final index = int.parse(match.group(1)!);
        if (index >= _variants.length) {
          response.statusCode = HttpStatus.notFound;
          return;
        }
        final url = _variants[index];
        response.headers.contentType = ContentType('application', 'vnd.apple.mpegurl');
        response.write(_rewriteMedia(await _text(url), url));
      } else if (path == 'key') {
        final key = await _fetchKey();
        response.headers.contentType = ContentType.binary;
        response.add(key);
      } else {
        response.statusCode = HttpStatus.notFound;
      }
    } on ApiException catch (e) {
      lastError = e;
      response.statusCode = HttpStatus.forbidden;
    } catch (_) {
      response.statusCode = HttpStatus.badGateway;
    } finally {
      await response.close();
    }
  }

  /// Our token goes only to our API's host: a playlist pointing a key elsewhere is refused.
  void _ensureApi(Uri url) {
    final api = Uri.parse(_api.options.baseUrl);
    if (url.scheme != api.scheme || url.host != api.host || url.port != api.port) {
      throw const ApiException(code: 'playback.foreign_url', status: 0);
    }
  }

  Future<String> _text(Uri url) async {
    _ensureApi(url);
    final response = await ApiException.guard(
      () => _api.getUri<String>(url, options: Options(responseType: ResponseType.plain)),
    );
    return response.data ?? '';
  }

  Future<Uint8List> _fetchKey() async {
    final cached = _key;
    if (cached != null) return cached;
    final url = _keyUrl;
    if (url == null) throw const ApiException(code: 'playback.no_key', status: 0);
    _ensureApi(url);
    final response = await ApiException.guard(
      () => _api.getUri<List<int>>(url, options: Options(responseType: ResponseType.bytes)),
    );
    return _key = Uint8List.fromList(response.data ?? const []);
  }

  /// Variant URIs → local `v/<n>.m3u8` (remembering where each really is).
  String _rewriteMaster(String playlist, Uri base) {
    _variants.clear();
    return _lines(playlist)
        .map((line) {
          if (line.isEmpty || line.startsWith('#')) return line;
          _variants.add(base.resolve(line));
          return 'v/${_variants.length - 1}.m3u8';
        })
        .join('\n');
  }

  /// The key URI → local `key`; segment URIs made absolute (they are signed storage URLs: fetched directly).
  String _rewriteMedia(String playlist, Uri base) => _lines(playlist)
      .map((line) {
        if (line.startsWith('#EXT-X-KEY')) {
          final uri = RegExp(r'URI="([^"]*)"').firstMatch(line)?.group(1);
          if (uri != null) _keyUrl = base.resolve(uri);
          return line.replaceFirst(RegExp(r'URI="[^"]*"'), 'URI="$_base/key"');
        }
        if (line.isEmpty || line.startsWith('#')) return line;
        return base.resolve(line).toString();
      })
      .join('\n');

  static Iterable<String> _lines(String playlist) => playlist.split(RegExp(r'\r\n|\n|\r')).map((l) => l.trim());

  /// Stops serving and wipes the key from memory.
  Future<void> close() async {
    _key?.fillRange(0, _key!.length, 0);
    _key = null;
    await _server.close(force: true);
  }
}
