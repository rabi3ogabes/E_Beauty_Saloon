import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../config/env.dart';
import '../core/api/api_client.dart';
import '../core/storage/key_value_store.dart';
import '../data/mock/mock_repositories.dart';
import '../data/models/account.dart';
import '../data/models/catalog.dart';
import '../data/repositories/account_repository.dart';
import '../data/repositories/booking_repository.dart';
import '../data/repositories/catalog_repository.dart';
import '../data/repositories/commerce_repository.dart';
import '../data/repositories/team_repository.dart';
import 'flavor.dart';

// ---------------------------------------------------------------------------
// Storage & preloaded values (overridden in bootstrap after async reads)
// ---------------------------------------------------------------------------

final keyValueStoreProvider = Provider<KeyValueStore>((ref) => SecureKeyValueStore());

final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore(ref.watch(keyValueStoreProvider)));

/// Values read from storage before `runApp` so the first frame is correct.
class Preloaded {
  const Preloaded({this.token, this.locale, this.themeMode = ThemeMode.system, this.onboardingSeen = false, this.tenantOverride});

  final String? token;
  final String? locale;
  final ThemeMode themeMode;
  final bool onboardingSeen;

  /// Staff/owner apps can switch salon at sign-in.
  final String? tenantOverride;

  static Future<Preloaded> load(KeyValueStore store, TokenStore tokens) async {
    final results = await Future.wait([
      tokens.read(),
      store.read(StorageKeys.locale),
      store.read(StorageKeys.themeMode),
      store.read(StorageKeys.onboardingSeen),
      store.read(StorageKeys.tenantOverride),
    ]);
    return Preloaded(
      token: results[0],
      locale: results[1],
      themeMode: ThemeMode.values.firstWhere((m) => m.name == results[2], orElse: () => ThemeMode.system),
      onboardingSeen: results[3] == '1',
      tenantOverride: results[4],
    );
  }
}

final preloadedProvider = Provider<Preloaded>((ref) => const Preloaded());

// ---------------------------------------------------------------------------
// Locale & theme mode
// ---------------------------------------------------------------------------

const supportedLocales = [Locale('en'), Locale('ar')];

class LocaleController extends Notifier<Locale> {
  @override
  Locale build() {
    final stored = ref.read(preloadedProvider).locale;
    final code = stored ?? ref.read(tenantConfigProvider).defaultLocale;
    return Locale(code == 'ar' ? 'ar' : 'en');
  }

  Future<void> set(Locale locale) async {
    if (locale == state) return;
    state = locale;
    await ref.read(keyValueStoreProvider).write(StorageKeys.locale, locale.languageCode);
  }

  Future<void> toggle() => set(state.languageCode == 'ar' ? const Locale('en') : const Locale('ar'));
}

final localeProvider = NotifierProvider<LocaleController, Locale>(LocaleController.new);

class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => ref.read(preloadedProvider).themeMode;

  Future<void> set(ThemeMode mode) async {
    state = mode;
    await ref.read(keyValueStoreProvider).write(StorageKeys.themeMode, mode.name);
  }
}

final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(ThemeModeController.new);

// ---------------------------------------------------------------------------
// Tenant (white-label slug; staff/owner may switch)
// ---------------------------------------------------------------------------

class TenantController extends Notifier<String> {
  @override
  String build() => ref.read(preloadedProvider).tenantOverride ?? ref.read(tenantConfigProvider).tenantSlug;

  Future<void> set(String slug) async {
    final clean = slug.trim().toLowerCase();
    if (clean.isEmpty || clean == state) return;
    state = clean;
    await ref.read(keyValueStoreProvider).write(StorageKeys.tenantOverride, clean);
  }
}

final tenantProvider = NotifierProvider<TenantController, String>(TenantController.new);

// ---------------------------------------------------------------------------
// API client
// ---------------------------------------------------------------------------

final apiClientProvider = Provider<ApiClient>((ref) {
  final config = ref.watch(tenantConfigProvider);
  return ApiClient.create(
    baseUrl: config.apiRoot,
    tenant: () => ref.read(tenantProvider),
    locale: () => ref.read(localeProvider).languageCode,
    tokens: ref.watch(tokenStoreProvider),
    onUnauthorized: () => scheduleMicrotask(() => ref.read(sessionProvider.notifier).expire()),
  );
});

// ---------------------------------------------------------------------------
// Repositories. Live endpoints use the API unless DEMO_MODE; pending ones use
// mocks until MOCK_PENDING_APIS=false.
// ---------------------------------------------------------------------------

const bool _mockLive = Env.demoMode;
const bool _mockPending = Env.demoMode || Env.mockPendingApis;

final catalogRepositoryProvider = Provider<CatalogRepository>(
  (ref) => _mockLive ? MockCatalogRepository() : ApiCatalogRepository(ref.watch(apiClientProvider)),
);

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  final flavor = ref.watch(flavorProvider);
  // TODO(backend): staff/owner auth endpoints pending — mocked until shipped.
  final mock = _mockLive || (flavor != AppFlavor.client && _mockPending);
  return mock ? MockAuthRepository() : ApiAuthRepository(ref.watch(apiClientProvider), prefix: flavor.authPrefix);
});

final profileRepositoryProvider = Provider<ProfileRepository>(
  (ref) => _mockLive ? MockProfileRepository() : ApiProfileRepository(ref.watch(apiClientProvider)),
);

final deviceRepositoryProvider = Provider<DeviceRepository>(
  (ref) => _mockPending ? MockDeviceRepository() : ApiDeviceRepository(ref.watch(apiClientProvider)),
);

final bookingRepositoryProvider = Provider<BookingRepository>(
  (ref) => _mockLive ? MockBookingRepository() : ApiBookingRepository(ref.watch(apiClientProvider)),
);

final shopRepositoryProvider = Provider<ShopRepository>(
  (ref) => _mockPending ? MockShopRepository() : ApiShopRepository(ref.watch(apiClientProvider)),
);

final studioRepositoryProvider = Provider<StudioRepository>(
  (ref) => _mockPending ? MockStudioRepository() : ApiStudioRepository(ref.watch(apiClientProvider)),
);

final staffRepositoryProvider = Provider<StaffRepository>(
  (ref) => _mockPending ? MockStaffRepository() : ApiStaffRepository(ref.watch(apiClientProvider)),
);

final ownerRepositoryProvider = Provider<OwnerRepository>(
  (ref) => _mockPending ? MockOwnerRepository() : ApiOwnerRepository(ref.watch(apiClientProvider)),
);

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

class SessionState {
  const SessionState({this.token, this.profile});

  final String? token;

  /// Profile returned at sign-in; refreshed by [meProvider].
  final ClientProfile? profile;

  bool get isSignedIn => token != null && token!.isNotEmpty;
}

class SessionController extends Notifier<SessionState> {
  @override
  SessionState build() => SessionState(token: ref.read(preloadedProvider).token);

  Future<void> signIn(AuthResult result) async {
    await ref.read(tokenStoreProvider).write(result.token);
    state = SessionState(token: result.token, profile: result.client);
  }

  /// Called on 401 — token is no longer valid.
  Future<void> expire() async {
    if (!state.isSignedIn) return;
    await ref.read(tokenStoreProvider).clear();
    state = const SessionState();
  }

  Future<void> signOut() async {
    try {
      await ref.read(authRepositoryProvider).logout();
    } catch (_) {
      // Token may already be invalid; we clear locally regardless.
    }
    await ref.read(tokenStoreProvider).clear();
    state = const SessionState();
  }
}

final sessionProvider = NotifierProvider<SessionController, SessionState>(SessionController.new);

/// Current client profile (null when signed out).
final meProvider = FutureProvider<ClientProfile?>((ref) async {
  final session = ref.watch(sessionProvider);
  if (!session.isSignedIn) return null;
  return ref.watch(profileRepositoryProvider).me();
});

// ---------------------------------------------------------------------------
// Catalog data
// ---------------------------------------------------------------------------

final salonProvider = FutureProvider<Salon>((ref) {
  ref.watch(localeProvider);
  ref.watch(tenantProvider);
  return ref.watch(catalogRepositoryProvider).salon();
});

final branchesProvider = FutureProvider<List<Branch>>((ref) {
  ref.watch(localeProvider);
  return ref.watch(catalogRepositoryProvider).branches();
});

final categoriesProvider = FutureProvider<List<ServiceCategory>>((ref) {
  ref.watch(localeProvider);
  return ref.watch(catalogRepositoryProvider).categories();
});

class ServicesQuery {
  const ServicesQuery({this.branchId, this.categoryId, this.query});

  final int? branchId;
  final int? categoryId;
  final String? query;

  @override
  bool operator ==(Object other) =>
      other is ServicesQuery && other.branchId == branchId && other.categoryId == categoryId && other.query == query;

  @override
  int get hashCode => Object.hash(branchId, categoryId, query);
}

final servicesProvider = FutureProvider.family<List<SalonService>, ServicesQuery>((ref, q) {
  ref.watch(localeProvider);
  return ref.watch(catalogRepositoryProvider).services(branchId: q.branchId, categoryId: q.categoryId, query: q.query);
});

final serviceProvider = FutureProvider.family<SalonService, int>((ref, id) {
  ref.watch(localeProvider);
  return ref.watch(catalogRepositoryProvider).service(id);
});

class StaffQuery {
  const StaffQuery({this.branchId, this.serviceId});

  final int? branchId;
  final int? serviceId;

  @override
  bool operator ==(Object other) => other is StaffQuery && other.branchId == branchId && other.serviceId == serviceId;

  @override
  int get hashCode => Object.hash(branchId, serviceId);
}

final staffProvider = FutureProvider.family<List<StaffMember>, StaffQuery>((ref, q) {
  return ref.watch(catalogRepositoryProvider).staff(branchId: q.branchId, serviceId: q.serviceId);
});

/// True when the salon's plan includes [module]. Optimistic while loading.
final moduleEnabledProvider = Provider.family<bool, String>((ref, module) {
  final salon = ref.watch(salonProvider);
  return salon.when(data: (s) => s.hasModule(module), loading: () => true, error: (_, _) => true);
});
