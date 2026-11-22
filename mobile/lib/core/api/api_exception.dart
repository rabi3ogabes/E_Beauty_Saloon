import 'package:dio/dio.dart';

enum ApiErrorKind {
  network,
  timeout,
  cancelled,
  unauthorized,
  forbidden,

  /// 403 with a `module` key: the salon's plan does not include this module.
  moduleDisabled,
  notFound,
  validation,
  rateLimited,
  server,
  unknown,
}

/// Typed error produced by the API layer. UI code only ever sees this type.
class ApiException implements Exception {
  const ApiException({
    required this.kind,
    required this.message,
    this.statusCode,
    this.errors = const {},
    this.module,
    this.code,
  });

  final ApiErrorKind kind;
  final String message;
  final int? statusCode;

  /// Laravel validation errors: field => messages.
  final Map<String, List<String>> errors;

  /// Disabled module key (`booking`, `shop`, `ai_studio` …) for 403s.
  final String? module;

  /// Stable machine code from the API envelope (`error.code`): validation_failed, domain_error,
  /// module_disabled, mfa_required, invalid_credentials, …
  final String? code;

  String? firstError(String field) {
    final list = errors[field];
    return (list == null || list.isEmpty) ? null : list.first;
  }

  bool get isUnauthorized => kind == ApiErrorKind.unauthorized;

  factory ApiException.fromDio(DioException e) {
    if (e.error is ApiException) return e.error! as ApiException;

    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
        return const ApiException(kind: ApiErrorKind.timeout, message: 'The connection timed out.');
      case DioExceptionType.cancel:
        return const ApiException(kind: ApiErrorKind.cancelled, message: 'Request cancelled.');
      case DioExceptionType.connectionError:
        return const ApiException(kind: ApiErrorKind.network, message: 'No connection. Check your internet and try again.');
      case DioExceptionType.badCertificate:
        return const ApiException(kind: ApiErrorKind.network, message: 'Secure connection failed.');
      case DioExceptionType.badResponse:
      case DioExceptionType.unknown:
        break;
    }

    final response = e.response;
    if (response == null) {
      return ApiException(kind: ApiErrorKind.network, message: e.message ?? 'Network error.');
    }
    return ApiException.fromResponse(response.statusCode ?? 0, response.data);
  }

  factory ApiException.fromResponse(int status, Object? body) {
    final map = body is Map ? body : const {};
    final message = (map['message'] as String?)?.trim();
    final errors = <String, List<String>>{};
    final rawErrors = map['errors'];
    if (rawErrors is Map) {
      rawErrors.forEach((key, value) {
        if (value is List) {
          errors['$key'] = value.map((v) => '$v').toList();
        } else if (value != null) {
          errors['$key'] = ['$value'];
        }
      });
    }

    final kind = switch (status) {
      401 => ApiErrorKind.unauthorized,
      403 when map['module'] != null => ApiErrorKind.moduleDisabled,
      403 => ApiErrorKind.forbidden,
      404 => ApiErrorKind.notFound,
      422 => ApiErrorKind.validation,
      429 => ApiErrorKind.rateLimited,
      >= 500 => ApiErrorKind.server,
      _ => ApiErrorKind.unknown,
    };

    return ApiException(
      kind: kind,
      statusCode: status,
      message: (message == null || message.isEmpty) ? _defaultMessage(kind) : message,
      errors: errors,
      module: map['module'] as String?,
      code: (map['error'] is Map ? (map['error'] as Map)['code'] : map['code'])?.toString(),
    );
  }

  static String _defaultMessage(ApiErrorKind kind) => switch (kind) {
    ApiErrorKind.unauthorized => 'Please sign in again.',
    ApiErrorKind.forbidden => 'You do not have access to this.',
    ApiErrorKind.moduleDisabled => 'This feature is not available for this salon.',
    ApiErrorKind.notFound => 'Not found.',
    ApiErrorKind.validation => 'Please check the highlighted fields.',
    ApiErrorKind.rateLimited => 'Too many attempts. Please wait a moment.',
    ApiErrorKind.server => 'Something went wrong on our side. Please try again.',
    _ => 'Something went wrong.',
  };

  @override
  String toString() => 'ApiException($kind, $statusCode): $message';
}
