import '../../core/api/api_client.dart';
import '../models/json.dart';
import '../models/team.dart';

/// Staff app — TODO(backend): `/staff/me/*` and check-in endpoints in progress.
abstract class StaffRepository {
  Future<List<CalendarEntry>> calendar(DateTime date);
  Future<void> checkIn(int bookingId);
  Future<CommissionSummary> commissions();
  Future<void> requestTimeOff({required DateTime from, required DateTime to, String? reason});
}

class ApiStaffRepository implements StaffRepository {
  ApiStaffRepository(this._api);

  final ApiClient _api;

  @override
  Future<List<CalendarEntry>> calendar(DateTime date) async =>
      unwrapList(await _api.get('/staff/me/calendar', query: {'date': ymd(date)})).map(CalendarEntry.fromJson).toList();

  @override
  Future<void> checkIn(int bookingId) async {
    await _api.post('/bookings/$bookingId/check-in');
  }

  @override
  Future<CommissionSummary> commissions() async => CommissionSummary.fromJson(unwrapMap(await _api.get('/staff/me/commissions')));

  @override
  Future<void> requestTimeOff({required DateTime from, required DateTime to, String? reason}) async {
    // TODO(backend): endpoint name not agreed yet; assumed `POST /staff/me/time-off`.
    await _api.post('/staff/me/time-off', data: {'from': ymd(from), 'to': ymd(to), 'reason': ?reason});
  }
}

/// Owner app — TODO(backend): `/owner/*` endpoints in progress.
abstract class OwnerRepository {
  Future<OwnerKpis> kpis({int? branchId});
  Future<List<OwnerAlert>> alerts();

  /// Stub: quick campaign send. TODO(backend): marketing API not specified yet.
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
    await _api.post('/owner/campaigns', data: {'channel': channel, 'audience': audience, 'message': message});
  }
}
