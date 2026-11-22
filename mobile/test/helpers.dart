import 'package:cupertino_ui/cupertino_ui.dart' show GlobalCupertinoLocalizations;
import 'package:flutter_localizations/flutter_localizations.dart' show GlobalWidgetsLocalizations;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:luxe_salon/app/providers.dart';
import 'package:luxe_salon/core/storage/key_value_store.dart';
import 'package:luxe_salon/core/theme/app_theme.dart';
import 'package:luxe_salon/l10n/l10n.dart';
import 'package:material_ui/material_ui.dart';

/// No network in tests: use the platform font instead of google_fonts.
void disableGoogleFonts() => LuxeTypography.useGoogleFonts = false;

/// Never auto-retry failed providers in tests.
Duration? noRetry(int _, Object _) => null;

/// Overrides every app needs in tests (in-memory storage, no preloaded session).
List<Override> baseOverrides({KeyValueStore? store}) {
  final kv = store ?? MemoryKeyValueStore();
  return [
    keyValueStoreProvider.overrideWithValue(kv),
    tokenStoreProvider.overrideWithValue(TokenStore(kv)),
    preloadedProvider.overrideWithValue(const Preloaded()),
  ];
}

/// Wraps [child] in ProviderScope + MaterialApp with theme and localisation.
Widget testApp(Widget child, {List<Override> overrides = const [], Locale locale = const Locale('en')}) {
  return ProviderScope(
    retry: noRetry,
    overrides: [...baseOverrides(), ...overrides],
    child: MaterialApp(
      locale: locale,
      supportedLocales: supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ],
      theme: buildLuxeTheme(palette: const BrandPalette(), brightness: Brightness.light, locale: locale),
      home: child,
    ),
  );
}
