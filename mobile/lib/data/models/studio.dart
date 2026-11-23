import '../../core/money/money.dart';
import 'json.dart';

/// AI Studio categories accepted by `POST /studio/jobs` (`App\Domain\AIStudio\Models\Style::CATEGORIES`).
/// Hair colour styles (balayage, gloss …) live under `hair`.
enum StudioCategory {
  hair('hair'),
  eyes('eyes'),
  lips('lips'),
  makeup('makeup'),
  nails('nails'),
  pedicure('pedicure'),
  bridal('bridal');

  const StudioCategory(this.wire);
  final String wire;
}

/// `GET /studio/styles` (`App\Http\Controllers\Api\V1\StudioController`).
class StudioStyle {
  const StudioStyle({required this.id, required this.name, required this.category, this.thumbnailUrl, this.serviceId});

  factory StudioStyle.fromJson(Json j) => StudioStyle(
    id: asInt(j['id']),
    name: asString(j['name']),
    category: asString(j['category'], 'hair'),
    thumbnailUrl: asStringOrNull(j['thumbnail_url']),
    serviceId: asIntOrNull(j['service_id']),
  );

  final int id;
  final String name;
  final String category;
  final String? thumbnailUrl;
  final int? serviceId;
}

enum StudioJobStatus { queued, processing, completed, failed }

StudioJobStatus studioStatusFrom(String? s) => switch (s) {
  'completed' || 'succeeded' || 'done' => StudioJobStatus.completed,
  'failed' || 'error' || 'rejected' || 'refunded' => StudioJobStatus.failed,
  'processing' || 'running' => StudioJobStatus.processing,
  _ => StudioJobStatus.queued,
};

/// `GET /studio/jobs/{id}` — `status`: queued | running | succeeded | failed | refunded; photo URLs are signed (60 min).
class StudioJob {
  const StudioJob({
    required this.id,
    required this.status,
    this.progress,
    this.beforeUrl,
    this.afterUrl,
    this.price,
    this.serviceId,
    this.styleName,
    this.error,
    this.lookId,
    this.durationMinutes,
    this.bookUrl,
  });

  factory StudioJob.fromJson(Json j) => StudioJob(
    id: asString(j['id']),
    status: studioStatusFrom(asStringOrNull(j['status'])),
    progress: j['progress'] == null ? null : asDouble(j['progress']).clamp(0, 1).toDouble(),
    beforeUrl: asStringOrNull(j['before_url']),
    afterUrl: asStringOrNull(j['after_url']),
    price: j['price'] == null ? null : Money.fromJson(j['price']),
    serviceId: asIntOrNull(j['service_id']),
    styleName: asStringOrNull(j['style_name']),
    error: asStringOrNull(j['error']),
    lookId: asStringOrNull(j['look_id']),
    durationMinutes: asIntOrNull(j['duration_minutes']),
    bookUrl: asStringOrNull(asJson(j['book'])['url']),
  );

  /// Look reference (`LK…`): `/looks/{id}/save`, `look_reference` when booking.
  final String? lookId;
  final int? durationMinutes;

  /// Web booking deep link (`/book?branch&service&look`); the app books natively with [serviceId].
  final String? bookUrl;

  final String id;
  final StudioJobStatus status;

  /// 0..1 when the backend reports it.
  final double? progress;
  final String? beforeUrl;
  final String? afterUrl;
  final Money? price;

  /// Service to pre-select in "Book this look".
  final int? serviceId;
  final String? styleName;
  final String? error;

  bool get isFinished => status == StudioJobStatus.completed || status == StudioJobStatus.failed;
}

/// `GET /looks` — `id` is the look reference.
class Look {
  const Look({required this.id, this.beforeUrl, this.afterUrl, this.styleName, this.serviceId, this.createdAt, this.price});

  factory Look.fromJson(Json j) => Look(
    id: asString(j['id']),
    beforeUrl: asStringOrNull(j['before_url']),
    afterUrl: asStringOrNull(j['after_url']),
    styleName: asStringOrNull(j['style_name']),
    serviceId: asIntOrNull(j['service_id']),
    createdAt: asDate(j['created_at']),
    price: j['price'] == null ? null : Money.fromJson(j['price']),
  );

  final String id;
  final String? beforeUrl;
  final String? afterUrl;
  final String? styleName;
  final int? serviceId;
  final DateTime? createdAt;
  final Money? price;
}
