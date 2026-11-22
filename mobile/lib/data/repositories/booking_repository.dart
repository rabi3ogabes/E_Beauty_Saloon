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

/// Booking engine — TODO(backend): availability & bookings endpoints are being
/// built in parallel. [ApiBookingRepository] codes against the agreed contract;
/// [MockBookingRepository] (lib/data/mock) is used until `MOCK_PENDING_APIS=false`.
abstract class BookingRepository {
  /// `GET /availability?branch_id&services[]&staff_id&date`
  Future<Availability> availability(AvailabilityQuery query);

  /// `POST /bookings`
  Future<Booking> create(BookingRequest request);

  /// `GET /bookings`
  Future<List<Booking>> list();

  /// `POST /bookings/{id}/cancel`
  Future<Booking> cancel(int id, {String? reason});

  /// `POST /bookings/{id}/reschedule`
  Future<Booking> reschedule(int id, DateTime startsAt);
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
  Future<Booking> create(BookingRequest request) async => Booking.fromJson(unwrapMap(await _api.post('/bookings', data: request.toJson())));

  @override
  Future<List<Booking>> list() async => unwrapList(await _api.get('/bookings')).map(Booking.fromJson).toList();

  @override
  Future<Booking> cancel(int id, {String? reason}) async =>
      Booking.fromJson(unwrapMap(await _api.post('/bookings/$id/cancel', data: {'reason': ?reason})));

  @override
  Future<Booking> reschedule(int id, DateTime startsAt) async =>
      Booking.fromJson(unwrapMap(await _api.post('/bookings/$id/reschedule', data: {'starts_at': startsAt.toIso8601String()})));
}

/// Helper so UI code can build the date param consistently.
String availabilityDate(DateTime d) => ymd(d);
