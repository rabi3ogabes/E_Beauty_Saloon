import '../../core/money/money.dart';
import 'json.dart';

/// `GET /shop/products`, `GET /shop/products/{id|slug}` (ShopApiController).
class Product {
  const Product({
    required this.id,
    required this.name,
    required this.price,
    this.slug,
    this.defaultVariantId,
    this.brand,
    this.description,
    this.imageUrl,
    this.inStock = true,
    this.originalPrice,
  });

  factory Product.fromJson(Json j) {
    final currency = asStringOrNull(j['currency']) ?? 'QAR';
    final variants = asJsonList(j['variants']);
    return Product(
      id: asInt(j['id']),
      slug: asStringOrNull(j['slug']),
      name: asString(j['name']),
      brand: asStringOrNull(j['brand']),
      description: asStringOrNull(j['description']),
      // `price` is Money; `price_from` (minor units) is the legacy field.
      price: Money.fromJson(j['price'] ?? j['price_from'], fallbackCurrency: currency),
      originalPrice: j['original_price'] == null ? null : Money.fromJson(j['original_price'], fallbackCurrency: currency),
      imageUrl: asStringOrNull(j['image_url'] ?? j['image']),
      defaultVariantId: asIntOrNull(j['default_variant_id']) ?? (variants.isEmpty ? null : asIntOrNull(variants.first['id'])),
      inStock: asBool(j['in_stock'], true),
    );
  }

  final int id;
  final String? slug;

  /// Variant added to the cart / order (`items[].variant_id`).
  final int? defaultVariantId;
  final String name;
  final String? brand;
  final String? description;
  final Money price;
  final Money? originalPrice;
  final String? imageUrl;
  final bool inStock;
}

class CartLine {
  const CartLine({required this.product, required this.quantity});

  final Product product;
  final int quantity;

  Money get total => product.price.times(quantity);

  CartLine withQuantity(int q) => CartLine(product: product, quantity: q);
}

enum Fulfilment { delivery, pickup }

/// `POST /shop/orders` response.
class Order {
  const Order({required this.id, required this.total, this.reference, this.status, this.checkoutUrl});

  factory Order.fromJson(Json j) {
    final payment = asJson(j['payment']);
    return Order(
      id: asInt(j['id']),
      reference: asStringOrNull(j['reference'] ?? j['number']),
      status: asStringOrNull(j['status']),
      total: Money.fromJson(j['total'], fallbackCurrency: asStringOrNull(j['currency']) ?? 'QAR'),
      checkoutUrl: asStringOrNull(j['checkout_url'] ?? payment['checkout_url']),
    );
  }

  final int id;
  final String? reference;
  final String? status;
  final Money total;
  final String? checkoutUrl;
}
