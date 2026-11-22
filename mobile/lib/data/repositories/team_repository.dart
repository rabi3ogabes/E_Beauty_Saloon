import '../../core/api/api_client.dart';
import '../models/json.dart';
import '../models/team.dart';

/// Staff app — live (`routes/api/staff.php`, token ability `staff`).
abstract class StaffRepository {
  /// `GET /staff/me/calendar?date` — my appointments of a branch-local day.
  Future<List<CalendarEntry>> calendar(DateTime date);

  /// `POST /staff/bookings/{reference}/check-in` — own appointments, or any with bookings.manage.
  Future<void> checkIn(CalendarEntry entry);

  /// `GET /staff/me/commissions` (this month by default).
  Future<CommissionSummary> commissions();

  /// `POST /staff/me/time-off` — creates a pending request the owner reviews.
  Future<void> requestTimeOff({required DateTime from, required DateTime to, String? reason});
}

class ApiStaffRepository implements StaffRepository {
  ApiStaffRepository(this._api);

  final ApiClient _api;

  @override
  Future<List<CalendarEntry>> calendar(DateTime date) async =>
      unwrapList(await _api.get('/staff/me/calendar', query: {'date': ymd(date)})).map(CalendarEntry.fromJson).toList();

  @override
  Future<void> checkIn(CalendarEntry entry) async {
    await _api.post('/staff/bookings/${Uri.encodeComponent(entry.reference ?? '${entry.bookingId}')}/check-in');
  }

  @override
  Future<CommissionSummary> commissions() async => CommissionSummary.fromJson(unwrapMap(await _api.get('/staff/me/commissions')));

  @override
  Future<void> requestTimeOff({required DateTime from, required DateTime to, String? reason}) async {
    await _api.post('/staff/me/time-off', data: {'from': ymd(from), 'to': ymd(to), 'reason': ?reason}, idempotencyKey: newIdempotencyKey());
  }
}

/// Owner app — live (`routes/api/owner.php`, token ability `owner`).
abstract class OwnerRepository {
  /// `GET /owner/kpis?branch_id` — today, salon timezone.
  Future<OwnerKpis> kpis({int? branchId});

  /// `GET /owner/alerts`
  Future<List<OwnerAlert>> alerts();

  /// `POST /owner/campaigns` — [audience] is a preset (`all`, `lapsed`, `vip`, `birthday`) or a saved
  /// segment id from `GET /owner/segments`. Consent, quiet hours and message credits apply server-side.
  Future<void> sendCampaign({required String channel, required String audience, required String message});
}

class ApiOwnerRepository implements OwnerRepository {
  ApiOwnerRepository(this._api);

  final ApiClient _api;

  @override
  Future<OwnerKpis> kpis({int? branchId}) async =>
      OwnerKpis.fromJson(unwrapMap(await _api.get('/owner/kpis', query: {'branch_id': branchId})));

  @override
  Future<List<OwnerAlert>> alerts() async => unwrapList(await _api.get('/owner/alerts')).map(OwnerAlert.fromJson).toList();

  @override
  Future<void> sendCampaign({required String channel, required String audience, required String message}) async {
    await _api.post(
      '/owner/campaigns',
      data: {'channel': channel, 'audience': audience, 'message': message},
      idempotencyKey: newIdempotencyKey(),
    );
  }
}
