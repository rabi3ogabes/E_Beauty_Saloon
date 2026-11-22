/// Lenient JSON readers: the API is the source of truth, but a missing or
/// oddly-typed field must never crash the app.
library;

typedef Json = Map<String, dynamic>;

int asInt(Object? v, [int fallback = 0]) {
  if (v is int) return v;
  if (v is num) return v.round();
  if (v is String) return int.tryParse(v) ?? double.tryParse(v)?.round() ?? fallback;
  return fallback;
}

int? asIntOrNull(Object? v) => v == null ? null : asInt(v);

double asDouble(Object? v, [double fallback = 0]) {
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v) ?? fallback;
  return fallback;
}

String asString(Object? v, [String fallback = '']) {
  if (v == null) return fallback;
  if (v is String) return v;
  // Spatie translatable fields can arrive as {en: .., ar: ..} from some endpoints.
  if (v is Map) return (v['en'] ?? v.values.firstOrNull ?? fallback).toString();
  return '$v';
}

String? asStringOrNull(Object? v) {
  final s = v == null ? null : asString(v);
  return (s == null || s.isEmpty) ? null : s;
}

bool asBool(Object? v, [bool fallback = false]) {
  if (v is bool) return v;
  if (v is num) return v != 0;
  if (v is String) return v == '1' || v.toLowerCase() == 'true';
  return fallback;
}

DateTime? asDate(Object? v) {
  if (v is String && v.isNotEmpty) return DateTime.tryParse(v);
  return null;
}

Json asJson(Object? v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};

List<Json> asJsonList(Object? v) => v is List ? v.whereType<Map>().map((m) => Map<String, dynamic>.from(m)).toList() : const [];

List<int> asIntList(Object? v) => v is List ? v.map((e) => asInt(e)).toList() : const [];

List<String> asStringList(Object? v) => v is List ? v.map((e) => '$e').toList() : const [];

/// `2026-11-22` (date only, local).
String ymd(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
