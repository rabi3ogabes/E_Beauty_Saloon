import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/providers.dart';
import '../../../core/money/money.dart';
import '../../../data/models/booking.dart';
import '../../../data/models/catalog.dart';
import '../../../data/repositories/booking_repository.dart';

enum BookingStep { branch, services, staff, time, review }

/// Immutable booking draft driven by [BookingFlowController].
class BookingDraft {
  const BookingDraft({
    this.step = BookingStep.branch,
    this.branch,
    this.services = const [],
    this.variants = const {},
    this.staff,
    required this.date,
    this.slot,
    this.payment = PaymentPreference.payAtSalon,
    this.notes = '',
    this.submitting = false,
    this.error,
    this.result,
  });

  final BookingStep step;
  final Branch? branch;
  final List<SalonService> services;

  /// serviceId → variantId
  final Map<int, int> variants;

  /// `null` = "No preference".
  final StaffMember? staff;
  final DateTime date;
  final TimeSlot? slot;
  final PaymentPreference payment;
  final String notes;
  final bool submitting;
  final Object? error;
  final Booking? result;

  Money get total {
    if (services.isEmpty) return Money.zero();
    var sum = Money.zero(services.first.price.currency);
    for (final s in services) {
      sum += _option(s)?.price ?? s.price;
    }
    return sum;
  }

  int get durationMinutes => services.fold(0, (m, s) => m + (_option(s)?.durationMinutes ?? s.durationMinutes));

  /// Deposit using each service's rule (percent or fixed minor units); defaults to 30% when none is set.
  Money get deposit {
    if (services.isEmpty) return Money.zero();
    var sum = Money.zero(services.first.price.currency);
    for (final s in services) {
      final price = _option(s)?.price ?? s.price;
      final amount = switch (s.depositType) {
        'fixed' => s.depositValue ?? 0,
        'percent' => (price.amount * (s.depositValue ?? 0) / 100).round(),
        _ => (price.amount * .3).round(),
      };
      sum += Money(amount.clamp(0, price.amount), price.currency);
    }
    return sum;
  }

  bool get requiresDeposit => services.any((s) => s.depositType != null);

  ServiceOption? _option(SalonService s) {
    final id = variants[s.id];
    if (id == null) return null;
    for (final v in s.variants) {
      if (v.id == id) return v;
    }
    return null;
  }

  bool get canContinue => switch (step) {
    BookingStep.branch => branch != null,
    BookingStep.services => services.isNotEmpty,
    BookingStep.staff => true,
    BookingStep.time => slot != null,
    BookingStep.review => branch != null && services.isNotEmpty && slot != null && !submitting,
  };

  AvailabilityQuery? get availabilityQuery => branch == null || services.isEmpty
      ? null
      : AvailabilityQuery(
          branchId: branch!.id,
          serviceIds: services.map((s) => s.id).toList(),
          staffId: staff?.id,
          date: availabilityDate(date),
        );

  BookingRequest toRequest() {
    final s = slot!;
    return BookingRequest(
      branchId: branch!.id,
      startsAt: s.startsAt,
      paymentPreference: payment,
      notes: notes,
      items: [
        for (final service in services)
          BookingRequestItem(
            serviceId: service.id,
            variantId: variants[service.id],
            staffId: _staffFor(service.id, s) ?? staff?.id,
          ),
      ],
    );
  }

  int? _staffFor(int serviceId, TimeSlot s) {
    for (final i in s.items) {
      if (i.serviceId == serviceId) return i.staffId;
    }
    return null;
  }

  BookingDraft copyWith({
    BookingStep? step,
    Branch? branch,
    List<SalonService>? services,
    Map<int, int>? variants,
    StaffMember? staff,
    bool clearStaff = false,
    DateTime? date,
    TimeSlot? slot,
    bool clearSlot = false,
    PaymentPreference? payment,
    String? notes,
    bool? submitting,
    Object? error,
    bool clearError = false,
    Booking? result,
  }) {
    return BookingDraft(
      step: step ?? this.step,
      branch: branch ?? this.branch,
      services: services ?? this.services,
      variants: variants ?? this.variants,
      staff: clearStaff ? null : (staff ?? this.staff),
      date: date ?? this.date,
      slot: clearSlot ? null : (slot ?? this.slot),
      payment: payment ?? this.payment,
      notes: notes ?? this.notes,
      submitting: submitting ?? this.submitting,
      error: clearError ? null : (error ?? this.error),
      result: result ?? this.result,
    );
  }
}

/// Drives the booking wizard: branch → services → staff → date/slot → review → submit.
class BookingFlowController extends Notifier<BookingDraft> {
  BookingFlowController({DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final DateTime Function() _clock;

  DateTime get _today {
    final now = _clock();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  BookingDraft build() => BookingDraft(date: _today);

  /// Starts a new booking, optionally pre-filled (service detail, "Book this look", rebook).
  Future<void> start({List<int> serviceIds = const [], int? branchId, int? staffId}) async {
    state = BookingDraft(date: _today);
    final catalog = ref.read(catalogRepositoryProvider);

    final branches = await catalog.branches();
    Branch? branch;
    for (final b in branches) {
      if (b.id == branchId) branch = b;
    }
    branch ??= branches.length == 1 ? branches.first : null;

    var services = <SalonService>[];
    if (serviceIds.isNotEmpty) {
      final all = await catalog.services(branchId: branch?.id);
      services = all.where((s) => serviceIds.contains(s.id)).toList();
    }

    StaffMember? staff;
    if (staffId != null) {
      final team = await catalog.staff(branchId: branch?.id);
      for (final s in team) {
        if (s.id == staffId) staff = s;
      }
    }

    final step = branch == null ? BookingStep.branch : (services.isEmpty ? BookingStep.services : BookingStep.staff);
    state = state.copyWith(branch: branch, services: services, staff: staff, step: step, payment: _defaultPayment(services));
  }

  void selectBranch(Branch branch) {
    final changed = state.branch?.id != branch.id;
    state = state.copyWith(
      branch: branch,
      clearSlot: changed,
      clearStaff: changed && state.staff != null && !state.staff!.branchIds.contains(branch.id),
    );
  }

  void toggleService(SalonService service) {
    final exists = state.services.any((s) => s.id == service.id);
    final services = exists ? state.services.where((s) => s.id != service.id).toList() : [...state.services, service];
    final variants = Map<int, int>.from(state.variants)..remove(exists ? service.id : -1);
    state = state.copyWith(services: services, variants: variants, clearSlot: true, payment: _defaultPayment(services));
  }

  void selectVariant(int serviceId, int variantId) {
    state = state.copyWith(variants: {...state.variants, serviceId: variantId}, clearSlot: true);
  }

  /// `null` = no preference (any available specialist).
  void selectStaff(StaffMember? staff) {
    state = staff == null ? state.copyWith(clearStaff: true, clearSlot: true) : state.copyWith(staff: staff, clearSlot: true);
  }

  void selectDate(DateTime date) {
    final day = DateTime(date.year, date.month, date.day);
    if (day.isBefore(_today)) return;
    state = state.copyWith(date: day, clearSlot: true);
  }

  void selectSlot(TimeSlot slot) => state = state.copyWith(slot: slot, clearError: true);

  void setPayment(PaymentPreference p) => state = state.copyWith(payment: p);

  void setNotes(String notes) => state = state.copyWith(notes: notes);

  void goTo(BookingStep step) => state = state.copyWith(step: step);

  bool next() {
    if (!state.canContinue || state.step == BookingStep.review) return false;
    state = state.copyWith(step: BookingStep.values[state.step.index + 1]);
    return true;
  }

  /// Returns false when already on the first step.
  bool back() {
    if (state.step == BookingStep.branch) return false;
    state = state.copyWith(step: BookingStep.values[state.step.index - 1], clearError: true);
    return true;
  }

  /// Creates the booking. Returns it on success (state.result is set too), null on failure (state.error).
  Future<Booking?> submit() async {
    if (!state.canContinue || state.step != BookingStep.review) return null;
    state = state.copyWith(submitting: true, clearError: true);
    try {
      final booking = await ref.read(bookingRepositoryProvider).create(state.toRequest());
      state = state.copyWith(submitting: false, result: booking);
      ref.invalidate(myBookingsProvider);
      return booking;
    } catch (e) {
      state = state.copyWith(submitting: false, error: e);
      return null;
    }
  }

  PaymentPreference _defaultPayment(List<SalonService> services) =>
      services.any((s) => s.depositType != null) ? PaymentPreference.deposit : PaymentPreference.payAtSalon;
}

final bookingFlowProvider = NotifierProvider<BookingFlowController, BookingDraft>(BookingFlowController.new);

final availabilityProvider = FutureProvider.autoDispose.family<Availability, AvailabilityQuery>((ref, q) {
  return ref.watch(bookingRepositoryProvider).availability(q);
});

final myBookingsProvider = FutureProvider<List<Booking>>((ref) async {
  final session = ref.watch(sessionProvider);
  if (!session.isSignedIn) return const [];
  return ref.watch(bookingRepositoryProvider).list();
});
