import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../../app/providers.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/states.dart';
import '../../../data/models/catalog.dart';
import '../../../l10n/l10n.dart';

class ServicesScreen extends ConsumerStatefulWidget {
  const ServicesScreen({super.key, this.initialCategoryId});

  final int? initialCategoryId;

  @override
  ConsumerState<ServicesScreen> createState() => _ServicesScreenState();
}

class _ServicesScreenState extends ConsumerState<ServicesScreen> {
  late int? _category = widget.initialCategoryId;
  String _query = '';
  Timer? _debounce;

  @override
  void didUpdateWidget(ServicesScreen old) {
    super.didUpdateWidget(old);
    if (old.initialCategoryId != widget.initialCategoryId) _category = widget.initialCategoryId;
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final categories = ref.watch(categoriesProvider).asData?.value ?? const <ServiceCategory>[];
    final query = ServicesQuery(categoryId: _category, query: _query.isEmpty ? null : _query);
    final services = ref.watch(servicesProvider(query));

    return Scaffold(
      appBar: AppBar(title: Text(l10n.services)),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
            child: TextField(
              decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: l10n.searchServices),
              textInputAction: TextInputAction.search,
              onChanged: (v) {
                _debounce?.cancel();
                _debounce = Timer(const Duration(milliseconds: 350), () => setState(() => _query = v.trim()));
              },
            ),
          ),
          SizedBox(
            height: 56,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 4),
              children: [
                ChoiceChip(label: Text(l10n.all), selected: _category == null, onSelected: (_) => setState(() => _category = null)),
                for (final c in categories) ...[
                  const SizedBox(width: 8),
                  ChoiceChip(label: Text(c.name), selected: _category == c.id, onSelected: (_) => setState(() => _category = c.id)),
                ],
              ],
            ),
          ),
          Expanded(
            child: AsyncValueView<List<SalonService>>(
              value: services,
              onRetry: () => ref.invalidate(servicesProvider(query)),
              data: (list) => list.isEmpty
                  ? EmptyState(icon: Icons.search_off, title: l10n.noServicesFound)
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                      itemCount: list.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 12),
                      itemBuilder: (_, i) => ServiceTile(service: list[i]),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class ServiceTile extends StatelessWidget {
  const ServiceTile({super.key, required this.service});

  final SalonService service;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    return LuxCard(
      onTap: () => context.push('/services/${service.id}'),
      padding: const EdgeInsets.all(12),
      child: Row(
        children: [
          AppImage(url: service.imageUrl, width: 76, height: 76),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(service.name, style: text.titleMedium),
                const SizedBox(height: 4),
                Text(context.duration(service.durationMinutes), style: text.bodySmall),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(context.money(service.price), style: text.titleSmall?.copyWith(color: tokens.goldDark)),
                    if (service.onSale)
                      Text(context.money(service.originalPrice!), style: text.bodySmall?.copyWith(decoration: TextDecoration.lineThrough)),
                  ],
                ),
              ],
            ),
          ),
          Icon(Icons.chevron_right, color: tokens.muted),
        ],
      ),
    );
  }
}

class ServiceCard extends StatelessWidget {
  const ServiceCard({super.key, required this.service});

  final SalonService service;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return LuxCard(
      padding: EdgeInsets.zero,
      onTap: () => context.push('/services/${service.id}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          AppImage(url: service.imageUrl, height: 140, width: double.infinity, radius: LuxeRadius.card),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(service.name, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 4),
                Text('${context.duration(service.durationMinutes)} · ${context.money(service.price)}', style: text.bodySmall, maxLines: 1),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class ServiceDetailScreen extends ConsumerWidget {
  const ServiceDetailScreen({super.key, required this.serviceId});

  final int serviceId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final service = ref.watch(serviceProvider(serviceId));
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    return Scaffold(
      body: AsyncValueView<SalonService>(
        value: service,
        onRetry: () => ref.invalidate(serviceProvider(serviceId)),
        data: (s) => CustomScrollView(
          slivers: [
            SliverAppBar(
              expandedHeight: 300,
              pinned: true,
              flexibleSpace: FlexibleSpaceBar(background: AppImage(url: s.imageUrl, radius: 0)),
            ),
            SliverPadding(
              padding: const EdgeInsets.all(20),
              sliver: SliverList.list(
                children: [
                  Text(s.name, style: text.headlineMedium),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Icon(Icons.schedule, size: 18, color: tokens.muted),
                      Text(context.duration(s.durationMinutes), style: text.bodyMedium),
                      const SizedBox(width: 8),
                      Text(context.money(s.price), style: text.titleMedium?.copyWith(color: tokens.goldDark)),
                      if (s.onSale)
                        Text(context.money(s.originalPrice!), style: text.bodySmall?.copyWith(decoration: TextDecoration.lineThrough)),
                    ],
                  ),
                  if (s.description != null) ...[
                    const SizedBox(height: 20),
                    Text(s.description!, style: text.bodyLarge?.copyWith(height: 1.6)),
                  ],
                  if (s.variants.isNotEmpty) ...[
                    SectionHeader(title: l10n.options, padding: const EdgeInsets.only(top: 24, bottom: 8)),
                    for (final v in s.variants)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(v.name),
                        subtitle: Text(context.duration(v.durationMinutes)),
                        trailing: Text(context.money(v.price), style: text.titleSmall),
                      ),
                  ],
                  if (s.depositType != null) ...[
                    const SizedBox(height: 16),
                    Text(l10n.depositRequiredNote, style: text.bodySmall),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
          child: LuxButton(label: l10n.bookThisService, onPressed: () => context.push('/book?services=$serviceId')),
        ),
      ),
    );
  }
}
