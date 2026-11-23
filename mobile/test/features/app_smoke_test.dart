import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:luxe_salon/app/app.dart';
import 'package:luxe_salon/app/flavor.dart';
import 'package:luxe_salon/app/providers.dart';
import 'package:luxe_salon/core/storage/key_value_store.dart';
import 'package:luxe_salon/data/mock/mock_repositories.dart';
import 'package:luxe_salon/router/routers.dart';
import 'package:material_ui/material_ui.dart';

import '../helpers.dart';

/// Boots each app end-to-end on mock data to catch routing and layout errors.
void main() {
  setUpAll(disableGoogleFonts);

  List<Override> mocks(AppFlavor flavor, {Preloaded preloaded = const Preloaded()}) {
    final kv = MemoryKeyValueStore();
    return [
      flavorProvider.overrideWithValue(flavor),
      keyValueStoreProvider.overrideWithValue(kv),
      tokenStoreProvider.overrideWithValue(TokenStore(kv)),
      preloadedProvider.overrideWithValue(preloaded),
      catalogRepositoryProvider.overrideWithValue(MockCatalogRepository(latency: 0)),
      authRepositoryProvider.overrideWithValue(MockAuthRepository()),
      profileRepositoryProvider.overrideWithValue(MockProfileRepository()),
      bookingRepositoryProvider.overrideWithValue(MockBookingRepository(latency: 0)),
      shopRepositoryProvider.overrideWithValue(MockShopRepository()),
      studioRepositoryProvider.overrideWithValue(MockStudioRepository()),
      staffRepositoryProvider.overrideWithValue(MockStaffRepository()),
      ownerRepositoryProvider.overrideWithValue(MockOwnerRepository()),
      routerProvider.overrideWith(
        (ref) => ref.watch(switch (flavor) {
          AppFlavor.client => clientRouterProvider,
          AppFlavor.staff => staffRouterProvider,
          AppFlavor.owner => ownerRouterProvider,
        }),
      ),
    ];
  }

  Future<void> boot(WidgetTester tester, List<Override> overrides) async {
    await tester.binding.setSurfaceSize(const Size(430, 932));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(ProviderScope(retry: noRetry, overrides: overrides, child: const LuxeApp()));
    // Splash (min 900 ms) + mock latency.
    await tester.pump(const Duration(milliseconds: 1500));
    await tester.pump(const Duration(milliseconds: 1500));
  }

  testWidgets('client: splash → onboarding → home → booking flow', (tester) async {
    await boot(tester, mocks(AppFlavor.client));

    expect(find.text('Your salon, in your pocket'), findsOneWidget);
    await tester.tap(find.text('Skip'));
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));

    expect(find.text('Book an appointment'), findsOneWidget);
    expect(find.text('Maison Lumière'), findsWidgets);

    await tester.tap(find.text('Book an appointment'));
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));
    expect(find.text('Choose a salon'), findsOneWidget);
    expect(find.text('The Pearl'), findsOneWidget);

    await tester.tap(find.text('The Pearl'));
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));
    expect(find.text('Choose services'), findsOneWidget);
  });

  testWidgets('client: protected tab redirects to sign-in', (tester) async {
    await boot(tester, mocks(AppFlavor.client, preloaded: const Preloaded(onboardingSeen: true)));
    await tester.tap(find.text('Bookings'));
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));
    expect(find.text('Send code'), findsOneWidget);
  });

  testWidgets('client: Arabic home renders right-to-left', (tester) async {
    await boot(tester, mocks(AppFlavor.client, preloaded: const Preloaded(onboardingSeen: true, locale: 'ar')));
    expect(find.text('احجزي موعداً'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('احجزي موعداً'))), TextDirection.rtl);
  });

  Future<void> settle(WidgetTester tester) async {
    await tester.pump(const Duration(milliseconds: 800));
    await tester.pump(const Duration(milliseconds: 800));
  }

  testWidgets('client (signed in): every tab and account screen renders', (tester) async {
    await boot(tester, mocks(AppFlavor.client, preloaded: const Preloaded(onboardingSeen: true, token: 't')));

    await tester.tap(find.text('Services'));
    await settle(tester);
    expect(find.text('Search services'), findsOneWidget);
    await tester.tap(find.text('Signature Blow-dry'));
    await settle(tester);
    expect(find.text('Book this service'), findsOneWidget);
    await tester.pageBack();
    await settle(tester);

    await tester.tap(find.text('Bookings').last);
    await settle(tester);
    expect(find.text('Signature Blow-dry'), findsOneWidget);
    await tester.tap(find.text('Past'));
    await settle(tester);
    expect(find.text('Balayage Lumière'), findsOneWidget);

    await tester.tap(find.text('Shop').last);
    await settle(tester);
    expect(find.text('Elixir Ultime Oil'), findsOneWidget);
    await tester.tap(find.byTooltip('Add to bag').first);
    await settle(tester);
    await tester.tap(find.byTooltip('Bag'));
    await settle(tester);
    expect(find.text('Checkout'), findsOneWidget);
    await tester.pageBack();
    await settle(tester);

    await tester.tap(find.text('Me'));
    await settle(tester);
    expect(find.text('Mariam Al-Thani'), findsOneWidget);
    await tester.tap(find.text('Wallet'));
    await settle(tester);
    expect(find.text('WALLET BALANCE'), findsOneWidget);
    await tester.pageBack();
    await settle(tester);
    await tester.tap(find.text('Loyalty'));
    await settle(tester);
    expect(find.text('Invite a friend'), findsOneWidget);
    await tester.pageBack();
    await settle(tester);

    await tester.tap(find.text('Home'));
    await settle(tester);
    await tester.tap(find.text('Try a look'));
    await settle(tester);
    expect(find.text('Before we begin'), findsOneWidget);
    await tester.tap(find.byType(Checkbox));
    await tester.pump();
    await tester.tap(find.text('Agree and continue'));
    await settle(tester);
    expect(find.text('Create my look'), findsOneWidget);
  });

  testWidgets('client: booking flow reaches the review step', (tester) async {
    await boot(tester, mocks(AppFlavor.client, preloaded: const Preloaded(onboardingSeen: true, token: 't')));
    await tester.tap(find.text('Book an appointment'));
    await settle(tester);
    await tester.tap(find.text('The Pearl'));
    await settle(tester);
    await tester.tap(find.text('Signature Blow-dry'));
    await tester.pump();
    await tester.tap(find.text('Continue'));
    await settle(tester);
    expect(find.text('No preference'), findsOneWidget);
    await tester.tap(find.text('No preference'));
    await settle(tester);
    expect(find.text('Pick a time'), findsOneWidget);
    // Pick tomorrow (always has slots in the mock unless it's a Friday).
    final tomorrow = DateTime.now().add(Duration(days: DateTime.now().add(const Duration(days: 1)).weekday == DateTime.friday ? 2 : 1));
    await tester.tap(find.byKey(Key('date-${tomorrow.year}-${tomorrow.month}-${tomorrow.day}')));
    await settle(tester);
    await tester.tap(find.byType(ChoiceChip).first);
    await tester.pump();
    await tester.tap(find.text('Continue'));
    await settle(tester);
    expect(find.text('Review'), findsOneWidget);
    expect(find.text('Confirm booking'), findsOneWidget);
    await tester.tap(find.text('Confirm booking'));
    await settle(tester);
    await settle(tester);
    expect(find.text("You're booked"), findsOneWidget);
  });

  testWidgets('staff: signed-in user lands on the day calendar', (tester) async {
    await boot(tester, mocks(AppFlavor.staff, preloaded: const Preloaded(token: 't')));
    expect(find.text('My day'), findsOneWidget);
    expect(find.text('Mariam Al-Thani'), findsOneWidget);
    await tester.tap(find.text('Mariam Al-Thani'));
    await settle(tester);
    expect(find.text('Check in'), findsOneWidget);
    await tester.tap(find.text('Check in'));
    await settle(tester);
    expect(find.text('Checked in'), findsWidgets);
    await tester.pageBack();
    await settle(tester);
    await tester.tap(find.text('Commissions'));
    await settle(tester);
    expect(find.text('Tips'), findsOneWidget);
    await tester.tap(find.text('Time off').last);
    await settle(tester);
    expect(find.text('Choose dates'), findsOneWidget);
    await tester.tap(find.text('Settings'));
    await settle(tester);
    expect(find.text('Sign out'), findsOneWidget);
  });

  testWidgets('owner: signed-in user sees the KPI dashboard', (tester) async {
    await boot(tester, mocks(AppFlavor.owner, preloaded: const Preloaded(token: 't')));
    expect(find.text('REVENUE TODAY'), findsOneWidget);
    expect(find.text('Utilisation'), findsOneWidget);
    await tester.tap(find.text('The Pearl'));
    await settle(tester);
    await tester.tap(find.text('Alerts'));
    await settle(tester);
    expect(find.text('Payment gateway errors'), findsOneWidget);
    await tester.tap(find.text('Campaigns'));
    await settle(tester);
    expect(find.text('Send campaign'), findsOneWidget);
  });
}
