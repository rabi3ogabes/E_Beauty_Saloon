import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../app/providers.dart';
import '../core/widgets/states.dart';
import '../data/models/team.dart';
import '../features/auth/otp_screen.dart';
import '../features/auth/phone_screen.dart';
import '../features/client/account/account_screens.dart';
import '../features/client/booking/booking_confirmation_screen.dart';
import '../features/client/booking/booking_flow_screen.dart';
import '../features/client/booking/my_bookings_screen.dart';
import '../features/client/home/home_screen.dart';
import '../features/client/onboarding/splash_onboarding.dart';
import '../features/client/services/services_screen.dart';
import '../features/client/shop/shop.dart';
import '../features/client/studio/studio.dart';
import '../features/common/settings_screen.dart';
import '../features/owner/owner_screens.dart';
import '../features/staff/staff_screens.dart';
import '../l10n/l10n.dart';
import 'app_shell.dart';

final rootNavigatorKey = GlobalKey<NavigatorState>(debugLabel: 'root');

/// The router of the running app (overridden per flavor in bootstrap).
final routerProvider = Provider<GoRouter>((ref) => throw UnimplementedError('routerProvider must be overridden'));

const _authRoutes = {'/sign-in', '/otp'};

List<int> _ids(String? csv) => (csv ?? '').split(',').map(int.tryParse).whereType<int>().toList();

/// Shared auth routes. After a successful verify the router redirect sends the user on.
List<RouteBase> _authRouteList() => [
  GoRoute(
    path: '/sign-in',
    builder: (_, s) => PhoneScreen(from: s.uri.queryParameters['from']),
  ),
  GoRoute(
    path: '/otp',
    builder: (_, s) => OtpScreen(phone: s.uri.queryParameters['phone'] ?? ''),
  ),
];

GoRouter _router(
  Ref ref, {
  required List<RouteBase> routes,
  required bool Function(String location) requiresAuth,
  required String home,
}) {
  final refresh = ValueNotifier<int>(0);
  ref.listen(sessionProvider, (prev, next) {
    if (prev?.isSignedIn != next.isSignedIn) refresh.value++;
  });
  ref.onDispose(refresh.dispose);

  return GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/splash',
    refreshListenable: refresh,
    redirect: (context, state) {
      final signedIn = ref.read(sessionProvider).isSignedIn;
      final loc = state.matchedLocation;
      if (_authRoutes.contains(loc)) {
        return signedIn ? (state.uri.queryParameters['from'] ?? home) : null;
      }
      if (!signedIn && requiresAuth(loc)) {
        return '/sign-in?from=${Uri.encodeComponent(state.uri.toString())}';
      }
      return null;
    },
    routes: [..._authRouteList(), ...routes],
    errorBuilder: (context, state) => Scaffold(
      appBar: AppBar(),
      body: EmptyState(
        icon: Icons.explore_off_outlined,
        title: context.l10n.pageNotFound,
        actionLabel: context.l10n.goHome,
        onAction: () => context.go(home),
      ),
    ),
  );
}

// ---------------------------------------------------------------------------
// Client (white-label)
// ---------------------------------------------------------------------------

final clientRouterProvider = Provider<GoRouter>((ref) {
  const protectedPrefixes = ['/bookings', '/wallet', '/loyalty', '/looks'];
  return _router(
    ref,
    home: '/home',
    requiresAuth: (loc) => protectedPrefixes.any(loc.startsWith),
    routes: [
      GoRoute(
        path: '/splash',
        builder: (_, _) => SplashScreen(next: (ref) => ref.read(preloadedProvider).onboardingSeen ? '/home' : '/onboarding'),
      ),
      GoRoute(path: '/onboarding', builder: (_, _) => const OnboardingScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => AppShell(
          shell: shell,
          destinations: [
            ShellDestination(icon: Icons.home_outlined, selectedIcon: Icons.home, label: context.l10n.tabHome),
            ShellDestination(icon: Icons.spa_outlined, selectedIcon: Icons.spa, label: context.l10n.tabServices),
            ShellDestination(icon: Icons.event_note_outlined, selectedIcon: Icons.event_note, label: context.l10n.tabBookings),
            ShellDestination(icon: Icons.shopping_bag_outlined, selectedIcon: Icons.shopping_bag, label: context.l10n.tabShop),
            ShellDestination(icon: Icons.person_outline, selectedIcon: Icons.person, label: context.l10n.tabProfile),
          ],
        ),
        branches: [
          StatefulShellBranch(
            routes: [GoRoute(path: '/home', builder: (_, _) => const HomeScreen())],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/services',
                builder: (_, s) => ServicesScreen(initialCategoryId: int.tryParse(s.uri.queryParameters['category'] ?? '')),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/bookings', builder: (_, _) => const MyBookingsScreen())],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/shop',
                builder: (_, _) => const _ModuleGate(module: 'shop', child: ShopScreen()),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/profile', builder: (_, _) => const ProfileScreen())],
          ),
        ],
      ),
      GoRoute(
        path: '/services/:id',
        builder: (_, s) => ServiceDetailScreen(serviceId: int.parse(s.pathParameters['id']!)),
      ),
      GoRoute(
        path: '/book',
        builder: (_, s) => _ModuleGate(
          module: 'booking',
          child: BookingFlowScreen(
            serviceIds: _ids(s.uri.queryParameters['services']),
            branchId: int.tryParse(s.uri.queryParameters['branch'] ?? ''),
            staffId: int.tryParse(s.uri.queryParameters['staff'] ?? ''),
          ),
        ),
      ),
      // Top-level (not nested under /book) so the parent does not restart the draft.
      GoRoute(path: '/booking/resume', builder: (_, _) => const BookingFlowScreen(resume: true)),
      GoRoute(path: '/booking/confirmed', builder: (_, _) => const BookingConfirmationScreen()),
      GoRoute(
        path: '/shop/product/:id',
        builder: (_, s) => ProductDetailScreen(productId: int.parse(s.pathParameters['id']!)),
      ),
      GoRoute(path: '/shop/cart', builder: (_, _) => const CartScreen()),
      GoRoute(
        path: '/studio',
        builder: (_, _) => const _ModuleGate(module: 'ai_studio', child: StudioScreen()),
        routes: [
          GoRoute(path: 'camera', builder: (_, _) => const StudioCameraScreen()),
          GoRoute(
            path: 'job/:id',
            builder: (_, s) => StudioJobScreen(jobId: s.pathParameters['id']!),
          ),
        ],
      ),
      GoRoute(path: '/looks', builder: (_, _) => const LooksScreen()),
      GoRoute(path: '/wallet', builder: (_, _) => const WalletScreen()),
      GoRoute(path: '/loyalty', builder: (_, _) => const LoyaltyScreen()),
    ],
  );
});

/// Hides a feature when the salon's plan does not include its module.
class _ModuleGate extends ConsumerWidget {
  const _ModuleGate({required this.module, required this.child});

  final String module;
  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (ref.watch(moduleEnabledProvider(module))) return child;
    return Scaffold(
      appBar: AppBar(),
      body: EmptyState(icon: Icons.lock_outline, title: context.l10n.moduleUnavailableTitle, message: context.l10n.errorModuleDisabled),
    );
  }
}

// ---------------------------------------------------------------------------
// Staff
// ---------------------------------------------------------------------------

final staffRouterProvider = Provider<GoRouter>((ref) {
  return _router(
    ref,
    home: '/calendar',
    requiresAuth: (loc) => loc != '/splash',
    routes: [
      GoRoute(
        path: '/splash',
        builder: (_, _) => SplashScreen(next: (ref) => ref.read(sessionProvider).isSignedIn ? '/calendar' : '/sign-in'),
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => AppShell(
          shell: shell,
          destinations: [
            ShellDestination(icon: Icons.calendar_today_outlined, selectedIcon: Icons.calendar_today, label: context.l10n.tabCalendar),
            ShellDestination(icon: Icons.payments_outlined, selectedIcon: Icons.payments, label: context.l10n.commissions),
            ShellDestination(icon: Icons.beach_access_outlined, selectedIcon: Icons.beach_access, label: context.l10n.timeOff),
            ShellDestination(icon: Icons.settings_outlined, selectedIcon: Icons.settings, label: context.l10n.settings),
          ],
        ),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/calendar',
                builder: (_, _) => const StaffCalendarScreen(),
                routes: [
                  GoRoute(
                    path: 'booking/:id',
                    parentNavigatorKey: rootNavigatorKey,
                    builder: (context, s) {
                      final entry = s.extra;
                      if (entry is CalendarEntry) return StaffBookingDetailScreen(entry: entry);
                      return Scaffold(
                        appBar: AppBar(),
                        body: EmptyState(icon: Icons.event_busy_outlined, title: context.l10n.pageNotFound),
                      );
                    },
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/commissions', builder: (_, _) => const CommissionsScreen())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/time-off', builder: (_, _) => const TimeOffScreen())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/settings', builder: (_, _) => const SettingsScreen())],
          ),
        ],
      ),
    ],
  );
});

// ---------------------------------------------------------------------------
// Owner
// ---------------------------------------------------------------------------

final ownerRouterProvider = Provider<GoRouter>((ref) {
  return _router(
    ref,
    home: '/dashboard',
    requiresAuth: (loc) => loc != '/splash',
    routes: [
      GoRoute(
        path: '/splash',
        builder: (_, _) => SplashScreen(next: (ref) => ref.read(sessionProvider).isSignedIn ? '/dashboard' : '/sign-in'),
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => AppShell(
          shell: shell,
          destinations: [
            ShellDestination(icon: Icons.insights_outlined, selectedIcon: Icons.insights, label: context.l10n.tabDashboard),
            ShellDestination(icon: Icons.notifications_none, selectedIcon: Icons.notifications, label: context.l10n.alerts),
            ShellDestination(icon: Icons.campaign_outlined, selectedIcon: Icons.campaign, label: context.l10n.campaigns),
            ShellDestination(icon: Icons.settings_outlined, selectedIcon: Icons.settings, label: context.l10n.settings),
          ],
        ),
        branches: [
          StatefulShellBranch(
            routes: [GoRoute(path: '/dashboard', builder: (_, _) => const OwnerDashboardScreen())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/alerts', builder: (_, _) => const OwnerAlertsScreen())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/campaigns', builder: (_, _) => const CampaignScreen())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/settings', builder: (_, _) => const SettingsScreen())],
          ),
        ],
      ),
    ],
  );
});
