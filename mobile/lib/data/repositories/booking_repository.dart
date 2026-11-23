import '../../core/api/api_client.dart';
import '../models/booking.dart';
import '../models/json.dart';

class AvailabilityQuery {
  const AvailabilityQuery({required this.branchId, required this.serviceIds, required this.date, this.staffId});

  final int branchId;
  final List<int> serviceIds;
  final int? staffId;

  /// `YYYY-MM-DD`
  final String date;

  @override
  bool operator ==(Object other) =>
      other is AvailabilityQuery &&
      other.branchId == branchId &&
      other.staffId == staffId &&
      other.date == date &&
      _listEq(other.serviceIds, serviceIds);

  @override
  int get hashCode => Object.hash(branchId, staffId, date, Object.hashAll(serviceIds));

  static bool _listEq(List<int> a, List<int> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i] != b[i]) return false;
    }
    return true;
  }
}

/// Booking engine — live (`routes/api/booking.php`, module `booking`). Bookings are addressed by
/// their `reference` (e.g. `K7Q2XM`), never by database id.
abstract class BookingRepository {
  /// `GET /availability?branch_id&services[]&staff_id&date`
  Future<Availability> availability(AvailabilityQuery query);

  /// `POST /bookings` (with `Idempotency-Key`). For deposit/full the booking is `pending_payment`
  /// and `payment.checkout_url` is the hosted payment page.
  Future<Booking> create(BookingRequest request);

  /// `GET /bookings`
  Future<List<Booking>> list();

  /// `POST /bookings/{reference}/cancel`
  Future<Booking> cancel(Booking booking, {String? reason});

  /// `POST /bookings/{reference}/reschedule`
  Future<Booking> reschedule(Booking booking, DateTime startsAt);
}

class ApiBookingRepository implements BookingRepository {
  ApiBookingRepository(this._api);

  final ApiClient _api;

  @override
  Future<Availability> availability(AvailabilityQuery q) async {
    final body = await _api.get(
      '/availability',
      query: {
        'branch_id': q.branchId,
        'services': q.serviceIds,
        'staff_id': q.staffId,
        'date': q.date,
      },
    );
    return Availability.fromJson(unwrapMap(body));
  }

  @override
  Future<Booking> create(BookingRequest request) async => Booking.fromJson(
    unwrapMap(await _api.post('/bookings', data: request.toJson(), idempotencyKey: request.idempotencyKey ?? newIdempotencyKey())),
  );

  @override
  Future<List<Booking>> list() async => unwrapList(await _api.get('/bookings', query: {'per_page': 50})).map(Booking.fromJson).toList();

  @override
  Future<Booking> cancel(Booking booking, {String? reason}) async =>
      Booking.fromJson(unwrapMap(await _api.post('/bookings/${_ref(booking)}/cancel', data: {'reason': ?reason})));

  @override
  Future<Booking> reschedule(Booking booking, DateTime startsAt) async => Booking.fromJson(
    unwrapMap(await _api.post('/bookings/${_ref(booking)}/reschedule', data: {'starts_at': startsAt.toUtc().toIso8601String()})),
  );

  static String _ref(Booking b) => Uri.encodeComponent(b.reference ?? '${b.id}');
}

/// Helper so UI code can build the date param consistently.
String availabilityDate(DateTime d) => ymd(d);
