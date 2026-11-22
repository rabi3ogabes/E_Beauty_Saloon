import 'dart:io';

import 'package:dio/dio.dart';

import '../../core/api/api_client.dart';
import '../models/catalog.dart';
import '../models/commerce.dart';
import '../models/studio.dart';

/// Shop & packages — TODO(backend): `GET /products`, `POST /orders`, `GET /packages` in progress.
abstract class ShopRepository {
  Future<List<Product>> products({String? query});
  Future<Product> product(int id);
  Future<Order> placeOrder({required List<CartLine> lines, required Fulfilment fulfilment, int? branchId, String? address});
  Future<List<SalonPackage>> packages();
}

class ApiShopRepository implements ShopRepository {
  ApiShopRepository(this._api);

  final ApiClient _api;

  @override
  Future<List<Product>> products({String? query}) async =>
      unwrapList(await _api.get('/products', query: {'q': query})).map(Product.fromJson).toList();

  @override
  Future<Product> product(int id) async => Product.fromJson(unwrapMap(await _api.get('/products/$id')));

  @override
  Future<Order> placeOrder({required List<CartLine> lines, required Fulfilment fulfilment, int? branchId, String? address}) async {
    final body = await _api.post(
      '/orders',
      data: {
        'items': [
          for (final l in lines) {'product_id': l.product.id, 'quantity': l.quantity},
        ],
        'fulfilment': fulfilment.name,
        'branch_id': ?branchId,
        'address': ?address,
      },
    );
    return Order.fromJson(unwrapMap(body));
  }

  @override
  Future<List<SalonPackage>> packages() async => unwrapList(await _api.get('/packages')).map(SalonPackage.fromJson).toList();
}

/// AI Studio — TODO(backend): `/studio/*` and `/looks` in progress.
abstract class StudioRepository {
  Future<List<StudioStyle>> styles({StudioCategory? category});

  /// Uploads the photo, returns the job id.
  Future<String> createJob({required File photo, required StudioCategory category, required int styleId});
  Future<StudioJob> job(String id);
  Future<List<Look>> looks();
}

class ApiStudioRepository implements StudioRepository {
  ApiStudioRepository(this._api);

  final ApiClient _api;

  @override
  Future<List<StudioStyle>> styles({StudioCategory? category}) async =>
      unwrapList(await _api.get('/studio/styles', query: {'category': category?.wire})).map(StudioStyle.fromJson).toList();

  @override
  Future<String> createJob({required File photo, required StudioCategory category, required int styleId}) async {
    final body = await _api.postMultipart('/studio/jobs', {
      'photo': await MultipartFile.fromFile(photo.path, filename: 'photo.jpg'),
      'category': category.wire,
      'style_id': styleId,
    });
    final map = unwrapMap(body);
    return '${map['id'] ?? map['job_id']}';
  }

  @override
  Future<StudioJob> job(String id) async => StudioJob.fromJson(unwrapMap(await _api.get('/studio/jobs/$id')));

  @override
  Future<List<Look>> looks() async => unwrapList(await _api.get('/looks')).map(Look.fromJson).toList();
}
