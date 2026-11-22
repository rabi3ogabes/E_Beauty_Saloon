import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:material_ui/material_ui.dart';

import '../core/push/push_service.dart';
import '../core/storage/key_value_store.dart';
import '../router/routers.dart';
import 'app.dart';
import 'flavor.dart';
import 'providers.dart';

/// Shared startup for the three entrypoints.
Future<void> bootstrap(AppFlavor flavor) async {
  WidgetsFlutterBinding.ensureInitialized();
  await SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);
  await initializeDateFormatting();

  final store = SecureKeyValueStore();
  final tokens = TokenStore(store);
  final preloaded = await Preloaded.load(store, tokens);

  // Push is optional: never block or crash startup on it.
  unawaited(PushService.instance.init());

  final Provider<GoRouter> router = switch (flavor) {
    AppFlavor.client => clientRouterProvider,
    AppFlavor.staff => staffRouterProvider,
    AppFlavor.owner => ownerRouterProvider,
  };

  FlutterError.onError = (details) {
    FlutterError.presentError(details);
    // Hook Crashlytics / Sentry here.
  };

  runApp(
    ProviderScope(
      overrides: [
        flavorProvider.overrideWithValue(flavor),
        keyValueStoreProvider.overrideWithValue(store),
        tokenStoreProvider.overrideWithValue(tokens),
        preloadedProvider.overrideWithValue(preloaded),
        routerProvider.overrideWith((ref) => ref.watch(router)),
      ],
      child: const LuxeApp(),
    ),
  );

  if (kDebugMode) debugPrint('Luxe Salon (${flavor.name}) started');
}
