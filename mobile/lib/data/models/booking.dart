import '../../core/money/money.dart';
import 'json.dart';

enum DayPart { morning, afternoon, evening }

class SlotItem {
  const SlotItem({required this.serviceId, this.staffId});

  factory SlotItem.fromJson(Json j) => SlotItem(serviceId: asInt(j['service_id']), staffId: asIntOrNull(j['staff_id']));

  final int serviceId;
  final int? staffId;

  Json toJson() => {'service_id': serviceId, 'staff_id': staffId};
}

class TimeSlot {
  const TimeSlot({required this.startsAt, required this.endsAt, this.items = const []});

  factory TimeSlot.fromJson(Json j) => TimeSlot(
    startsAt: asDate(j['starts_at']) ?? DateTime.now(),
    endsAt: asDate(j['ends_at']) ?? DateTime.now(),
    items: asJsonList(j['items']).map(SlotItem.fromJson).toList(),
  );

  /// Raw ISO string is kept so we post back exactly what the server offered.
  final DateTime startsAt;
  final DateTime endsAt;
  final List<SlotItem> items;

  @override
  bool operator ==(Object other) => other is TimeSlot && other.startsAt == startsAt && other.endsAt == endsAt;

  @override
  int get hashCode => Object.hash(startsAt, endsAt);
}

/// `GET /availability` — TODO(backend): endpoint in progress.
class Availability {
  const Availability({required this.date, required this.groups, this.nextAvailableDate});

  factory Availability.fromJson(Json j) {
    final groups = <DayPart, List<TimeSlot>>{};
    final raw = asJson(j['groups']);
    for (final part in DayPart.values) {
      groups[part] = asJsonList(raw[part.name]).map(TimeSlot.fromJson).toList();
    }
    return Availability(
      date: asString(j['date']),
      groups: groups,
      nextAvailableDate: asStringOrNull(j['next_available_date']),
    );
  }

  /// `YYYY-MM-DD`
  final String date;
  final Map<DayPart, List<TimeSlot>> groups;
  final String? nextAvailableDate;

  bool get isEmpty => groups.values.every((l) => l.isEmpty);

  List<TimeSlot> slots(DayPart part) => groups[part] ?? const [];
}

enum PaymentPreference {
  deposit('deposit'),
  full('full'),
  payAtSalon('pay_at_salon');

  const PaymentPreference(this.wire);
  final String wire;

  bool get isOnline => this != PaymentPreference.payAtSalon;
}

class BookingRequestItem {
  const BookingRequestItem({required this.serviceId, this.variantId, this.staffId});

  final int serviceId;
  final int? variantId;
  final int? staffId;

  Json toJson() => {
    'service_id': serviceId,
    if (variantId != null) 'variant_id': variantId,
    if (staffId != null) 'staff_id': staffId,
  };
}

/// Body of `POST /bookings`.
class BookingRequest {
  const BookingRequest({
    required this.branchId,
    required this.items,
    required this.startsAt,
    required this.paymentPreference,
    this.notes,
  });

  final int branchId;
  final List<BookingRequestItem> items;
  final DateTime startsAt;
  final PaymentPreference paymentPreference;
  final String? notes;

  Json toJson() => {
    'branch_id': branchId,
    'items': items.map((i) => i.toJson()).toList(),
    'starts_at': startsAt.toIso8601String(),
    'payment_preference': paymentPreference.wire,
    if (notes != null && notes!.trim().isNotEmpty) 'notes': notes!.trim(),
  };
}

class BookingItem {
  const BookingItem({
    required this.serviceId,
    required this.serviceName,
    this.staffId,
    this.staffName,
    this.price,
    this.durationMinutes,
  });

  factory BookingItem.fromJson(Json j) => BookingItem(
    serviceId: asInt(j['service_id']),
    serviceName: asString(j['service_name'] ?? asJson(j['service'])['name']),
    staffId: asIntOrNull(j['staff_id']),
    staffName: asStringOrNull(j['staff_name'] ?? asJson(j['staff'])['name']),
    price: j['price'] == null ? null : Money.fromJson(j['price']),
    durationMinutes: asIntOrNull(j['duration_minutes']),
  );

  final int serviceId;
  final String serviceName;
  final int? staffId;
  final String? staffName;
  final Money? price;
  final int? durationMinutes;
}

class BookingPayment {
  const BookingPayment({this.status, this.preference, this.checkoutUrl, this.amountDue});

  factory BookingPayment.fromJson(Json j) => BookingPayment(
    status: asStringOrNull(j['status']),
    preference: asStringOrNull(j['preference']),
    checkoutUrl: asStringOrNull(j['checkout_url']),
    amountDue: j['amount_due'] == null ? null : Money.fromJson(j['amount_due']),
  );

  final String? status;
  final String? preference;

  /// Hosted payment page, present when an online payment is required.
  final String? checkoutUrl;
  final Money? amountDue;
}

enum BookingStatus { pending, confirmed, checkedIn, completed, cancelled, noShow, unknown }

BookingStatus bookingStatusFrom(String? s) => switch (s) {
  'pending' || 'awaiting_payment' => BookingStatus.pending,
  'confirmed' => BookingStatus.confirmed,
  'checked_in' || 'in_progress' => BookingStatus.checkedIn,
  'completed' => BookingStatus.completed,
  'cancelled' || 'canceled' => BookingStatus.cancelled,
  'no_show' => BookingStatus.noShow,
  _ => BookingStatus.unknown,
};

/// Booking resource — TODO(backend): contract from the bookings engineer, may evolve.
class Booking {
  const Booking({
    required this.id,
    required this.status,
    required this.startsAt,
    required this.endsAt,
    required this.branchId,
    required this.total,
    this.reference,
    this.branchName,
    this.items = const [],
    this.payment,
    this.notes,
    this.canCancel = true,
    this.canReschedule = true,
  });

  factory Booking.fromJson(Json j) {
    final branch = asJson(j['branch']);
    return Booking(
      id: asInt(j['id']),
      reference: asStringOrNull(j['reference']),
      status: bookingStatusFrom(asStringOrNull(j['status'])),
      startsAt: asDate(j['starts_at']) ?? DateTime.now(),
      endsAt: asDate(j['ends_at']) ?? DateTime.now(),
      branchId: asInt(j['branch_id'] ?? branch['id']),
      branchName: asStringOrNull(j['branch_name'] ?? branch['name']),
      items: asJsonList(j['items']).map(BookingItem.fromJson).toList(),
      total: Money.fromJson(j['total']),
      payment: j['payment'] == null ? null : BookingPayment.fromJson(asJson(j['payment'])),
      notes: asStringOrNull(j['notes']),
      canCancel: asBool(j['can_cancel'], true),
      canReschedule: asBool(j['can_reschedule'], true),
    );
  }

  final int id;
  final String? reference;
  final BookingStatus status;
  final DateTime startsAt;
  final DateTime endsAt;
  final int branchId;
  final String? branchName;
  final List<BookingItem> items;
  final Money total;
  final BookingPayment? payment;
  final String? notes;
  final bool canCancel;
  final bool canReschedule;

  bool get isUpcoming => endsAt.isAfter(DateTime.now()) && status != BookingStatus.cancelled && status != BookingStatus.completed;

  String get title => items.map((i) => i.serviceName).join(' · ');

  Booking copyWith({BookingStatus? status, DateTime? startsAt, DateTime? endsAt}) => Booking(
    id: id,
    reference: reference,
    status: status ?? this.status,
    startsAt: startsAt ?? this.startsAt,
    endsAt: endsAt ?? this.endsAt,
    branchId: branchId,
    branchName: branchName,
    items: items,
    total: total,
    payment: payment,
    notes: notes,
    canCancel: canCancel,
    canReschedule: canReschedule,
  );
}
