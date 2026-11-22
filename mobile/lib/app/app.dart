import 'dart:async';

import 'package:cupertino_ui/cupertino_ui.dart' show GlobalCupertinoLocalizations;
import 'package:flutter_localizations/flutter_localizations.dart' show GlobalWidgetsLocalizations;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../core/push/push_service.dart';
import '../core/theme/app_theme.dart';
import '../l10n/l10n.dart';
import '../router/routers.dart';
import 'flavor.dart';
import 'providers.dart';

/// Brand palette: build-time white-label colours, overridden at runtime by `GET /salon` branding.
final brandPaletteProvider = Provider<BrandPalette>((ref) {
  final config = ref.watch(tenantConfigProvider);
  final base = BrandPalette(
    primary: config.primary,
    primaryDark: config.primaryDark,
    accent: config.accent,
    ink: config.ink,
    background: config.background,
  );
  final branding = ref.watch(salonProvider).asData?.value.branding;
  if (branding == null) return base;
  return base.merge(primary: branding.primary, accent: branding.accent, ink: branding.ink, background: branding.background);
});

final scaffoldMessengerKey = GlobalKey<ScaffoldMessengerState>();

class LuxeApp extends ConsumerWidget {
  const LuxeApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    final locale = ref.watch(localeProvider);
    final palette = ref.watch(brandPaletteProvider);
    final themeMode = ref.watch(themeModeProvider);
    final config = ref.watch(tenantConfigProvider);
    final flavor = ref.watch(flavorProvider);

    return MaterialApp.router(
      title: switch (flavor) {
        AppFlavor.client => config.appName,
        AppFlavor.staff => 'Luxe Staff',
        AppFlavor.owner => 'Luxe Owner',
      },
      debugShowCheckedModeBanner: false,
      scaffoldMessengerKey: scaffoldMessengerKey,
      routerConfig: router,
      locale: locale,
      supportedLocales: supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ],
      themeMode: themeMode,
      theme: buildLuxeTheme(palette: palette, brightness: Brightness.light, locale: locale),
      darkTheme: buildLuxeTheme(palette: palette, brightness: Brightness.dark, locale: locale),
      builder: (context, child) => _PushListener(child: child ?? const SizedBox.shrink()),
    );
  }
}

/// Registers the FCM token once signed in and turns pushes into snackbars / navigation.
class _PushListener extends ConsumerStatefulWidget {
  const _PushListener({required this.child});

  final Widget child;

  @override
  ConsumerState<_PushListener> createState() => _PushListenerState();
}

class _PushListenerState extends ConsumerState<_PushListener> {
  final _subs = <StreamSubscription<Object>>[];

  @override
  void initState() {
    super.initState();
    final push = PushService.instance;
    _subs
      ..add(push.onForegroundMessage.listen(_onForeground))
      ..add(push.onOpened.listen((m) => _open(m.route)))
      ..add(push.onToken.listen(_register));
    if (ref.read(sessionProvider).isSignedIn) _registerCurrent();
  }

  @override
  void dispose() {
    for (final s in _subs) {
      s.cancel();
    }
    super.dispose();
  }

  Future<void> _registerCurrent() async {
    final push = PushService.instance;
    if (push.token != null) await _register(push.token!);
    await push.requestPermissionAndRegister();
  }

  Future<void> _register(String token) async {
    if (!ref.read(sessionProvider).isSignedIn) return;
    try {
      await ref
          .read(deviceRepositoryProvider)
          .register(token: token, platform: PushService.platformName, app: ref.read(flavorProvider).name);
    } catch (_) {
      // TODO(backend): POST /devices pending; failures are non-fatal.
    }
  }

  void _onForeground(PushMessage m) {
    final text = [m.title, m.body].whereType<String>().join(' — ');
    if (text.isEmpty) return;
    final l10n = AppLocalizations.of(context);
    scaffoldMessengerKey.currentState?.showSnackBar(
      SnackBar(
        content: Text(text),
        action: m.route == null ? null : SnackBarAction(label: l10n.view, onPressed: () => _open(m.route)),
      ),
    );
  }

  void _open(String? route) {
    if (route == null || !route.startsWith('/')) return;
    ref.read(routerProvider).push(route);
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(sessionProvider, (prev, next) {
      if (next.isSignedIn && !(prev?.isSignedIn ?? false)) _registerCurrent();
    });
    return widget.child;
  }
}
