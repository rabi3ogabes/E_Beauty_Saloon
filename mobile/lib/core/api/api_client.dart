import 'dart:math';

import 'package:dio/dio.dart';

import '../storage/key_value_store.dart';
import 'api_exception.dart';
import 'interceptors.dart';

/// Thin typed wrapper around Dio. All methods throw [ApiException] only.
class ApiClient {
  ApiClient(this.dio);

  factory ApiClient.create({
    required String baseUrl,
    required String Function() tenant,
    required String Function() locale,
    required TokenStore tokens,
    void Function()? onUnauthorized,
    HttpClientAdapter? adapter,
    EtagInterceptor? etagCache,
  }) {
    final dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 30),
        sendTimeout: const Duration(seconds: 60),
        responseType: ResponseType.json,
        // 304 is handled by the ETag interceptor.
        validateStatus: (s) => s != null && ((s >= 200 && s < 300) || s == 304),
      ),
    );
    if (adapter != null) dio.httpClientAdapter = adapter;
    dio.interceptors.addAll([
      TenantInterceptor(tenant: tenant, locale: locale),
      AuthInterceptor(tokens, onUnauthorized: onUnauthorized),
      etagCache ?? EtagInterceptor(),
      ErrorMappingInterceptor(),
    ]);
    return ApiClient(dio);
  }

  final Dio dio;

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) => _guard(() => dio.get<dynamic>(path, queryParameters: _clean(query)));

  /// [idempotencyKey] is sent as `Idempotency-Key`: the backend replays the first response on retries
  /// (POST /bookings, /shop/orders, purchases), so a flaky network never books twice.
  Future<dynamic> post(String path, {Object? data, Map<String, dynamic>? query, String? idempotencyKey}) => _guard(
    () => dio.post<dynamic>(
      path,
      data: data,
      queryParameters: _clean(query),
      options: idempotencyKey == null ? null : Options(headers: {'Idempotency-Key': idempotencyKey}),
    ),
  );

  Future<dynamic> patch(String path, {Object? data}) => _guard(() => dio.patch<dynamic>(path, data: data));

  Future<dynamic> delete(String path, {Object? data}) => _guard(() => dio.delete<dynamic>(path, data: data));

  /// `multipart/form-data` upload (AI Studio photos).
  Future<dynamic> postMultipart(String path, Map<String, dynamic> fields, {void Function(int, int)? onProgress}) =>
      _guard(() => dio.post<dynamic>(path, data: FormData.fromMap(fields), onSendProgress: onProgress));

  Future<dynamic> _guard(Future<Response<dynamic>> Function() call) async {
    try {
      final response = await call();
      return response.data;
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  /// Drops nulls/empty strings and encodes lists as `key[]` the way Laravel expects.
  static Map<String, dynamic>? _clean(Map<String, dynamic>? query) {
    if (query == null) return null;
    final out = <String, dynamic>{};
    query.forEach((key, value) {
      if (value == null) return;
      if (value is String && value.isEmpty) return;
      if (value is List) {
        if (value.isEmpty) return;
        out[key.endsWith('[]') ? key : '$key[]'] = value.map((v) => '$v').toList();
      } else {
        out[key] = '$value';
      }
    });
    return out;
  }
}

/// Unwraps Laravel's `{data: ...}` resource envelope.
/// Random 128-bit hex key for `Idempotency-Key` (one per user action, reused on retry).
String newIdempotencyKey() {
  final r = Random.secure();
  return List.generate(16, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
}

dynamic unwrapData(dynamic body) => body is Map && body.containsKey('data') ? body['data'] : body;

List<Map<String, dynamic>> unwrapList(dynamic body) {
  final data = unwrapData(body);
  if (data is List) return data.whereType<Map>().map((m) => Map<String, dynamic>.from(m)).toList();
  return const [];
}

Map<String, dynamic> unwrapMap(dynamic body) {
  final data = unwrapData(body);
  return data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
}
