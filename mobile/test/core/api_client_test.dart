import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:luxe_salon/core/api/api_client.dart';
import 'package:luxe_salon/core/api/api_exception.dart';
import 'package:luxe_salon/core/storage/key_value_store.dart';
import 'package:luxe_salon/data/repositories/catalog_repository.dart';

/// Minimal Dio adapter: records requests and replies from a queue.
class MockAdapter implements HttpClientAdapter {
  final requests = <RequestOptions>[];
  final _replies = <ResponseBody Function(RequestOptions)>[];

  void reply(int status, Object? body, {Map<String, List<String>> headers = const {}}) {
    _replies.add(
      (_) => ResponseBody.fromString(
        body == null ? '' : jsonEncode(body),
        status,
        headers: {
          Headers.contentTypeHeader: ['application/json'],
          ...headers,
        },
      ),
    );
  }

  void replyWith(ResponseBody Function(RequestOptions) fn) => _replies.add(fn);

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    if (_replies.isEmpty) throw StateError('No reply queued for ${options.method} ${options.uri}');
    return _replies.removeAt(0)(options);
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late MockAdapter adapter;
  late TokenStore tokens;
  late ApiClient api;
  var unauthorizedCalls = 0;
  var locale = 'en';

  setUp(() {
    adapter = MockAdapter();
    tokens = TokenStore(MemoryKeyValueStore());
    unauthorizedCalls = 0;
    locale = 'en';
    api = ApiClient.create(
      baseUrl: 'https://lumiere.luxesalon.app/api/v1',
      tenant: () => 'lumiere',
      locale: () => locale,
      tokens: tokens,
      adapter: adapter,
      onUnauthorized: () => unauthorizedCalls++,
    );
  });

  test('sends tenant, language and JSON headers', () async {
    adapter.reply(200, {'data': []});
    locale = 'ar';
    await api.get('/branches');

    final h = adapter.requests.single.headers;
    expect(h['X-Tenant'], 'lumiere');
    expect(h['Accept-Language'], 'ar');
    expect(h['Accept'], 'application/json');
    expect(h.containsKey('Authorization'), isFalse);
    expect(adapter.requests.single.uri.toString(), 'https://lumiere.luxesalon.app/api/v1/branches');
  });

  test('adds the bearer token once signed in', () async {
    await tokens.write('1|abc');
    adapter.reply(200, {
      'data': {'id': 1},
    });
    await api.get('/me');
    expect(adapter.requests.single.headers['Authorization'], 'Bearer 1|abc');
  });

  test('maps 422 to a validation ApiException with field errors', () async {
    adapter.reply(422, {
      'message': 'The code field must be 6 digits.',
      'errors': {
        'code': ['The code field must be 6 digits.'],
      },
    });

    await expectLater(
      api.post('/auth/verify', data: {'phone': '+974', 'code': '1'}),
      throwsA(
        isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiErrorKind.validation)
            .having((e) => e.statusCode, 'status', 422)
            .having((e) => e.firstError('code'), 'code error', 'The code field must be 6 digits.'),
      ),
    );
  });

  test('maps 403 with a module key to moduleDisabled', () async {
    adapter.reply(403, {'message': 'Not in your plan', 'module': 'shop'});
    await expectLater(
      api.get('/products'),
      throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.moduleDisabled).having((e) => e.module, 'module', 'shop')),
    );
  });

  test('401 with a token reports unauthorized', () async {
    await tokens.write('expired');
    adapter.reply(401, {'message': 'Unauthenticated.'});
    await expectLater(api.get('/me'), throwsA(isA<ApiException>().having((e) => e.isUnauthorized, 'unauthorized', isTrue)));
    expect(unauthorizedCalls, 1);
  });

  test('5xx maps to server error', () async {
    adapter.reply(503, null);
    await expectLater(api.get('/salon'), throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.server)));
  });

  test('connection errors map to network', () async {
    adapter.replyWith((o) => throw DioException.connectionError(requestOptions: o, reason: 'offline'));
    await expectLater(api.get('/salon'), throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.network)));
  });

  test('ETag: sends If-None-Match and serves the cached body on 304', () async {
    adapter.reply(
      200,
      {
        'data': {'name': 'Maison Lumière'},
      },
      headers: {
        'etag': ['W/"abc"'],
      },
    );
    adapter.reply(304, null);

    final first = await api.get('/salon');
    final second = await api.get('/salon');

    expect(adapter.requests[0].headers.containsKey('If-None-Match'), isFalse);
    expect(adapter.requests[1].headers['If-None-Match'], 'W/"abc"');
    expect(second, first);
    expect(unwrapMap(second)['name'], 'Maison Lumière');
  });

  test('list query params use Laravel array syntax and drop nulls', () async {
    adapter.reply(200, {
      'data': {'date': '2026-11-23', 'groups': {}},
    });
    await api.get(
      '/availability',
      query: {
        'branch_id': 1,
        'services': [101, 201],
        'staff_id': null,
        'date': '2026-11-23',
      },
    );
    final uri = adapter.requests.single.uri;
    expect(uri.queryParametersAll['services[]'], ['101', '201']);
    expect(uri.queryParameters.containsKey('staff_id'), isFalse);
    expect(uri.queryParameters['branch_id'], '1');
  });

  test('catalog repository parses services with money objects', () async {
    adapter.reply(200, {
      'data': [
        {
          'id': 101,
          'name': 'Signature Blow-dry',
          'duration_minutes': 45,
          'price': {'amount': 14950, 'currency': 'QAR', 'formatted': 'QAR 149.50'},
          'original_price': null,
          'variants': [],
        },
      ],
      'meta': {'current_page': 1},
    });
    final services = await ApiCatalogRepository(api).services(categoryId: 1);

    expect(services.single.name, 'Signature Blow-dry');
    expect(services.single.price.format('en'), 'QAR 149.50');
    expect(adapter.requests.single.uri.queryParameters['category_id'], '1');
  });
}
