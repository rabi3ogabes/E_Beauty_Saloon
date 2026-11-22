import '../../core/money/money.dart';
import 'booking.dart';
import 'json.dart';

/// One appointment on a staff member's day — `GET /staff/me/calendar?date` (TODO(backend)).
class CalendarEntry {
  const CalendarEntry({
    required this.bookingId,
    required this.clientName,
    required this.startsAt,
    required this.endsAt,
    required this.status,
    this.clientPhone,
    this.clientNotes,
    this.services = const [],
    this.isFirstVisit = false,
    this.branchName,
  });

  factory CalendarEntry.fromJson(Json j) {
    final client = asJson(j['client']);
    return CalendarEntry(
      bookingId: asInt(j['booking_id'] ?? j['id']),
      clientName: asString(client['name'] ?? j['client_name']),
      clientPhone: asStringOrNull(client['phone'] ?? j['client_phone']),
      clientNotes: asStringOrNull(client['notes'] ?? j['client_notes']),
      isFirstVisit: asBool(client['first_visit'] ?? j['first_visit']),
      startsAt: asDate(j['starts_at']) ?? DateTime.now(),
      endsAt: asDate(j['ends_at']) ?? DateTime.now(),
      status: bookingStatusFrom(asStringOrNull(j['status'])),
      services: asStringList(j['services']),
      branchName: asStringOrNull(j['branch_name']),
    );
  }

  final int bookingId;
  final String clientName;
  final String? clientPhone;
  final String? clientNotes;
  final bool isFirstVisit;
  final DateTime startsAt;
  final DateTime endsAt;
  final BookingStatus status;
  final List<String> services;
  final String? branchName;

  CalendarEntry copyWith({BookingStatus? status}) => CalendarEntry(
    bookingId: bookingId,
    clientName: clientName,
    clientPhone: clientPhone,
    clientNotes: clientNotes,
    isFirstVisit: isFirstVisit,
    startsAt: startsAt,
    endsAt: endsAt,
    status: status ?? this.status,
    services: services,
    branchName: branchName,
  );
}

class CommissionLine {
  const CommissionLine({required this.label, required this.amount, this.date});

  factory CommissionLine.fromJson(Json j) => CommissionLine(
    label: asString(j['label'] ?? j['service_name']),
    amount: Money.fromJson(j['amount']),
    date: asDate(j['date']),
  );

  final String label;
  final Money amount;
  final DateTime? date;
}

/// `GET /staff/me/commissions` — TODO(backend).
class CommissionSummary {
  const CommissionSummary({
    required this.periodLabel,
    required this.total,
    required this.servicesTotal,
    required this.productsTotal,
    required this.tips,
    this.lines = const [],
  });

  factory CommissionSummary.fromJson(Json j) => CommissionSummary(
    periodLabel: asString(j['period']),
    total: Money.fromJson(j['total']),
    servicesTotal: Money.fromJson(j['services_total']),
    productsTotal: Money.fromJson(j['products_total']),
    tips: Money.fromJson(j['tips']),
    lines: asJsonList(j['lines']).map(CommissionLine.fromJson).toList(),
  );

  final String periodLabel;
  final Money total;
  final Money servicesTotal;
  final Money productsTotal;
  final Money tips;
  final List<CommissionLine> lines;
}

/// `GET /owner/kpis?branch_id` — TODO(backend).
class OwnerKpis {
  const OwnerKpis({
    required this.revenueToday,
    required this.revenueYesterday,
    required this.bookingsToday,
    required this.utilization,
    required this.newClients,
    required this.averageTicket,
    this.hourlyRevenue = const [],
  });

  factory OwnerKpis.fromJson(Json j) => OwnerKpis(
    revenueToday: Money.fromJson(j['revenue_today']),
    revenueYesterday: Money.fromJson(j['revenue_yesterday']),
    bookingsToday: asInt(j['bookings_today']),
    utilization: asDouble(j['utilization']).clamp(0, 1).toDouble(),
    newClients: asInt(j['new_clients']),
    averageTicket: Money.fromJson(j['average_ticket']),
    hourlyRevenue: (j['hourly_revenue'] is List) ? (j['hourly_revenue'] as List).map((e) => asInt(e)).toList() : const [],
  );

  final Money revenueToday;
  final Money revenueYesterday;
  final int bookingsToday;

  /// 0..1 share of bookable staff time that is booked.
  final double utilization;
  final int newClients;
  final Money averageTicket;

  /// Minor units per opening hour, for the sparkline.
  final List<int> hourlyRevenue;

  double? get revenueChange =>
      revenueYesterday.amount == 0 ? null : (revenueToday.amount - revenueYesterday.amount) / revenueYesterday.amount;
}

enum AlertSeverity { info, warning, critical }

/// `GET /owner/alerts` — TODO(backend).
class OwnerAlert {
  const OwnerAlert({required this.id, required this.title, required this.severity, this.body, this.createdAt});

  factory OwnerAlert.fromJson(Json j) => OwnerAlert(
    id: asString(j['id']),
    title: asString(j['title']),
    body: asStringOrNull(j['body']),
    severity: switch (asStringOrNull(j['severity'])) {
      'critical' || 'danger' => AlertSeverity.critical,
      'warning' => AlertSeverity.warning,
      _ => AlertSeverity.info,
    },
    createdAt: asDate(j['created_at']),
  );

  final String id;
  final String title;
  final String? body;
  final AlertSeverity severity;
  final DateTime? createdAt;
}
