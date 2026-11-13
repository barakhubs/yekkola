import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';

import '../../../core/api/api_exception.dart';

/// A fetch stopped because its screen or session ended.
class DocumentCancelled implements Exception {
  const DocumentCancelled();
}

/// The student's stamped copy of a document lesson (PRD-06 FR-06, PRD-07 FR-12): asked from the API (which
/// stamps it with the student's name and masked phone on a queue, then hands a short-lived signed link),
/// downloaded into app-private storage per account, and opened from there — online or not.
class DocumentRepository {
  DocumentRepository({required this.api, required this.files, required this.root, this.pollEvery});

  /// The API client (our headers).
  final Dio api;

  /// Plain client for the signed download link: none of our headers.
  final Dio files;

  /// App-private, excluded from backups.
  final Directory root;

  /// Tests only: a fixed wait between polls.
  final Duration? pollEvery;

  static final _uid = RegExp(r'^[0-9A-Za-z]{10,40}$');

  File fileFor(String userId, String lessonUid) {
    if (!_uid.hasMatch(userId) || !_uid.hasMatch(lessonUid)) throw ArgumentError('bad id');
    return File('${root.path}${Platform.pathSeparator}$userId${Platform.pathSeparator}$lessonUid.pdf');
  }

  Future<File?> saved(String userId, String lessonUid) async {
    final file = fileFor(userId, lessonUid);
    return await file.exists() ? file : null;
  }

  /// Gets (or refreshes) the stamped copy. While the API is still stamping it (202), asks again after its
  /// `retry_after`, up to [maxWait]. Stops (throws [DocumentCancelled]) once [cancelled] says so — e.g. the
  /// screen closed or the session ended —, so no copy lands after its account's documents were deleted.
  Future<File> fetch(
    String userId,
    String lessonUid, {
    Duration maxWait = const Duration(minutes: 2),
    bool Function()? cancelled,
  }) async {
    final stop = cancelled ?? () => false;
    final deadline = DateTime.now().add(maxWait);
    while (true) {
      final response = await _ask(lessonUid);
      if (stop()) throw const DocumentCancelled();
      final data = (response.data is Map ? (response.data as Map)['data'] : null) as Map?;
      if (response.statusCode == 200 && data?['url'] is String) {
        return _download(data!['url'] as String, fileFor(userId, lessonUid), stop);
      }
      final retry = (data?['retry_after'] as int?) ?? int.tryParse(response.headers.value('retry-after') ?? '') ?? 3;
      if (DateTime.now().isAfter(deadline)) throw const ApiException(code: 'document.still_processing', status: 202);
      await Future<void>.delayed(pollEvery ?? Duration(seconds: retry.clamp(1, 30)));
      if (stop()) throw const DocumentCancelled();
    }
  }

  /// Whether this student may still open the saved copy: asks the API (online only). A final refusal (access
  /// ended, course taken down, lesson gone) deletes the copy and is rethrown; a network failure isn't (the copy
  /// stays readable offline).
  Future<void> checkAccess(String userId, String lessonUid) async {
    try {
      await _ask(lessonUid);
    } on ApiException catch (e) {
      if (e.isNetwork || !_final(e)) return;
      final file = fileFor(userId, lessonUid);
      if (await file.exists()) await file.delete();
      rethrow;
    }
  }

  static bool _final(ApiException e) =>
      e.status == 404 ||
      e.status == 410 ||
      const {'enrollment.required', 'enrollment.course_taken_down', 'lesson.not_found'}.contains(e.code);

  Future<Response<Object?>> _ask(String lessonUid) => ApiException.guard(
    () => api.get<Object?>(
      '/lessons/$lessonUid/document',
      options: Options(validateStatus: (status) => status == 200 || status == 202),
    ),
  );

  Future<File> _download(String url, File target, bool Function() stop) async {
    await target.parent.create(recursive: true);
    final part = File('${target.path}.part');
    try {
      await ApiException.guard(() => files.download(url, part.path));
      if (stop()) throw const DocumentCancelled();
      await part.rename(target.path);
      return target;
    } catch (_) {
      if (await part.exists()) await part.delete();
      rethrow;
    }
  }

  /// Every document of every account on this phone (the session was ended by the API).
  Future<void> deleteAll() async {
    if (await root.exists()) await root.delete(recursive: true);
  }
}
