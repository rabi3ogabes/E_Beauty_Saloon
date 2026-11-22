import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/flavor.dart';
import '../../../app/providers.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/skeleton.dart';
import '../../../data/models/catalog.dart';
import '../../../l10n/l10n.dart';
import '../../auth/brand_mark.dart';
import '../services/services_screen.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final me = ref.watch(meProvider).asData?.value ?? ref.watch(sessionProvider).profile;
    final salon = ref.watch(salonProvider).asData?.value;
    final config = ref.watch(tenantConfigProvider);
    final studioEnabled = ref.watch(moduleEnabledProvider('ai_studio'));

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(salonProvider);
          ref.invalidate(branchesProvider);
          ref.invalidate(categoriesProvider);
          ref.invalidate(servicesProvider);
        },
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(
              child: _Hero(
                greeting: me == null ? l10n.welcome : l10n.greeting(me.firstName),
                salonName: salon?.name ?? config.appName,
              ),
            ),
            const SliverToBoxAdapter(child: _Categories()),
            if (studioEnabled) const SliverToBoxAdapter(child: _TryALookCard()),
            SliverToBoxAdapter(
              child: SectionHeader(title: l10n.popularServices, actionLabel: l10n.seeAll, onAction: () => context.go('/services')),
            ),
            const SliverToBoxAdapter(child: _PopularServices()),
            SliverToBoxAdapter(child: SectionHeader(title: l10n.ourBranches)),
            const _Branches(),
            const SliverToBoxAdapter(child: SizedBox(height: 32)),
          ],
        ),
      ),
    );
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.greeting, required this.salonName});

  final String greeting;
  final String salonName;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [tokens.rose.withValues(alpha: .55), scheme.surfaceContainerLowest],
        ),
      ),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const BrandMark(size: 40),
                  const SizedBox(width: 12),
                  Expanded(child: Text(salonName, style: text.titleMedium)),
                  IconButton(
                    onPressed: () => context.push('/bookings'),
                    icon: const Icon(Icons.notifications_none_rounded),
                    tooltip: context.l10n.myBookings,
                  ),
                ],
              ),
              const SizedBox(height: 28),
              Eyebrow(greeting),
              const SizedBox(height: 8),
              Text(context.l10n.heroTitle, style: text.displaySmall),
              const SizedBox(height: 20),
              LuxButton(label: context.l10n.bookAppointment, icon: Icons.calendar_today_outlined, onPressed: () => context.push('/book')),
            ],
          ),
        ),
      ),
    );
  }
}

class _Categories extends ConsumerWidget {
  const _Categories();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final categories = ref.watch(categoriesProvider);
    return SizedBox(
      height: 76,
      child: categories.when(
        loading: () => const Padding(padding: EdgeInsets.fromLTRB(20, 16, 20, 0), child: SkeletonChips(count: 4)),
        error: (_, _) => const SizedBox.shrink(),
        data: (list) => ListView.separated(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
          scrollDirection: Axis.horizontal,
          itemCount: list.length,
          separatorBuilder: (_, _) => const SizedBox(width: 8),
          itemBuilder: (context, i) => ActionChip(
            label: Text(list[i].name),
            onPressed: () => context.go('/services?category=${list[i].id}'),
          ),
        ),
      ),
    );
  }
}

class _TryALookCard extends StatelessWidget {
  const _TryALookCard();

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 0),
      child: LuxCard(
        onTap: () => context.push('/studio'),
        padding: EdgeInsets.zero,
        color: LuxeColors.ink,
        child: Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(LuxeRadius.card),
            gradient: LinearGradient(colors: [LuxeColors.ink, Color.lerp(LuxeColors.ink, tokens.gold, .35)!]),
          ),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Eyebrow(l10n.aiStudio, color: tokens.gold),
                    const SizedBox(height: 6),
                    Text(l10n.tryALook, style: text.headlineSmall?.copyWith(color: LuxeColors.ivory)),
                    const SizedBox(height: 4),
                    Text(l10n.tryALookSubtitle, style: text.bodyMedium?.copyWith(color: LuxeColors.ivory.withValues(alpha: .75))),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              CircleAvatar(
                radius: 26,
                backgroundColor: tokens.gold,
                child: const Icon(Icons.auto_awesome, color: LuxeColors.ink),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _PopularServices extends ConsumerWidget {
  const _PopularServices();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final services = ref.watch(servicesProvider(const ServicesQuery()));
    return SizedBox(
      height: 236,
      child: services.when(
        loading: () => Shimmer(
          child: ListView.separated(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            scrollDirection: Axis.horizontal,
            itemCount: 3,
            separatorBuilder: (_, _) => const SizedBox(width: 12),
            itemBuilder: (_, _) => const SkeletonBox(width: 180, height: 236, radius: LuxeRadius.card),
          ),
        ),
        error: (e, _) => Center(child: Text(context.errorMessage(e))),
        data: (list) => ListView.separated(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          scrollDirection: Axis.horizontal,
          itemCount: list.length.clamp(0, 8),
          separatorBuilder: (_, _) => const SizedBox(width: 12),
          itemBuilder: (_, i) => SizedBox(width: 180, child: ServiceCard(service: list[i])),
        ),
      ),
    );
  }
}

class _Branches extends ConsumerWidget {
  const _Branches();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final branches = ref.watch(branchesProvider);
    return branches.when(
      loading: () => const SliverToBoxAdapter(child: SkeletonList(count: 2, itemHeight: 64, shrinkWrap: true)),
      error: (e, _) => SliverToBoxAdapter(
        child: Padding(padding: const EdgeInsets.all(20), child: Text(context.errorMessage(e))),
      ),
      data: (list) => SliverList.separated(
        itemCount: list.length,
        separatorBuilder: (_, _) => const SizedBox(height: 12),
        itemBuilder: (context, i) => Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          child: BranchTile(branch: list[i]),
        ),
      ),
    );
  }
}

class BranchTile extends StatelessWidget {
  const BranchTile({super.key, required this.branch});

  final Branch branch;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    return LuxCard(
      child: Row(
        children: [
          AppImage(url: branch.coverUrl, width: 56, height: 56, fallbackIcon: Icons.storefront_outlined),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(branch.name, style: text.titleMedium),
                Text(branch.subtitle, style: text.bodySmall),
                const SizedBox(height: 4),
                StatusPill(
                  label: branch.isOpenNow ? l10n.openNow : l10n.closedNow,
                  color: branch.isOpenNow ? tokens.success : tokens.muted,
                ),
              ],
            ),
          ),
          if (branch.whatsapp != null)
            IconButton(
              tooltip: l10n.chatWhatsapp,
              icon: Icon(Icons.chat_outlined, color: tokens.goldDark),
              onPressed: () => launchUrl(
                Uri.parse('https://wa.me/${branch.whatsapp!.replaceAll(RegExp(r'\D'), '')}'),
                mode: LaunchMode.externalApplication,
              ),
            ),
          IconButton(
            tooltip: l10n.bookHere,
            icon: Icon(Icons.arrow_forward, color: tokens.goldDark),
            onPressed: () => context.push('/book?branch=${branch.id}'),
          ),
        ],
      ),
    );
  }
}
