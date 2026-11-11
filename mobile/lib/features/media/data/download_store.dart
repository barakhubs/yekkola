import 'dart:convert';
import 'dart:io';

import '../domain/offline_download.dart';

/// Where downloads live: app-private storage (not backed up, not visible to other apps), one folder per
/// lesson: `manifest.json`, `playlist.m3u8` (the local template, no key) and `seg/<n>.ts` — the segments
/// exactly as served, still AES-128 encrypted. Plain media is never written.
class DownloadStore {
  DownloadStore(this.root);

  final Directory root;

  static final _uid = RegExp(r'^[0-9A-Za-z]{10,40}$');

  Directory lessonDir(String lessonUid) {
    if (!_uid.hasMatch(lessonUid)) throw ArgumentError.value(lessonUid, 'lessonUid');
    return Directory('${root.path}${Platform.pathSeparator}$lessonUid');
  }

  File manifestFile(String lessonUid) => File('${lessonDir(lessonUid).path}/manifest.json');
  File playlistFile(String lessonUid) => File('${lessonDir(lessonUid).path}/playlist.m3u8');
  File segmentFile(String lessonUid, int index) => File('${lessonDir(lessonUid).path}/seg/$index.ts');

  Future<OfflineDownload?> read(String lessonUid) async {
    final file = manifestFile(lessonUid);
    if (!await file.exists()) return null;
    try {
      return OfflineDownload.fromJson((jsonDecode(await file.readAsString()) as Map).cast<String, Object?>());
    } catch (_) {
      return null; // unreadable: treated as not downloaded
    }
  }

  Future<List<OfflineDownload>> all() async {
    if (!await root.exists()) return const [];
    final found = <OfflineDownload>[];
    await for (final entry in root.list()) {
      if (entry is! Directory) continue;
      final download = await read(entry.uri.pathSegments.where((s) => s.isNotEmpty).last);
      if (download != null) found.add(download);
    }
    return found;
  }

  /// Written to a temporary file then renamed, so a crash never leaves half a manifest.
  Future<void> save(OfflineDownload download) async {
    final file = manifestFile(download.lessonUid);
    await file.parent.create(recursive: true);
    final tmp = File('${file.path}.tmp');
    await tmp.writeAsString(jsonEncode(download.toJson()), flush: true);
    await tmp.rename(file.path);
  }

  Future<void> savePlaylist(String lessonUid, String template) async {
    final file = playlistFile(lessonUid);
    await file.parent.create(recursive: true);
    await file.writeAsString(template, flush: true);
  }

  Future<int> sizeOnDisk(String lessonUid) async {
    final dir = lessonDir(lessonUid);
    if (!await dir.exists()) return 0;
    var total = 0;
    await for (final entry in dir.list(recursive: true)) {
      if (entry is File) total += await entry.length();
    }
    return total;
  }

  /// Everything (the session ended: device removed, phone changed, account banned — PRD-07 BR-04).
  Future<void> deleteAll() async {
    if (await root.exists()) await root.delete(recursive: true);
  }

  Future<void> delete(String lessonUid) async {
    final dir = lessonDir(lessonUid);
    if (await dir.exists()) await dir.delete(recursive: true);
  }
}
