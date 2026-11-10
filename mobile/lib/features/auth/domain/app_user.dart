/// The signed-in account, as much of it as the app needs before the offline DB exists.
class AppUser {
  const AppUser({
    required this.id,
    required this.phone,
    required this.name,
    required this.locale,
    required this.status,
  });

  factory AppUser.fromJson(Map<String, Object?> json) => AppUser(
    id: json['id'] as String,
    phone: json['phone'] as String,
    name: json['name'] as String?,
    locale: (json['locale'] as String?) ?? 'fr',
    status: (json['status'] as String?) ?? 'active',
  );

  final String id;

  /// E.164.
  final String phone;
  final String? name;

  /// `fr` or `en`.
  final String locale;

  /// `active`, `suspended` (signed in, sees an explanation only — PRD-01 FR-15), …
  final String status;

  /// First sign-in collects the name before anything else (PRD-01 FR-04).
  bool get needsProfile => name == null || name!.trim().isEmpty;

  bool get isSuspended => status == 'suspended';

  Map<String, Object?> toJson() => {'id': id, 'phone': phone, 'name': name, 'locale': locale, 'status': status};
}
