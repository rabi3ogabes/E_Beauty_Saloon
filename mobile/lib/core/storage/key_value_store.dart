import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Small async key/value store. Backed by the Keychain / EncryptedSharedPreferences
/// in the app and by memory in tests.
abstract class KeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
}

class SecureKeyValueStore implements KeyValueStore {
  SecureKeyValueStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) async {
    try {
      return await _storage.read(key: key);
    } catch (_) {
      // Keychain can throw after a device restore; treat as empty.
      return null;
    }
  }

  @override
  Future<void> write(String key, String? value) async {
    try {
      if (value == null) {
        await _storage.delete(key: key);
      } else {
        await _storage.write(key: key, value: value);
      }
    } catch (_) {
      // Best effort; nothing sensible to do here.
    }
  }
}

class MemoryKeyValueStore implements KeyValueStore {
  MemoryKeyValueStore([Map<String, String>? initial]) : _values = {...?initial};

  final Map<String, String> _values;

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String? value) async {
    if (value == null) {
      _values.remove(key);
    } else {
      _values[key] = value;
    }
  }
}

abstract final class StorageKeys {
  static const token = 'auth_token';
  static const locale = 'locale';
  static const themeMode = 'theme_mode';
  static const onboardingSeen = 'onboarding_seen';
  static const studioConsent = 'studio_consent';
  static const tenantOverride = 'tenant_override';
}

/// Holds the bearer token with an in-memory cache so the auth interceptor
/// does not hit the keychain on every request.
class TokenStore {
  TokenStore(this._store);

  final KeyValueStore _store;
  String? _cached;
  bool _loaded = false;

  Future<String?> read() async {
    if (!_loaded) {
      _cached = await _store.read(StorageKeys.token);
      _loaded = true;
    }
    return _cached;
  }

  Future<void> write(String token) async {
    _cached = token;
    _loaded = true;
    await _store.write(StorageKeys.token, token);
  }

  Future<void> clear() async {
    _cached = null;
    _loaded = true;
    await _store.write(StorageKeys.token, null);
  }
}
