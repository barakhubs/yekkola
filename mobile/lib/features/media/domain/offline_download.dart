import '../data/offline_key_vault.dart';

/// What the app keeps about one downloaded lesson, next to its encrypted segments (`manifest.json`).
/// Holds the key only wrapped (PRD-07 FR-07). Moves to drift with the sync engine.
class OfflineDownload {
  const OfflineDownload({
    required this.userId,
    required this.lessonUid,
    required this.mediaId,
    required this.licenseId,
    required this.kind,
    required this.rendition,
    required this.sizeBytes,
    required this.segmentCount,
    required this.expiresAt,
    required this.wrappedKey,
    required this.watermark,
    required this.completedSegments,
    this.lastOpenedAt,
    this.courseId,
    this.courseTitle,
    this.lessonTitle,
  });

  factory OfflineDownload.fromJson(Map<String, Object?> json) => OfflineDownload(
    userId: json['user_id']! as String,
    lessonUid: json['lesson_uid']! as String,
    mediaId: json['media_id']! as String,
    licenseId: json['license_id']! as String,
    kind: json['kind']! as String,
    rendition: json['rendition']! as String,
    sizeBytes: json['size_bytes']! as int,
    segmentCount: json['segment_count']! as int,
    expiresAt: DateTime.parse(json['expires_at']! as String),
    wrappedKey: WrappedKey.fromJson((json['wrapped_key']! as Map).cast<String, Object?>()),
    watermark: json['watermark']! as String,
    completedSegments: json['completed_segments']! as int,
    lastOpenedAt: json['last_opened_at'] == null ? null : DateTime.parse(json['last_opened_at']! as String),
    courseId: json['course_id'] as String?,
    courseTitle: json['course_title'] as String?,
    lessonTitle: json['lesson_title'] as String?,
  );

  /// The account it was downloaded for: other accounts signed in on this phone never see or play it.
  final String userId;
  final String lessonUid;

  /// The lesson's media: a new one (re-uploaded video) means downloading again.
  final String mediaId;
  final String licenseId;

  /// `video` or `audio`.
  final String kind;
  final String rendition;

  /// Estimated total, from the API (shown before downloading — PRD-07 FR-08).
  final int sizeBytes;
  final int segmentCount;

  /// Offline license expiry (the platform setting at issue/renewal); after it, playback needs a renewal online.
  final DateTime expiresAt;
  final WrappedKey wrappedKey;

  /// Per-viewer overlay text (name + masked phone).
  final String watermark;
  final int completedSegments;

  /// Latest time the app played it: a device clock set back before this is treated as expired. Only moves
  /// forward (see [openedAt]).
  final DateTime? lastOpenedAt;

  /// For the downloads screen (shown offline): which course and lesson this is.
  final String? courseId;
  final String? courseTitle;
  final String? lessonTitle;

  bool get isComplete => completedSegments >= segmentCount;

  /// What the wrapped key is bound to: changing any of these (e.g. a longer expiry) breaks the key.
  String get keyBinding => [userId, licenseId, lessonUid, mediaId, expiresAt.toUtc().toIso8601String()].join('|');

  /// Playable now? Expired, or the clock was moved back past the last playback.
  bool isUsableAt(DateTime now) =>
      now.isBefore(expiresAt) &&
      (lastOpenedAt == null || !now.isBefore(lastOpenedAt!.subtract(const Duration(minutes: 5))));

  OfflineDownload copyWith({int? completedSegments, DateTime? lastOpenedAt}) => OfflineDownload(
    userId: userId,
    lessonUid: lessonUid,
    mediaId: mediaId,
    licenseId: licenseId,
    kind: kind,
    rendition: rendition,
    sizeBytes: sizeBytes,
    segmentCount: segmentCount,
    expiresAt: expiresAt,
    wrappedKey: wrappedKey,
    watermark: watermark,
    completedSegments: completedSegments ?? this.completedSegments,
    lastOpenedAt: lastOpenedAt ?? this.lastOpenedAt,
    courseId: courseId,
    courseTitle: courseTitle,
    lessonTitle: lessonTitle,
  );

  /// Records a playback at [now] without letting a clock set back drag the guard backwards.
  OfflineDownload openedAt(DateTime now) =>
      copyWith(lastOpenedAt: lastOpenedAt != null && lastOpenedAt!.isAfter(now) ? lastOpenedAt : now);

  Map<String, Object?> toJson() => {
    'user_id': userId,
    'lesson_uid': lessonUid,
    'media_id': mediaId,
    'license_id': licenseId,
    'kind': kind,
    'rendition': rendition,
    'size_bytes': sizeBytes,
    'segment_count': segmentCount,
    'expires_at': expiresAt.toUtc().toIso8601String(),
    'wrapped_key': wrappedKey.toJson(),
    'watermark': watermark,
    'completed_segments': completedSegments,
    'last_opened_at': lastOpenedAt?.toUtc().toIso8601String(),
    'course_id': courseId,
    'course_title': courseTitle,
    'lesson_title': lessonTitle,
  };
}
