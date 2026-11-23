import 'dart:collection';

import 'package:dio/dio.dart';

import '../storage/key_value_store.dart';
import 'api_exception.dart';

/// Adds `X-Tenant`, `Accept-Language` and `Accept` to every request.
class TenantInterceptor extends Interceptor {
  TenantInterceptor({required this.tenant, required this.locale});

  /// Salon slug (read lazily so it can change at runtime, e.g. staff app salon switch).
  final String Function() tenant;

  /// Current UI language code (`en` / `ar`).
  final String Function() locale;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['X-Tenant'] = tenant();
    options.headers['Accept-Language'] = locale();
    options.headers['Accept'] = 'application/json';
    handler.next(options);
  }
}

/// Adds the Sanctum bearer token and reports 401s so the session can be cleared.
class AuthInterceptor extends Interceptor {
  AuthInterceptor(this.tokens, {this.onUnauthorized});

  final TokenStore tokens;
  final void Function()? onUnauthorized;

  @override
  Future<void> onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await tokens.read();
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final hadToken = err.requestOptions.headers.containsKey('Authorization');
    if (err.response?.statusCode == 401 && hadToken) {
      onUnauthorized?.call();
    }
    handler.next(err);
  }
}

/// In-memory ETag cache: sends `If-None-Match` and turns a `304` back into the
/// cached `200` body, so callers never see 304s.
class EtagInterceptor extends Interceptor {
  EtagInterceptor({this.maxEntries = 64});

  final int maxEntries;
  final LinkedHashMap<String, _CachedResponse> _cache = LinkedHashMap();

  static String _key(RequestOptions o) => '${o.headers['X-Tenant']}|${o.headers['Accept-Language']}|${o.uri}';

  int get length => _cache.length;

  void clear() => _cache.clear();

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    if (options.method.toUpperCase() == 'GET') {
      final cached = _cache[_key(options)];
      if (cached != null) {
        options.headers['If-None-Match'] = cached.etag;
      }
    }
    handler.next(options);
  }

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    final options = response.requestOptions;
    if (options.method.toUpperCase() == 'GET') {
      final key = _key(options);
      if (response.statusCode == 304) {
        final cached = _cache[key];
        if (cached != null) {
          handler.next(
            Response<dynamic>(
              requestOptions: options,
              data: cached.data,
              statusCode: 200,
              headers: response.headers,
              extra: {'etag_cache_hit': true},
            ),
          );
          return;
        }
      }
      final etag = response.headers.value('etag');
      if (etag != null && response.statusCode == 200) {
        _cache.remove(key);
        _cache[key] = _CachedResponse(etag, response.data);
        while (_cache.length > maxEntries) {
          _cache.remove(_cache.keys.first);
        }
      }
    }
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    // Some adapters surface 304 as an error depending on validateStatus.
    final response = err.response;
    if (response?.statusCode == 304) {
      final cached = _cache[_key(err.requestOptions)];
      if (cached != null) {
        handler.resolve(
          Response<dynamic>(
            requestOptions: err.requestOptions,
            data: cached.data,
            statusCode: 200,
            extra: {'etag_cache_hit': true},
          ),
        );
        return;
      }
    }
    handler.next(err);
  }
}

class _CachedResponse {
  _CachedResponse(this.etag, this.data);

  final String etag;
  final Object? data;
}

/// Converts every [DioException] into one carrying a typed [ApiException].
class ErrorMappingInterceptor extends Interceptor {
  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    if (err.error is ApiException) {
      handler.next(err);
      return;
    }
    handler.next(err.copyWith(error: ApiException.fromDio(err)));
  }
}
