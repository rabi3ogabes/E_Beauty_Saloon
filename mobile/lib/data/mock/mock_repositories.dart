import 'dart:io';

import '../../core/api/api_exception.dart';
import '../../core/money/money.dart';
import '../models/account.dart';
import '../models/booking.dart';
import '../models/catalog.dart';
import '../models/commerce.dart';
import '../models/studio.dart';
import '../models/team.dart';
import '../repositories/account_repository.dart';
import '../repositories/booking_repository.dart';
import '../repositories/catalog_repository.dart';
import '../repositories/commerce_repository.dart';
import '../repositories/team_repository.dart';
import 'mock_data.dart';

/// Mock implementations used while backend endpoints are pending
/// (`MOCK_PENDING_APIS=true`, the default) or in `DEMO_MODE`.
/// Delete a mock once its endpoint ships and the flag is flipped.

Future<void> _latency([int ms = 350]) => Future<void>.delayed(Duration(milliseconds: ms));

class MockCatalogRepository implements CatalogRepository {
  MockCatalogRepository({this.latency = 300});

  final int latency;

  @override
  Future<Salon> salon() async {
    await _latency(latency);
    return MockData.salon;
  }

  @override
  Future<List<Branch>> branches() async {
    await _latency(latency);
    return MockData.branches;
  }

  @override
  Future<List<ServiceCategory>> categories() async {
    await _latency(latency);
    return MockData.categories;
  }

  @override
  Future<List<SalonService>> services({int? branchId, int? categoryId, String? query}) async {
    await _latency(latency);
    final q = query?.toLowerCase().trim() ?? '';
    return MockData.services
        .where((s) => categoryId == null || s.categoryId == categoryId)
        .where((s) => q.isEmpty || s.name.toLowerCase().contains(q))
        .toList();
  }

  @override
  Future<SalonService> service(int id, {int? branchId}) async {
    await _latency(latency);
    return MockData.services.firstWhere(
      (s) => s.id == id,
      orElse: () => throw const ApiException(kind: ApiErrorKind.notFound, message: 'Service not found', statusCode: 404),
    );
  }

  @override
  Future<List<StaffMember>> staff({int? branchId, int? serviceId}) async {
    await _latency(latency);
    return MockData.staff
        .where((s) => branchId == null || s.branchIds.contains(branchId))
        .where((s) => serviceId == null || s.serviceIds.contains(serviceId))
        .toList();
  }
}

class MockAuthRepository implements AuthRepository {
  /// Any 6-digit code works except `000000`, which simulates a wrong code.
  @override
  Future<void> requestOtp(String phone) async {
    await _latency();
  }

  @override
  Future<AuthResult> verify({required String phone, required String code, String? name, required String deviceName}) async {
    await _latency();
    if (code == '000000') {
      throw const ApiException(
        kind: ApiErrorKind.validation,
        statusCode: 422,
        message: 'The code is invalid or has expired.',
        errors: {
          'code': ['The code is invalid or has expired.'],
        },
      );
    }
    return AuthResult(token: 'mock-token', client: MockData.client);
  }

  @override
  Future<void> logout() async {}
}

class MockProfileRepository implements ProfileRepository {
  ClientProfile _me = MockData.client;

  @override
  Future<ClientProfile> me() async {
    await _latency();
    return _me;
  }

  @override
  Future<ClientProfile> update({String? name, String? email, String? locale, Map<String, bool>? consents}) async {
    await _latency(200);
    _me = _me.copyWith(name: name, email: email, locale: locale, consents: consents == null ? null : {..._me.consents, ...consents});
    return _me;
  }

  @override
  Future<Wallet> wallet() async {
    await _latency();
    final now = DateTime.now();
    return Wallet(
      balance: MockData.qar(25000),
      transactions: [
        WalletTransaction(
          id: 3,
          type: 'refund',
          amount: MockData.qar(5000),
          balanceAfter: MockData.qar(25000),
          note: 'Late cancellation refund',
          createdAt: now.subtract(const Duration(days: 3)),
        ),
        WalletTransaction(
          id: 2,
          type: 'payment',
          amount: MockData.qar(-30000),
          balanceAfter: MockData.qar(20000),
          note: 'Balayage Lumière',
          createdAt: now.subtract(const Duration(days: 20)),
        ),
        WalletTransaction(
          id: 1,
          type: 'gift_card',
          amount: MockData.qar(50000),
          balanceAfter: MockData.qar(50000),
          note: 'Gift card redeemed',
          createdAt: now.subtract(const Duration(days: 40)),
        ),
      ],
    );
  }

  @override
  Future<LoyaltySummary> loyalty() async {
    await _latency();
    final now = DateTime.now();
    return LoyaltySummary(
      points: 1840,
      tier: 'gold',
      referralCode: 'MARIAM25',
      entries: [
        LoyaltyEntry(
          id: 3,
          type: 'earn',
          points: 115,
          balanceAfter: 1840,
          note: 'Balayage Lumière',
          createdAt: now.subtract(const Duration(days: 20)),
        ),
        LoyaltyEntry(
          id: 2,
          type: 'referral',
          points: 250,
          balanceAfter: 1725,
          note: 'Friend joined: Hessa',
          createdAt: now.subtract(const Duration(days: 34)),
        ),
        LoyaltyEntry(
          id: 1,
          type: 'redeem',
          points: -500,
          balanceAfter: 1475,
          note: 'Redeemed for QAR 50',
          createdAt: now.subtract(const Duration(days: 60)),
        ),
      ],
    );
  }

  @override
  Future<Map<String, dynamic>> export() async {
    await _latency();
    return {
      'profile': {'name': _me.name, 'phone': _me.phone, 'email': _me.email},
      'bookings': [],
      'consents': _me.consents,
    };
  }

  @override
  Future<void> deleteAccount() async => _latency();
}

class MockDeviceRepository implements DeviceRepository {
  @override
  Future<void> register({required String token, required String platform, required String app}) async {}
}

class MockBookingRepository implements BookingRepository {
  MockBookingRepository({this.latency = 350, DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final int latency;
  final DateTime Function() _clock;
  final List<Booking> _bookings = [];
  int _nextId = 5001;
  bool _seeded = false;

  @override
  Future<Availability> availability(AvailabilityQuery q) async {
    await _latency(latency);
    final day = DateTime.parse(q.date);
    final now = _clock();
    // Fridays are closed in the demo so the "next available" path is exercised.
    if (day.weekday == DateTime.friday) {
      return Availability(
        date: q.date,
        groups: {for (final p in DayPart.values) p: const []},
        nextAvailableDate: availabilityDate(day.add(const Duration(days: 1))),
      );
    }
    final staffId = q.staffId ?? MockData.staff.first.id;
    final groups = <DayPart, List<TimeSlot>>{for (final p in DayPart.values) p: []};
    for (var minutes = 10 * 60; minutes <= 20 * 60 + 30; minutes += 30) {
      final start = DateTime(day.year, day.month, day.day, minutes ~/ 60, minutes % 60);
      if (start.isBefore(now)) continue;
      // Deterministic gaps so the grid looks realistic.
      if ((minutes ~/ 30 + day.day) % 4 == 0) continue;
      final part = start.hour < 12 ? DayPart.morning : (start.hour < 17 ? DayPart.afternoon : DayPart.evening);
      groups[part]!.add(
        TimeSlot(
          startsAt: start,
          endsAt: start.add(const Duration(minutes: 60)),
          items: [for (final id in q.serviceIds) SlotItem(serviceId: id, staffId: staffId)],
        ),
      );
    }
    return Availability(date: q.date, groups: groups, nextAvailableDate: availabilityDate(day.add(const Duration(days: 1))));
  }

  @override
  Future<Booking> create(BookingRequest request) async {
    await _latency(latency);
    final services = MockData.services.where((s) => request.items.any((i) => i.serviceId == s.id)).toList();
    final total = services.fold<Money>(Money.zero(MockData.currency), (sum, s) => sum + s.price);
    final duration = services.fold<int>(0, (sum, s) => sum + s.durationMinutes);
    final branch = MockData.branches.firstWhere((b) => b.id == request.branchId, orElse: () => MockData.branches.first);
    final due = switch (request.paymentPreference) {
      PaymentPreference.full => total,
      PaymentPreference.deposit => Money((total.amount * 0.3).round(), total.currency),
      PaymentPreference.payAtSalon => null,
    };
    final booking = Booking(
      id: _nextId++,
      reference: 'LUM-${_nextId + 1000}',
      status: request.paymentPreference.isOnline ? BookingStatus.pending : BookingStatus.confirmed,
      startsAt: request.startsAt,
      endsAt: request.startsAt.add(Duration(minutes: duration == 0 ? 60 : duration)),
      branchId: branch.id,
      branchName: branch.name,
      total: total,
      notes: request.notes,
      items: [
        for (final s in services)
          BookingItem(
            serviceId: s.id,
            serviceName: s.name,
            price: s.price,
            durationMinutes: s.durationMinutes,
            staffId: request.items.firstWhere((i) => i.serviceId == s.id).staffId,
            staffName: _staffName(request.items.firstWhere((i) => i.serviceId == s.id).staffId),
          ),
      ],
      payment: BookingPayment(
        status: due == null ? 'not_required' : 'pending',
        preference: request.paymentPreference.wire,
        amountDue: due,
        // A real checkout URL comes from the backend's payment gateway.
        checkoutUrl: null,
      ),
    );
    _bookings.insert(0, booking);
    return booking;
  }

  String? _staffName(int? id) {
    if (id == null) return null;
    for (final s in MockData.staff) {
      if (s.id == id) return s.name;
    }
    return null;
  }

  void _seed() {
    if (_seeded) return;
    _seeded = true;
    final now = _clock();
    final d1 = DateTime(now.year, now.month, now.day, 16).add(const Duration(days: 3));
    final d2 = DateTime(now.year, now.month, now.day, 11).subtract(const Duration(days: 20));
    _bookings.addAll([
      Booking(
        id: 4001,
        reference: 'LUM-4001',
        status: BookingStatus.confirmed,
        startsAt: d1,
        endsAt: d1.add(const Duration(minutes: 45)),
        branchId: 1,
        branchName: 'The Pearl',
        total: MockData.qar(14950),
        items: [
          BookingItem(
            serviceId: 101,
            serviceName: 'Signature Blow-dry',
            staffId: 11,
            staffName: 'Layla Haddad',
            price: MockData.qar(14950),
          ),
        ],
      ),
      Booking(
        id: 3990,
        reference: 'LUM-3990',
        status: BookingStatus.completed,
        startsAt: d2,
        endsAt: d2.add(const Duration(minutes: 180)),
        branchId: 1,
        branchName: 'The Pearl',
        total: MockData.qar(115000),
        canCancel: false,
        canReschedule: false,
        items: [
          BookingItem(
            serviceId: 201,
            serviceName: 'Balayage Lumière',
            staffId: 12,
            staffName: 'Sofia Marchetti',
            price: MockData.qar(115000),
          ),
        ],
      ),
    ]);
  }

  @override
  Future<List<Booking>> list() async {
    await _latency(latency);
    _seed();
    return List.unmodifiable(_bookings);
  }

  @override
  Future<Booking> cancel(Booking booking, {String? reason}) async {
    await _latency(latency);
    final i = _bookings.indexWhere((b) => b.id == booking.id);
    if (i < 0) throw const ApiException(kind: ApiErrorKind.notFound, message: 'Booking not found', statusCode: 404);
    return _bookings[i] = _bookings[i].copyWith(status: BookingStatus.cancelled);
  }

  @override
  Future<Booking> reschedule(Booking booking, DateTime startsAt) async {
    await _latency(latency);
    final i = _bookings.indexWhere((b) => b.id == booking.id);
    if (i < 0) throw const ApiException(kind: ApiErrorKind.notFound, message: 'Booking not found', statusCode: 404);
    final b = _bookings[i];
    return _bookings[i] = b.copyWith(startsAt: startsAt, endsAt: startsAt.add(b.endsAt.difference(b.startsAt)));
  }
}

class MockShopRepository implements ShopRepository {
  @override
  Future<List<Product>> products({String? query}) async {
    await _latency();
    final q = query?.toLowerCase() ?? '';
    return MockData.products.where((p) => q.isEmpty || p.name.toLowerCase().contains(q)).toList();
  }

  @override
  Future<Product> product(int id) async {
    await _latency(200);
    return MockData.products.firstWhere((p) => p.id == id);
  }

  @override
  Future<Order> placeOrder({
    required List<CartLine> lines,
    required Fulfilment fulfilment,
    int? branchId,
    String? address,
    String? paymentMethod,
  }) async {
    await _latency(600);
    final total = lines.fold<Money>(Money.zero(MockData.currency), (sum, l) => sum + l.total);
    return Order(id: 901, reference: 'ORD-901', status: 'pending_payment', total: total);
  }

  @override
  Future<List<SalonPackage>> packages() async {
    await _latency();
    return MockData.packages;
  }
}

class MockStudioRepository implements StudioRepository {
  final Map<String, ({DateTime started, String photo, int styleId})> _jobs = {};
  final List<Look> _looks = [];

  @override
  Future<List<StudioStyle>> styles({StudioCategory? category}) async {
    await _latency();
    return MockData.styles.where((s) => category == null || s.category == category.wire).toList();
  }

  @override
  Future<String> createJob({required File photo, required StudioCategory category, required int styleId}) async {
    await _latency(700);
    final id = 'job_${DateTime.now().millisecondsSinceEpoch}';
    _jobs[id] = (started: DateTime.now(), photo: photo.path, styleId: styleId);
    return id;
  }

  @override
  Future<StudioJob> job(String id) async {
    await _latency(150);
    final j = _jobs[id];
    if (j == null) throw const ApiException(kind: ApiErrorKind.notFound, message: 'Job not found', statusCode: 404);
    final style = MockData.styles.firstWhere((s) => s.id == j.styleId, orElse: () => MockData.styles.first);
    final elapsed = DateTime.now().difference(j.started).inMilliseconds / 6000;
    if (elapsed < 1) {
      return StudioJob(
        id: id,
        status: elapsed < .15 ? StudioJobStatus.queued : StudioJobStatus.processing,
        progress: elapsed,
        beforeUrl: j.photo,
        styleName: style.name,
      );
    }
    final service = MockData.services.firstWhere((s) => s.id == style.serviceId, orElse: () => MockData.services.first);
    final done = StudioJob(
      id: id,
      status: StudioJobStatus.completed,
      progress: 1,
      beforeUrl: j.photo,
      // Mock: the "after" is the same photo; the UI renders a tinted preview.
      afterUrl: j.photo,
      price: service.price,
      serviceId: service.id,
      styleName: style.name,
    );
    if (!_looks.any((l) => l.id == id)) {
      _looks.insert(
        0,
        Look(
          id: id,
          beforeUrl: j.photo,
          afterUrl: j.photo,
          styleName: style.name,
          serviceId: service.id,
          createdAt: DateTime.now(),
          price: service.price,
        ),
      );
    }
    return done;
  }

  @override
  Future<List<Look>> looks() async {
    await _latency();
    return List.unmodifiable(_looks);
  }
}

class MockStaffRepository implements StaffRepository {
  final Set<int> _checkedIn = {};

  @override
  Future<List<CalendarEntry>> calendar(DateTime date) async {
    await _latency();
    DateTime at(int h, int m) => DateTime(date.year, date.month, date.day, h, m);
    final entries = [
      CalendarEntry(
        bookingId: 7001,
        clientName: 'Mariam Al-Thani',
        clientPhone: '+97455501234',
        clientNotes: 'Prefers low heat. Sensitive scalp — use the gentle shampoo.',
        startsAt: at(10, 0),
        endsAt: at(10, 45),
        status: BookingStatus.confirmed,
        services: ['Signature Blow-dry'],
      ),
      CalendarEntry(
        bookingId: 7002,
        clientName: 'Hessa Al-Sulaiti',
        clientPhone: '+97455507777',
        isFirstVisit: true,
        startsAt: at(11, 30),
        endsAt: at(14, 30),
        status: BookingStatus.confirmed,
        services: ['Balayage Lumière'],
      ),
      CalendarEntry(
        bookingId: 7003,
        clientName: 'Dana Youssef',
        clientNotes: 'Allergic to PPD — patch test done 12/10.',
        startsAt: at(15, 0),
        endsAt: at(16, 15),
        status: BookingStatus.pending,
        services: ['Root Touch-up'],
      ),
      CalendarEntry(
        bookingId: 7004,
        clientName: 'Reem Khalifa',
        startsAt: at(17, 30),
        endsAt: at(18, 30),
        status: BookingStatus.confirmed,
        services: ['Precision Cut & Style'],
      ),
    ];
    return entries.map((e) => _checkedIn.contains(e.bookingId) ? e.copyWith(status: BookingStatus.checkedIn) : e).toList();
  }

  @override
  Future<void> checkIn(int bookingId) async {
    await _latency();
    _checkedIn.add(bookingId);
  }

  @override
  Future<CommissionSummary> commissions() async {
    await _latency();
    final now = DateTime.now();
    return CommissionSummary(
      periodLabel: 'This month',
      total: MockData.qar(642500),
      servicesTotal: MockData.qar(548000),
      productsTotal: MockData.qar(46500),
      tips: MockData.qar(48000),
      lines: [
        CommissionLine(label: 'Balayage Lumière · Hessa', amount: MockData.qar(23000), date: now.subtract(const Duration(days: 1))),
        CommissionLine(label: 'Precision Cut · Reem', amount: MockData.qar(5600), date: now.subtract(const Duration(days: 2))),
        CommissionLine(label: 'Elixir Ultime Oil (retail)', amount: MockData.qar(1950), date: now.subtract(const Duration(days: 2))),
      ],
    );
  }

  @override
  Future<void> requestTimeOff({required DateTime from, required DateTime to, String? reason}) => _latency(500);
}

class MockOwnerRepository implements OwnerRepository {
  @override
  Future<OwnerKpis> kpis({int? branchId}) async {
    await _latency();
    final factor = branchId == null ? 3 : 1;
    return OwnerKpis(
      revenueToday: MockData.qar(1284000 * factor ~/ 2),
      revenueYesterday: MockData.qar(1120000 * factor ~/ 2),
      bookingsToday: 23 * factor,
      utilization: branchId == 3 ? .58 : .82,
      newClients: 4 * factor,
      averageTicket: MockData.qar(55800),
      hourlyRevenue: const [0, 42000, 61000, 120000, 98000, 76000, 143000, 188000, 210000, 160000, 90000, 40000],
    );
  }

  @override
  Future<List<OwnerAlert>> alerts() async {
    await _latency();
    final now = DateTime.now();
    return [
      OwnerAlert(
        id: 'a1',
        severity: AlertSeverity.critical,
        title: 'Payment gateway errors',
        body: '3 deposit payments failed in the last hour.',
        createdAt: now.subtract(const Duration(minutes: 12)),
      ),
      OwnerAlert(
        id: 'a2',
        severity: AlertSeverity.warning,
        title: 'Low stock: Bond Repair Mask',
        body: '2 units left at The Pearl.',
        createdAt: now.subtract(const Duration(hours: 2)),
      ),
      OwnerAlert(
        id: 'a3',
        severity: AlertSeverity.info,
        title: 'New 5★ review',
        body: '“Layla is a magician.” — Hessa',
        createdAt: now.subtract(const Duration(hours: 5)),
      ),
    ];
  }

  @override
  Future<void> sendCampaign({required String channel, required String audience, required String message}) => _latency(600);
}
