import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:luxe_salon/app/providers.dart';
import 'package:luxe_salon/core/api/api_exception.dart';
import 'package:luxe_salon/core/money/money.dart';
import 'package:luxe_salon/data/mock/mock_data.dart';
import 'package:luxe_salon/data/mock/mock_repositories.dart';
import 'package:luxe_salon/data/models/booking.dart';
import 'package:luxe_salon/features/client/booking/booking_flow_controller.dart';

import '../helpers.dart';

/// Records requests; can be told to fail.
class RecordingBookingRepository extends MockBookingRepository {
  RecordingBookingRepository() : super(latency: 0);

  final requests = <BookingRequest>[];
  ApiException? failWith;

  @override
  Future<Booking> create(BookingRequest request) async {
    requests.add(request);
    if (failWith != null) throw failWith!;
    return super.create(request);
  }
}

void main() {
  late ProviderContainer container;
  late RecordingBookingRepository bookings;
  final now = DateTime(2026, 11, 22, 9, 0); // a Sunday

  setUp(() {
    bookings = RecordingBookingRepository();
    container = ProviderContainer.test(
      retry: noRetry,
      overrides: [
        ...baseOverrides(),
        catalogRepositoryProvider.overrideWithValue(MockCatalogRepository(latency: 0)),
        bookingRepositoryProvider.overrideWithValue(bookings),
        bookingFlowProvider.overrideWith(() => BookingFlowController(clock: () => now)),
      ],
    );
  });

  BookingFlowController notifier() => container.read(bookingFlowProvider.notifier);
  BookingDraft state() => container.read(bookingFlowProvider);

  test('starts on the branch step for today with nothing selected', () {
    expect(state().step, BookingStep.branch);
    expect(state().date, DateTime(2026, 11, 22));
    expect(state().canContinue, isFalse);
  });

  test('prefill with service + branch jumps to the staff step and picks deposit when required', () async {
    await notifier().start(serviceIds: [201], branchId: 1);

    expect(state().branch?.id, 1);
    expect(state().services.map((s) => s.id), [201]);
    expect(state().step, BookingStep.staff);
    expect(state().payment, PaymentPreference.deposit);
    // 30% deposit rule on QAR 1,150.00
    expect(state().deposit, const Money(34500, 'QAR'));
  });

  test('full happy path builds the right POST /bookings body', () async {
    await notifier().start();
    final n = notifier();

    n.selectBranch(MockData.branches.first);
    expect(n.next(), isTrue);
    expect(state().step, BookingStep.services);

    n.toggleService(MockData.services[0]); // blow-dry 149.50
    n.toggleService(MockData.services[1]); // cut 280.00 with variants
    n.selectVariant(102, 1022); // creative director 420.00
    expect(state().total, const Money(14950 + 42000, 'QAR'));
    expect(state().durationMinutes, 45 + 75);
    expect(n.next(), isTrue);

    n.selectStaff(null); // no preference
    expect(n.next(), isTrue);
    expect(state().step, BookingStep.time);
    expect(state().canContinue, isFalse, reason: 'a slot is required');

    final slot = TimeSlot(
      startsAt: DateTime(2026, 11, 23, 16, 30),
      endsAt: DateTime(2026, 11, 23, 18, 30),
      items: const [SlotItem(serviceId: 101, staffId: 11), SlotItem(serviceId: 102, staffId: 12)],
    );
    n.selectDate(DateTime(2026, 11, 23));
    n.selectSlot(slot);
    expect(n.next(), isTrue);
    expect(state().step, BookingStep.review);

    n.setPayment(PaymentPreference.full);
    n.setNotes('  Low heat please ');
    final booking = await n.submit();

    expect(booking, isNotNull);
    expect(state().result?.id, booking!.id);
    final body = bookings.requests.single.toJson();
    expect(body['branch_id'], 1);
    expect(body['starts_at'], '2026-11-23T16:30:00.000');
    expect(body['payment_preference'], 'full');
    expect(body['notes'], 'Low heat please');
    expect(body['items'], [
      {'service_id': 101, 'staff_id': 11},
      {'service_id': 102, 'variant_id': 1022, 'staff_id': 12},
    ]);
  });

  test('changing services, staff or date clears the chosen slot', () async {
    await notifier().start(serviceIds: [101], branchId: 1);
    final slot = TimeSlot(startsAt: DateTime(2026, 11, 23, 10), endsAt: DateTime(2026, 11, 23, 11));

    notifier().selectSlot(slot);
    notifier().selectDate(DateTime(2026, 11, 24));
    expect(state().slot, isNull);

    notifier().selectSlot(slot);
    notifier().selectStaff(MockData.staff.first);
    expect(state().slot, isNull);

    notifier().selectSlot(slot);
    notifier().toggleService(MockData.services[4]);
    expect(state().slot, isNull);
  });

  test('past dates are ignored and back() walks the steps', () async {
    await notifier().start(serviceIds: [101], branchId: 1);
    notifier().selectDate(DateTime(2026, 11, 20));
    expect(state().date, DateTime(2026, 11, 22));

    expect(notifier().back(), isTrue);
    expect(state().step, BookingStep.services);
    expect(notifier().back(), isTrue);
    expect(notifier().back(), isFalse);
    expect(state().step, BookingStep.branch);
  });

  test('a failed submit keeps the draft and exposes the error', () async {
    bookings.failWith = const ApiException(kind: ApiErrorKind.validation, statusCode: 422, message: 'Slot no longer available');
    await notifier().start(serviceIds: [101], branchId: 1);
    notifier()
      ..next()
      ..selectSlot(TimeSlot(startsAt: DateTime(2026, 11, 23, 10), endsAt: DateTime(2026, 11, 23, 11)))
      ..next();
    expect(state().step, BookingStep.review);

    final result = await notifier().submit();

    expect(result, isNull);
    expect(state().submitting, isFalse);
    expect(state().error, isA<ApiException>());
    expect(state().slot, isNotNull);
    expect(state().step, BookingStep.review);
  });

  test('availability provider returns grouped slots from the repository', () async {
    await notifier().start(serviceIds: [101], branchId: 1);
    notifier().selectDate(DateTime(2026, 11, 23));
    final a = await container.read(availabilityProvider(state().availabilityQuery!).future);
    expect(a.date, '2026-11-23');
    expect(a.isEmpty, isFalse);
    for (final s in a.slots(DayPart.morning)) {
      expect(s.startsAt.hour, lessThan(12));
    }
  });
}
