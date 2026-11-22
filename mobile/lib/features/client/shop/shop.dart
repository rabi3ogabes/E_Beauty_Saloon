import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/providers.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/skeleton.dart';
import '../../../core/widgets/states.dart';
import '../../../data/models/catalog.dart';
import '../../../data/models/commerce.dart';
import '../../../l10n/l10n.dart';

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

class CartController extends Notifier<List<CartLine>> {
  @override
  List<CartLine> build() => const [];

  void add(Product p, {int quantity = 1}) {
    final i = state.indexWhere((l) => l.product.id == p.id);
    if (i < 0) {
      state = [...state, CartLine(product: p, quantity: quantity)];
    } else {
      setQuantity(p.id, state[i].quantity + quantity);
    }
  }

  void setQuantity(int productId, int quantity) {
    state = [
      for (final l in state)
        if (l.product.id != productId) l else if (quantity > 0) l.withQuantity(quantity.clamp(1, 20)),
    ];
  }

  void clear() => state = const [];
}

final cartProvider = NotifierProvider<CartController, List<CartLine>>(CartController.new);

final cartCountProvider = Provider<int>((ref) => ref.watch(cartProvider).fold(0, (n, l) => n + l.quantity));

final cartTotalProvider = Provider<Money>((ref) {
  final lines = ref.watch(cartProvider);
  if (lines.isEmpty) return Money.zero();
  return lines.skip(1).fold(lines.first.total, (sum, l) => sum + l.total);
});

final productsProvider = FutureProvider<List<Product>>((ref) => ref.watch(shopRepositoryProvider).products());

final productProvider = FutureProvider.family<Product, int>((ref, id) => ref.watch(shopRepositoryProvider).product(id));

final packagesProvider = FutureProvider<List<SalonPackage>>((ref) => ref.watch(shopRepositoryProvider).packages());

// ---------------------------------------------------------------------------
// Screens
// ---------------------------------------------------------------------------

class ShopScreen extends ConsumerWidget {
  const ShopScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final products = ref.watch(productsProvider);
    final packages = ref.watch(packagesProvider);
    final count = ref.watch(cartCountProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.shop),
        actions: [
          IconButton(
            tooltip: l10n.cart,
            onPressed: () => context.push('/shop/cart'),
            icon: Badge.count(count: count, isLabelVisible: count > 0, child: const Icon(Icons.shopping_bag_outlined)),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(productsProvider);
          ref.invalidate(packagesProvider);
        },
        child: CustomScrollView(
          slivers: [
            if ((packages.asData?.value ?? const []).isNotEmpty) ...[
              SliverToBoxAdapter(
                child: SectionHeader(title: l10n.packages, padding: const EdgeInsets.fromLTRB(20, 8, 20, 12)),
              ),
              SliverToBoxAdapter(
                child: SizedBox(
                  height: 150,
                  child: ListView.separated(
                    padding: const EdgeInsets.symmetric(horizontal: 20),
                    scrollDirection: Axis.horizontal,
                    itemCount: packages.asData!.value.length,
                    separatorBuilder: (_, _) => const SizedBox(width: 12),
                    itemBuilder: (context, i) => SizedBox(width: 260, child: _PackageCard(package: packages.asData!.value[i])),
                  ),
                ),
              ),
            ],
            SliverToBoxAdapter(
              child: SectionHeader(title: l10n.products, padding: const EdgeInsets.fromLTRB(20, 24, 20, 12)),
            ),
            products.when(
              loading: () => SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                sliver: SliverGrid.count(
                  crossAxisCount: 2,
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  childAspectRatio: .68,
                  children: List.generate(4, (_) => const Shimmer(child: SkeletonBox(height: 240, radius: LuxeRadius.card))),
                ),
              ),
              error: (e, _) => SliverFillRemaining(
                child: ErrorState(error: e, onRetry: () => ref.invalidate(productsProvider)),
              ),
              data: (list) => SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                sliver: SliverGrid.builder(
                  itemCount: list.length,
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                    childAspectRatio: .68,
                  ),
                  itemBuilder: (_, i) => _ProductCard(product: list[i]),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PackageCard extends StatelessWidget {
  const _PackageCard({required this.package});

  final SalonPackage package;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    return LuxCard(
      color: tokens.rose.withValues(alpha: .35),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Eyebrow(context.l10n.sessionsCount(package.sessions)),
          const SizedBox(height: 6),
          Text(package.name, style: text.titleMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
          if (package.description != null) Text(package.description!, style: text.bodySmall, maxLines: 2, overflow: TextOverflow.ellipsis),
          const Spacer(),
          Text(context.money(package.price), style: text.titleMedium?.copyWith(color: tokens.goldDark)),
        ],
      ),
    );
  }
}

class _ProductCard extends ConsumerWidget {
  const _ProductCard({required this.product});

  final Product product;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    return LuxCard(
      padding: EdgeInsets.zero,
      onTap: () => context.push('/shop/product/${product.id}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: AppImage(url: product.imageUrl, width: double.infinity, fallbackIcon: Icons.local_mall_outlined),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 4, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (product.brand != null) Eyebrow(product.brand!),
                Text(product.name, style: text.titleSmall, maxLines: 2, overflow: TextOverflow.ellipsis),
                Row(
                  children: [
                    Expanded(
                      child: Text(context.money(product.price), style: text.bodyMedium?.copyWith(color: tokens.goldDark)),
                    ),
                    IconButton(
                      tooltip: context.l10n.addToCart,
                      onPressed: product.inStock
                          ? () {
                              Haptics.success();
                              ref.read(cartProvider.notifier).add(product);
                              context.showSnack(context.l10n.addedToCart(product.name));
                            }
                          : null,
                      icon: const Icon(Icons.add_circle_outline),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class ProductDetailScreen extends ConsumerStatefulWidget {
  const ProductDetailScreen({super.key, required this.productId});

  final int productId;

  @override
  ConsumerState<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends ConsumerState<ProductDetailScreen> {
  int _qty = 1;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final product = ref.watch(productProvider(widget.productId));
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    return Scaffold(
      appBar: AppBar(),
      body: AsyncValueView<Product>(
        value: product,
        onRetry: () => ref.invalidate(productProvider(widget.productId)),
        data: (p) => ListView(
          padding: const EdgeInsets.all(20),
          children: [
            AspectRatio(
              aspectRatio: 1,
              child: AppImage(url: p.imageUrl, fallbackIcon: Icons.local_mall_outlined),
            ),
            const SizedBox(height: 20),
            if (p.brand != null) Eyebrow(p.brand!),
            Text(p.name, style: text.headlineMedium),
            const SizedBox(height: 8),
            Text(context.money(p.price), style: text.titleLarge?.copyWith(color: tokens.goldDark)),
            if (p.description != null) ...[const SizedBox(height: 16), Text(p.description!, style: text.bodyLarge?.copyWith(height: 1.6))],
            const SizedBox(height: 20),
            if (!p.inStock) StatusPill(label: l10n.outOfStock, color: tokens.danger),
          ],
        ),
      ),
      bottomNavigationBar: product.asData == null
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
                child: Row(
                  children: [
                    _QtyStepper(value: _qty, onChanged: (v) => setState(() => _qty = v)),
                    const SizedBox(width: 12),
                    Expanded(
                      child: LuxButton(
                        label: l10n.addToCart,
                        onPressed: product.asData!.value.inStock
                            ? () {
                                Haptics.success();
                                ref.read(cartProvider.notifier).add(product.asData!.value, quantity: _qty);
                                context.pop();
                                context.showSnack(l10n.addedToCart(product.asData!.value.name));
                              }
                            : null,
                      ),
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}

class _QtyStepper extends StatelessWidget {
  const _QtyStepper({required this.value, required this.onChanged, this.min = 1});

  final int value;
  final int min;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: tokens.hairline),
        borderRadius: BorderRadius.circular(LuxeRadius.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconButton(onPressed: value > min ? () => onChanged(value - 1) : null, icon: const Icon(Icons.remove)),
          Text('$value', style: Theme.of(context).textTheme.titleMedium),
          IconButton(onPressed: value < 20 ? () => onChanged(value + 1) : null, icon: const Icon(Icons.add)),
        ],
      ),
    );
  }
}

class CartScreen extends ConsumerStatefulWidget {
  const CartScreen({super.key});

  @override
  ConsumerState<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends ConsumerState<CartScreen> {
  Fulfilment _fulfilment = Fulfilment.pickup;
  Branch? _branch;
  bool _busy = false;

  Future<void> _checkout() async {
    if (!ref.read(sessionProvider).isSignedIn) {
      context.push('/sign-in?from=${Uri.encodeComponent('/shop/cart')}');
      return;
    }
    setState(() => _busy = true);
    try {
      final order = await ref
          .read(shopRepositoryProvider)
          .placeOrder(
            lines: ref.read(cartProvider),
            fulfilment: _fulfilment,
            branchId: _branch?.id,
          );
      Haptics.success();
      ref.read(cartProvider.notifier).clear();
      if (order.checkoutUrl != null) {
        await launchUrl(Uri.parse(order.checkoutUrl!), mode: LaunchMode.inAppBrowserView);
      }
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(context.l10n.orderPlaced),
          content: Text(context.l10n.orderPlacedMessage(order.reference ?? '#${order.id}')),
          actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(context.l10n.done))],
        ),
      );
      if (mounted) context.go('/shop');
    } catch (e) {
      if (mounted) context.showSnack(context.errorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final lines = ref.watch(cartProvider);
    final total = ref.watch(cartTotalProvider);
    final branches = ref.watch(branchesProvider).asData?.value ?? const <Branch>[];
    final text = Theme.of(context).textTheme;
    _branch ??= branches.where((b) => b.pickupEnabled).firstOrNull ?? branches.firstOrNull;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.cart)),
      body: lines.isEmpty
          ? EmptyState(
              icon: Icons.shopping_bag_outlined,
              title: l10n.cartEmpty,
              actionLabel: l10n.browseShop,
              onAction: () => context.go('/shop'),
            )
          : ListView(
              padding: const EdgeInsets.all(20),
              children: [
                for (final l in lines)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: LuxCard(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        children: [
                          AppImage(url: l.product.imageUrl, width: 64, height: 64, fallbackIcon: Icons.local_mall_outlined),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(l.product.name, style: text.titleSmall),
                                Text(context.money(l.total), style: text.bodySmall),
                              ],
                            ),
                          ),
                          _QtyStepper(
                            value: l.quantity,
                            min: 0,
                            onChanged: (v) => ref.read(cartProvider.notifier).setQuantity(l.product.id, v),
                          ),
                        ],
                      ),
                    ),
                  ),
                SectionHeader(title: l10n.fulfilment, padding: const EdgeInsets.only(top: 12, bottom: 8)),
                SegmentedButton<Fulfilment>(
                  segments: [
                    ButtonSegment(value: Fulfilment.pickup, label: Text(l10n.pickup), icon: const Icon(Icons.storefront_outlined)),
                    ButtonSegment(value: Fulfilment.delivery, label: Text(l10n.delivery), icon: const Icon(Icons.local_shipping_outlined)),
                  ],
                  selected: {_fulfilment},
                  onSelectionChanged: (s) => setState(() => _fulfilment = s.first),
                ),
                if (_fulfilment == Fulfilment.pickup && branches.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  DropdownButtonFormField<int>(
                    initialValue: _branch?.id,
                    decoration: InputDecoration(labelText: l10n.pickupBranch),
                    items: [for (final b in branches) DropdownMenuItem(value: b.id, child: Text(b.name))],
                    onChanged: (id) => setState(() => _branch = branches.firstWhere((b) => b.id == id)),
                  ),
                ],
                const SizedBox(height: 20),
                Row(
                  children: [
                    Expanded(child: Text(l10n.total, style: text.titleLarge)),
                    Text(context.money(total), style: text.titleLarge),
                  ],
                ),
                const SizedBox(height: 8),
                Text(l10n.securePaymentNote, style: text.bodySmall),
              ],
            ),
      bottomNavigationBar: lines.isEmpty
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
                child: LuxButton(label: l10n.checkout, onPressed: _checkout, busy: _busy),
              ),
            ),
    );
  }
}
