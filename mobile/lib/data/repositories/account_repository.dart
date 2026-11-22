import '../../core/api/api_client.dart';
import '../models/account.dart';

/// Phone OTP sign-in.
abstract class AuthRepository {
  /// `POST {prefix}/otp` → 202.
  Future<void> requestOtp(String phone);

  /// `POST {prefix}/verify` → `{token, client}`.
  Future<AuthResult> verify({required String phone, required String code, String? name, required String deviceName});

  Future<void> logout();
}

class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository(this._api, {this.prefix = '/auth'});

  final ApiClient _api;

  /// `/auth` for clients. TODO(backend): `/staff/auth`, `/owner/auth` pending.
  final String prefix;

  @override
  Future<void> requestOtp(String phone) async {
    await _api.post('$prefix/otp', data: {'phone': phone});
  }

  @override
  Future<AuthResult> verify({required String phone, required String code, String? name, required String deviceName}) async {
    final body = await _api.post(
      '$prefix/verify',
      data: {
        'phone': phone,
        'code': code,
        if (name != null && name.trim().isNotEmpty) 'name': name.trim(),
        'device_name': deviceName,
      },
    );
    return AuthResult.fromJson(body is Map ? Map<String, dynamic>.from(body) : const {});
  }

  @override
  Future<void> logout() async {
    await _api.post('$prefix/logout');
  }
}

/// `/me*` endpoints (live).
abstract class ProfileRepository {
  Future<ClientProfile> me();
  Future<ClientProfile> update({String? name, String? email, String? locale, Map<String, bool>? consents});
  Future<Wallet> wallet();
  Future<LoyaltySummary> loyalty();
  Future<Map<String, dynamic>> export();
  Future<void> deleteAccount();
}

class ApiProfileRepository implements ProfileRepository {
  ApiProfileRepository(this._api);

  final ApiClient _api;

  @override
  Future<ClientProfile> me() async => ClientProfile.fromJson(unwrapMap(await _api.get('/me')));

  @override
  Future<ClientProfile> update({String? name, String? email, String? locale, Map<String, bool>? consents}) async {
    final body = await _api.patch(
      '/me',
      data: {
        'name': ?name,
        'email': ?email,
        'locale': ?locale,
        'consents': ?consents,
      },
    );
    return ClientProfile.fromJson(unwrapMap(body));
  }

  @override
  Future<Wallet> wallet() async => Wallet.fromJson(unwrapMap(await _api.get('/me/wallet')));

  @override
  Future<LoyaltySummary> loyalty() async => LoyaltySummary.fromJson(unwrapMap(await _api.get('/me/loyalty')));

  @override
  Future<Map<String, dynamic>> export() async {
    final body = await _api.get('/me/export');
    return body is Map ? Map<String, dynamic>.from(body) : {'data': body};
  }

  @override
  Future<void> deleteAccount() async {
    await _api.delete('/me');
  }
}

/// `POST /devices` — TODO(backend): FCM token registration endpoint in progress.
abstract class DeviceRepository {
  Future<void> register({required String token, required String platform, required String app});
}

class ApiDeviceRepository implements DeviceRepository {
  ApiDeviceRepository(this._api);

  final ApiClient _api;

  @override
  Future<void> register({required String token, required String platform, required String app}) async {
    await _api.post('/devices', data: {'token': token, 'platform': platform, 'app': app});
  }
}
