import '../../core/money/money.dart';
import 'json.dart';

/// `GET /salon`
class Salon {
  const Salon({
    required this.name,
    required this.slug,
    required this.currency,
    required this.timezone,
    this.locales = const ['en', 'ar'],
    this.modules = const [],
    this.branding = const Branding(),
  });

  factory Salon.fromJson(Json j) => Salon(
    name: asString(j['name']),
    slug: asString(j['slug']),
    currency: asString(j['currency'], 'QAR'),
    timezone: asString(j['timezone'], 'Asia/Qatar'),
    locales: j['locales'] == null ? const ['en', 'ar'] : asStringList(j['locales']),
    modules: asStringList(j['modules']),
    branding: Branding.fromJson(asJson(j['branding'])),
  );

  final String name;
  final String slug;
  final String currency;
  final String timezone;
  final List<String> locales;

  /// Entitled module keys: booking, pos, shop, marketing, ai_studio, website, loyalty.
  final List<String> modules;
  final Branding branding;

  bool hasModule(String key) => modules.contains(key);
}

class Branding {
  const Branding({
    this.primary,
    this.accent,
    this.ink,
    this.background,
    this.fontDisplay,
    this.fontBody,
    this.buttonStyle,
    this.about,
    this.logoUrl,
  });

  factory Branding.fromJson(Json j) => Branding(
    primary: asStringOrNull(j['primary']),
    accent: asStringOrNull(j['accent']),
    ink: asStringOrNull(j['ink']),
    background: asStringOrNull(j['background']),
    fontDisplay: asStringOrNull(j['font_display']),
    fontBody: asStringOrNull(j['font_body']),
    buttonStyle: asStringOrNull(j['button_style']),
    about: asStringOrNull(j['about']),
    logoUrl: asStringOrNull(j['logo_url']),
  );

  /// Hex strings like `#C8A96A`.
  final String? primary;
  final String? accent;
  final String? ink;
  final String? background;
  final String? fontDisplay;
  final String? fontBody;
  final String? buttonStyle;
  final String? about;
  final String? logoUrl;
}

/// `GET /branches`
class Branch {
  const Branch({
    required this.id,
    required this.name,
    this.slug = '',
    this.address,
    this.area,
    this.city,
    this.lat,
    this.lng,
    this.phone,
    this.whatsapp,
    this.isOpenNow = false,
    this.bookingEnabled = true,
    this.pickupEnabled = false,
    this.coverUrl,
  });

  factory Branch.fromJson(Json j) {
    final loc = asJson(j['location']);
    return Branch(
      id: asInt(j['id']),
      slug: asString(j['slug']),
      name: asString(j['name']),
      address: asStringOrNull(j['address']),
      area: asStringOrNull(j['area']),
      city: asStringOrNull(j['city']),
      lat: loc.isEmpty ? null : asDouble(loc['lat']),
      lng: loc.isEmpty ? null : asDouble(loc['lng']),
      phone: asStringOrNull(j['phone']),
      whatsapp: asStringOrNull(j['whatsapp']),
      isOpenNow: asBool(j['is_open_now']),
      bookingEnabled: asBool(j['booking_enabled'], true),
      pickupEnabled: asBool(j['pickup_enabled']),
      coverUrl: asStringOrNull(j['cover_url']),
    );
  }

  final int id;
  final String slug;
  final String name;
  final String? address;
  final String? area;
  final String? city;
  final double? lat;
  final double? lng;
  final String? phone;
  final String? whatsapp;
  final bool isOpenNow;
  final bool bookingEnabled;
  final bool pickupEnabled;
  final String? coverUrl;

  String get subtitle => [area, city].whereType<String>().where((s) => s.isNotEmpty).join(', ');
}

/// `GET /categories`
class ServiceCategory {
  const ServiceCategory({required this.id, required this.name, this.slug = '', this.icon});

  factory ServiceCategory.fromJson(Json j) => ServiceCategory(
    id: asInt(j['id']),
    slug: asString(j['slug']),
    name: asString(j['name']),
    icon: asStringOrNull(j['icon']),
  );

  final int id;
  final String slug;
  final String name;
  final String? icon;
}

class ServiceOption {
  const ServiceOption({required this.id, required this.name, required this.durationMinutes, required this.price});

  factory ServiceOption.fromJson(Json j, String currency) => ServiceOption(
    id: asInt(j['id']),
    name: asString(j['name']),
    durationMinutes: asInt(j['duration_minutes']),
    price: Money.fromJson(j['price'], fallbackCurrency: currency),
  );

  final int id;
  final String name;
  final int durationMinutes;
  final Money price;
}

/// `GET /services`, `GET /services/{id}`
class SalonService {
  const SalonService({
    required this.id,
    required this.name,
    required this.durationMinutes,
    required this.price,
    this.slug = '',
    this.categoryId,
    this.description,
    this.originalPrice,
    this.gender,
    this.imageUrl,
    this.onlineBookable = true,
    this.depositType,
    this.depositValue,
    this.variants = const [],
    this.addons = const [],
  });

  factory SalonService.fromJson(Json j) {
    final price = Money.fromJson(j['price']);
    final deposit = asJson(j['deposit']);
    return SalonService(
      id: asInt(j['id']),
      slug: asString(j['slug']),
      categoryId: asIntOrNull(j['category_id']),
      name: asString(j['name']),
      description: asStringOrNull(j['description']),
      durationMinutes: asInt(j['duration_minutes']),
      price: price,
      originalPrice: j['original_price'] == null ? null : Money.fromJson(j['original_price']),
      gender: asStringOrNull(j['gender']),
      imageUrl: asStringOrNull(j['image_url']),
      onlineBookable: asBool(j['online_bookable'], true),
      depositType: deposit.isEmpty ? null : asStringOrNull(deposit['type']),
      depositValue: deposit.isEmpty ? null : asIntOrNull(deposit['value']),
      variants: asJsonList(j['variants']).map((v) => ServiceOption.fromJson(v, price.currency)).toList(),
      addons: asJsonList(j['addons']).map((v) => ServiceOption.fromJson(v, price.currency)).toList(),
    );
  }

  final int id;
  final String slug;
  final int? categoryId;
  final String name;
  final String? description;
  final int durationMinutes;
  final Money price;
  final Money? originalPrice;
  final String? gender;
  final String? imageUrl;
  final bool onlineBookable;

  /// `percent` or `fixed` (minor units).
  final String? depositType;
  final int? depositValue;
  final List<ServiceOption> variants;
  final List<ServiceOption> addons;

  bool get onSale => originalPrice != null && originalPrice!.amount > price.amount;
}

/// `GET /staff`
class StaffMember {
  const StaffMember({
    required this.id,
    required this.name,
    this.title,
    this.bio,
    this.photoUrl,
    this.rating = 0,
    this.ratingsCount = 0,
    this.branchIds = const [],
    this.serviceIds = const [],
  });

  factory StaffMember.fromJson(Json j) => StaffMember(
    id: asInt(j['id']),
    name: asString(j['name']),
    title: asStringOrNull(j['title']),
    bio: asStringOrNull(j['bio']),
    photoUrl: asStringOrNull(j['photo_url']),
    rating: asDouble(j['rating']),
    ratingsCount: asInt(j['ratings_count']),
    branchIds: asIntList(j['branch_ids']),
    serviceIds: asIntList(j['service_ids']),
  );

  final int id;
  final String name;
  final String? title;
  final String? bio;
  final String? photoUrl;
  final double rating;
  final int ratingsCount;
  final List<int> branchIds;
  final List<int> serviceIds;

  String get initials {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    return parts.take(2).map((p) => String.fromCharCodes(p.runes.take(1)).toUpperCase()).join();
  }
}

/// `GET /packages` (pending backend).
class SalonPackage {
  const SalonPackage({
    required this.id,
    required this.name,
    required this.price,
    this.description,
    this.sessions = 1,
    this.validityDays,
    this.imageUrl,
  });

  factory SalonPackage.fromJson(Json j) => SalonPackage(
    id: asInt(j['id']),
    name: asString(j['name']),
    description: asStringOrNull(j['description']),
    price: Money.fromJson(j['price']),
    sessions: asInt(j['sessions'], 1),
    validityDays: asIntOrNull(j['validity_days']),
    imageUrl: asStringOrNull(j['image_url']),
  );

  final int id;
  final String name;
  final String? description;
  final Money price;
  final int sessions;
  final int? validityDays;
  final String? imageUrl;
}
