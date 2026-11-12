import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/generated/yekkola_api.dart';
import '../domain/offline_download.dart';
import 'download_store.dart';
import 'hls_playlist.dart';
import 'offline_key_vault.dart';

/// The lesson's media was replaced since it was downloaded: the download was dropped.
class MediaChanged implements Exception {
  const MediaChanged();
}

class DownloadProgress {
  const DownloadProgress({required this.completedSegments, required this.segmentCount, required this.sizeBytes});

  final int completedSegments;
  final int segmentCount;
  final int sizeBytes;

  double get fraction => segmentCount == 0 ? 0 : completedSegments / segmentCount;
}

/// Our HLS downloader (PRD-07 FR-09, FR-16): asks the API for an offline license, keeps the key wrapped,
/// then fetches the rendition's encrypted segments one by one into app-private storage. Resumable: finished
/// segments are kept (each is written to `.part` then renamed), so calling [download] again continues where
/// it stopped. Signed segment URLs that ran out are refreshed by renewing the license.
///
/// Segments come straight from storage with a separate Dio that carries none of our headers: the API token
/// and install ID must never reach the storage host.
class HlsDownloader {
  HlsDownloader({required this.api, required this.segments, required this.store, required this.vault});

  final YekkolaApi api;

  /// Plain Dio for the signed storage URLs: no interceptors, no headers of ours.
  final Dio segments;
  final DownloadStore store;
  final OfflineKeyVault vault;

  /// Downloads (or resumes) [lessonUid] for [userId] in [rendition] (null: our lowest, PRD-07 FR-08).
  Future<OfflineDownload> download(
    String lessonUid, {
    required String userId,
    String? rendition,
    CancelToken? cancel,
    void Function(DownloadProgress progress)? onProgress,
  }) async {
    var (download, urls, _) = await _license(lessonUid, userId, rendition);

    var i = 0;
    while (i < urls.length) {
      final file = store.segmentFile(lessonUid, i);
      if (!await file.exists()) {
        try {
          await _fetch(urls[i], file, cancel);
        } on DioException catch (e) {
          // An expired or refused signed URL: renew (fresh URLs, same key) and retry this segment once.
          if (!_isExpiredUrl(e)) rethrow;
          final bool reset;
          (download, urls, reset) = await _license(lessonUid, userId, download.rendition);
          if (reset) {
            // The lesson's media changed meanwhile: what we had is gone, start again from the first segment.
            i = 0;
            continue;
          }
          await _fetch(urls[i], file, cancel);
        }
      }
      i++;
      download = download.copyWith(completedSegments: i);
      // Progress survives the app being killed (the files are the truth; this is for the UI).
      if (i % 10 == 0) await store.save(download);
      onProgress?.call(
        DownloadProgress(completedSegments: i, segmentCount: urls.length, sizeBytes: download.sizeBytes),
      );
    }

    await store.save(download);
    return download;
  }

  /// Renews the license of a finished download (new expiry), without fetching anything else.
  Future<OfflineDownload> renew(OfflineDownload existing) async {
    final (download, _, reset) = await _license(existing.lessonUid, existing.userId, existing.rendition);
    // New media since the download: the old one is gone; the caller downloads again.
    if (reset) throw const MediaChanged();
    return download;
  }

  /// The license, its segment URLs, and whether what was on disk had to be dropped (new media, another
  /// quality or another account).
  Future<(OfflineDownload, List<String>, bool)> _license(String lessonUid, String userId, String? rendition) async {
    final response = await ApiException.guard(
      () => api.playback.lessonsOfflineLicense(
        lesson: lessonUid,
        body: OfflineLicenseRequest(rendition: rendition),
      ),
    );
    final license = response.data;
    final playlist = OfflinePlaylist.parse(license.playlist);

    final previous = await store.read(lessonUid);
    final sameMedia =
        previous != null &&
        previous.userId == userId &&
        previous.mediaId == license.mediaId &&
        previous.rendition == license.rendition.name &&
        previous.segmentCount == playlist.segmentUrls.length;
    // New media or another quality: start over rather than mixing segments.
    if (previous != null && !sameMedia) await store.delete(lessonUid);

    final expiresAt = DateTime.parse(license.expiresAt).toUtc();
    final draft = OfflineDownload(
      userId: userId,
      lessonUid: lessonUid,
      mediaId: license.mediaId,
      licenseId: license.licenseId,
      kind: license.kind,
      rendition: license.rendition.name,
      sizeBytes: license.rendition.sizeBytes,
      segmentCount: playlist.segmentUrls.length,
      expiresAt: expiresAt,
      wrappedKey: const WrappedKey(nonce: [], cipherText: [], mac: []),
      watermark: license.watermark.text,
      completedSegments: sameMedia ? previous.completedSegments : 0,
      lastOpenedAt: sameMedia ? previous.lastOpenedAt : null,
    );

    final key = base64Decode(license.key);
    final wrapped = await vault.wrap(key, boundTo: draft.keyBinding);
    key.fillRange(0, key.length, 0);

    final download = OfflineDownload.fromJson({...draft.toJson(), 'wrapped_key': wrapped.toJson()});
    await store.savePlaylist(lessonUid, playlist.template);
    await store.save(download);

    return (download, playlist.segmentUrls, previous != null && !sameMedia);
  }

  Future<void> _fetch(String url, File target, CancelToken? cancel) async {
    await target.parent.create(recursive: true);
    final part = File('${target.path}.part');
    var expected = -1;
    await segments.download(url, part.path, cancelToken: cancel, onReceiveProgress: (_, total) => expected = total);
    // A segment cut short on a flaky link must not be kept as done (resume skips existing files).
    if (expected > 0 && await part.length() != expected) {
      await part.delete();
      throw DioException(
        requestOptions: RequestOptions(path: url),
        type: DioExceptionType.connectionError,
      );
    }
    await part.rename(target.path);
  }

  static bool _isExpiredUrl(DioException e) {
    final status = e.response?.statusCode;
    return status == 400 || status == 401 || status == 403;
  }
}
