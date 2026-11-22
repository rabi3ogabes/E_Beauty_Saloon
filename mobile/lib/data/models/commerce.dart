import '../../core/money/money.dart';
import 'json.dart';

/// `GET /products` — TODO(backend): shop endpoints in progress.
class Product {
  const Product({
    required this.id,
    required this.name,
    required this.price,
    this.brand,
    this.description,
    this.imageUrl,
    this.inStock = true,
    this.originalPrice,
  });

  factory Product.fromJson(Json j) => Product(
    id: asInt(j['id']),
    name: asString(j['name']),
    brand: asStringOrNull(j['brand']),
    description: asStringOrNull(j['description']),
    price: Money.fromJson(j['price']),
    originalPrice: j['original_price'] == null ? null : Money.fromJson(j['original_price']),
    imageUrl: asStringOrNull(j['image_url']),
    inStock: asBool(j['in_stock'], true),
  );

  final int id;
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

/// `POST /orders` response.
class Order {
  const Order({required this.id, required this.total, this.reference, this.status, this.checkoutUrl});

  factory Order.fromJson(Json j) {
    final payment = asJson(j['payment']);
    return Order(
      id: asInt(j['id']),
      reference: asStringOrNull(j['reference']),
      status: asStringOrNull(j['status']),
      total: Money.fromJson(j['total']),
      checkoutUrl: asStringOrNull(j['checkout_url'] ?? payment['checkout_url']),
    );
  }

  final int id;
  final String? reference;
  final String? status;
  final Money total;
  final String? checkoutUrl;
}
