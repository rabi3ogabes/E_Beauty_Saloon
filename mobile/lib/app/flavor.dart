import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/tenant_config.dart';
import '../config/tenant_config.g.dart';

/// Which of the three apps is running. Set by the entrypoint (`main_*.dart`).
enum AppFlavor {
  client,
  staff,
  owner;

  /// Path prefix of the auth endpoints for this audience.
  /// Client: `/auth/*` (live). Staff/owner: `/staff/auth/*`, `/owner/auth/*`
  /// TODO(backend): staff & owner OTP endpoints are not implemented yet.
  String get authPrefix => switch (this) {
    AppFlavor.client => '/auth',
    AppFlavor.staff => '/staff/auth',
    AppFlavor.owner => '/owner/auth',
  };
}

final flavorProvider = Provider<AppFlavor>((ref) => AppFlavor.client);

final tenantConfigProvider = Provider<TenantConfig>((ref) => generatedTenantConfig.resolve());
