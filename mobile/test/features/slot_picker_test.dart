import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/intl.dart' show DateFormat;
import 'package:luxe_salon/core/api/api_exception.dart';
import 'package:luxe_salon/data/models/booking.dart';
import 'package:luxe_salon/features/client/booking/slot_picker.dart';
import 'package:material_ui/material_ui.dart';

import '../helpers.dart';

void main() {
  setUpAll(disableGoogleFonts);

  final today = DateTime(2026, 11, 22);
  // CLDR uses a narrow no-break space before AM/PM, so derive labels the same way the UI does.
  String label(int h, int m) => DateFormat.jm('en').format(DateTime(2026, 11, 23, h, m));
  TimeSlot slot(int h, int m) => TimeSlot(startsAt: DateTime(2026, 11, 23, h, m), endsAt: DateTime(2026, 11, 23, h + 1, m));

  final availability = Availability(
    date: '2026-11-23',
    groups: {
      DayPart.morning: [slot(10, 0), slot(10, 30)],
      DayPart.afternoon: [slot(14, 0)],
      DayPart.evening: [slot(18, 30), slot(19, 0)],
    },
  );

  Future<void> pump(
    WidgetTester tester, {
    required AsyncValue<Availability> value,
    TimeSlot? selected,
    ValueChanged<DateTime>? onDate,
    ValueChanged<TimeSlot>? onSlot,
    VoidCallback? onRetry,
    Locale locale = const Locale('en'),
  }) async {
    await tester.binding.setSurfaceSize(const Size(430, 1600));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(
      testApp(
        Scaffold(
          body: SingleChildScrollView(
            child: SlotPicker(
              today: today,
              date: DateTime(2026, 11, 23),
              availability: value,
              selected: selected,
              onDateChanged: onDate ?? (_) {},
              onSlotSelected: onSlot ?? (_) {},
              onRetry: onRetry,
            ),
          ),
        ),
        locale: locale,
      ),
    );
    await tester.pump(const Duration(milliseconds: 300));
  }

  testWidgets('groups slots into morning, afternoon and evening', (tester) async {
    await pump(tester, value: AsyncValue.data(availability));

    expect(find.text('Morning'), findsOneWidget);
    expect(find.text('Afternoon'), findsOneWidget);
    expect(find.text('Evening'), findsOneWidget);
    expect(find.byType(ChoiceChip), findsNWidgets(5));
    expect(find.text(label(10, 30)), findsOneWidget);
    expect(find.text(label(19, 0)), findsOneWidget);
  });

  testWidgets('hides empty groups', (tester) async {
    await pump(
      tester,
      value: AsyncValue.data(
        Availability(
          date: '2026-11-23',
          groups: {
            DayPart.morning: [slot(9, 0)],
            DayPart.afternoon: const [],
            DayPart.evening: const [],
          },
        ),
      ),
    );
    expect(find.text('Morning'), findsOneWidget);
    expect(find.text('Afternoon'), findsNothing);
    expect(find.text('Evening'), findsNothing);
  });

  testWidgets('tapping a slot reports it and selection is shown', (tester) async {
    TimeSlot? picked;
    await pump(tester, value: AsyncValue.data(availability), onSlot: (s) => picked = s);

    await tester.tap(find.text(label(14, 0)));
    await tester.pump();
    expect(picked, slot(14, 0));

    await pump(tester, value: AsyncValue.data(availability), selected: slot(14, 0));
    final chip = tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, label(14, 0)));
    expect(chip.selected, isTrue);
    expect(tester.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, label(10, 0))).selected, isFalse);
  });

  testWidgets('tapping a day in the strip changes the date', (tester) async {
    DateTime? date;
    await pump(tester, value: AsyncValue.data(availability), onDate: (d) => date = d);
    await tester.tap(find.byKey(const Key('date-2026-11-24')));
    expect(date, DateTime(2026, 11, 24));
  });

  testWidgets('loading shows skeletons, not a spinner', (tester) async {
    await pump(tester, value: const AsyncValue.loading());
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.byType(ChoiceChip), findsNothing);
  });

  testWidgets('fully booked day offers the next available date', (tester) async {
    DateTime? date;
    await pump(
      tester,
      value: AsyncValue.data(
        Availability(date: '2026-11-23', groups: {for (final p in DayPart.values) p: const []}, nextAvailableDate: '2026-11-25'),
      ),
      onDate: (d) => date = d,
    );
    expect(find.text('No times left'), findsOneWidget);
    await tester.tap(find.textContaining('Next available'));
    expect(date, DateTime(2026, 11, 25));
  });

  testWidgets('errors are retryable', (tester) async {
    var retried = 0;
    await pump(
      tester,
      value: const AsyncValue.error(ApiException(kind: ApiErrorKind.network, message: 'offline'), StackTrace.empty),
      onRetry: () => retried++,
    );
    await tester.tap(find.text('Try again'));
    expect(retried, 1);
  });

  testWidgets('Arabic renders right-to-left with Arabic day parts', (tester) async {
    await pump(tester, value: AsyncValue.data(availability), locale: const Locale('ar'));
    expect(find.text('الصباح'), findsOneWidget);
    expect(find.text('المساء'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('الصباح'))), TextDirection.rtl);
  });
}
