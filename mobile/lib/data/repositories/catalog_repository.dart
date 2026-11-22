import '../../core/api/api_client.dart';
import '../models/catalog.dart';

/// Public, cacheable salon catalog (live endpoints, ETag-aware).
abstract class CatalogRepository {
  Future<Salon> salon();
  Future<List<Branch>> branches();
  Future<List<ServiceCategory>> categories();
  Future<List<SalonService>> services({int? branchId, int? categoryId, String? query});
  Future<SalonService> service(int id, {int? branchId});
  Future<List<StaffMember>> staff({int? branchId, int? serviceId});
}

class ApiCatalogRepository implements CatalogRepository {
  ApiCatalogRepository(this._api);

  final ApiClient _api;

  @override
  Future<Salon> salon() async => Salon.fromJson(unwrapMap(await _api.get('/salon')));

  @override
  Future<List<Branch>> branches() async => unwrapList(await _api.get('/branches')).map(Branch.fromJson).toList();

  @override
  Future<List<ServiceCategory>> categories() async => unwrapList(await _api.get('/categories')).map(ServiceCategory.fromJson).toList();

  @override
  Future<List<SalonService>> services({int? branchId, int? categoryId, String? query}) async {
    final body = await _api.get(
      '/services',
      query: {
        'branch_id': branchId,
        'category_id': categoryId,
        'q': query,
        'per_page': 100,
      },
    );
    return unwrapList(body).map(SalonService.fromJson).toList();
  }

  @override
  Future<SalonService> service(int id, {int? branchId}) async =>
      SalonService.fromJson(unwrapMap(await _api.get('/services/$id', query: {'branch_id': branchId})));

  @override
  Future<List<StaffMember>> staff({int? branchId, int? serviceId}) async {
    final body = await _api.get('/staff', query: {'branch_id': branchId, 'service_id': serviceId});
    return unwrapList(body).map(StaffMember.fromJson).toList();
  }
}
