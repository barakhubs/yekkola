/// A registered mobile device (one that can hold downloads), for the device list and the
/// device-limit flow (PRD-01 FR-07/FR-08).
class DeviceSummary {
  const DeviceSummary({
    required this.id,
    required this.platform,
    required this.name,
    required this.lastActiveAt,
    this.isCurrent = false,
  });

  /// Reads the `devices` entries of a `device.limit_reached` error or a DeviceResource.
  factory DeviceSummary.fromJson(Map<String, Object?> json) => DeviceSummary(
    id: json['id'] as String,
    platform: (json['platform'] as String?) ?? 'android',
    name: json['name'] as String?,
    lastActiveAt: DateTime.tryParse((json['last_active_at'] as String?) ?? '')?.toLocal(),
    isCurrent: json['is_current'] == true,
  );

  final String id;
  final String platform;
  final String? name;
  final DateTime? lastActiveAt;
  final bool isCurrent;
}
