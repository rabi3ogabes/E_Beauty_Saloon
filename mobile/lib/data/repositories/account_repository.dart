import '../../core/api/api_client.dart';
import '../models/account.dart';

/// Sign-in: phone OTP for clients, e-mail + password (+ authenticator code) for staff / owner.
abstract class AuthRepository {
  /// `POST {prefix}/otp` → 202 (client app).
  Future<void> requestOtp(String phone);

  /// `POST {prefix}/verify` → `{token, client}` (client app).
  Future<AuthResult> verify({required String phone, required String code, String? name, required String deviceName});

  /// `POST {prefix}/login` → `{token, user}` (staff / owner apps). Throws `ApiException` with
  /// `code == 'mfa_required'` when the account has app MFA and [code] was not sent.
  Future<AuthResult> signInWithPassword({required String email, required String password, String? code, required String deviceName});

  Future<void> logout();
}

class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository(this._api, {this.prefix = '/auth'});

  final ApiClient _api;

  /// `/auth` (clients), `/staff/auth`, `/owner/auth` — see `AppFlavor.authPrefix`.
  final String prefix;

  @override
  Future<AuthResult> signInWithPassword({required String email, required String password, String? code, required String deviceName}) async {
    final body = await _api.post(
      '$prefix/login',
      data: {
        'email': email.trim(),
        'password': password,
        if (code != null && code.trim().isNotEmpty) 'code': code.trim(),
        'device_name': deviceName,
      },
    );
    return AuthResult.fromJson(body is Map ? Map<String, dynamic>.from(body) : const {});
  }

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

/// `POST /devices` (live) — FCM token of a client installation; `DELETE /devices/{token}` on sign-out.
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
