import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/providers.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/skeleton.dart';
import '../../../core/widgets/states.dart';
import '../../../data/models/booking.dart';
import '../../../data/models/catalog.dart';
import '../../../l10n/l10n.dart';
import 'booking_flow_controller.dart';
import 'slot_picker.dart';

class BookingFlowScreen extends ConsumerStatefulWidget {
  const BookingFlowScreen({super.key, this.serviceIds = const [], this.branchId, this.staffId, this.resume = false});

  /// Continue the current draft (e.g. after signing in) instead of starting over.
  final bool resume;
  final List<int> serviceIds;
  final int? branchId;
  final int? staffId;

  @override
  ConsumerState<BookingFlowScreen> createState() => _BookingFlowScreenState();
}

class _BookingFlowScreenState extends ConsumerState<BookingFlowScreen> {
  late final Future<void> _starting;

  @override
  void initState() {
    super.initState();
    final notifier = ref.read(bookingFlowProvider.notifier);
    // Deferred: providers must not be modified while the widget tree is building.
    _starting = widget.resume
        ? Future<void>.value()
        : Future<void>(() => notifier.start(serviceIds: widget.serviceIds, branchId: widget.branchId, staffId: widget.staffId));
  }

  Future<void> _submit() async {
    final notifier = ref.read(bookingFlowProvider.notifier);
    if (!ref.read(sessionProvider).isSignedIn) {
      // After OTP the router redirects to the resumed draft; the user confirms again.
      context.push('/sign-in?from=${Uri.encodeComponent('/booking/resume')}');
      return;
    }
    final booking = await notifier.submit();
    if (!mounted) return;
    if (booking == null) {
      final error = ref.read(bookingFlowProvider).error;
      if (error != null) context.showSnack(context.errorMessage(error));
      return;
    }
    Haptics.success();
    final url = booking.payment?.checkoutUrl;
    if (url != null && ref.read(bookingFlowProvider).payment.isOnline) {
      // Hosted payment page (PCI scope stays with the gateway).
      await launchUrl(Uri.parse(url), mode: LaunchMode.inAppBrowserView);
    }
    if (mounted) context.go('/booking/confirmed');
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final draft = ref.watch(bookingFlowProvider);
    final notifier = ref.read(bookingFlowProvider.notifier);

    final titles = {
      BookingStep.branch: l10n.stepBranch,
      BookingStep.services: l10n.stepServices,
      BookingStep.staff: l10n.stepStaff,
      BookingStep.time: l10n.stepTime,
      BookingStep.review: l10n.stepReview,
    };

    return PopScope(
      canPop: draft.step == BookingStep.branch,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) notifier.back();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(titles[draft.step]!),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(3),
            child: TweenAnimationBuilder<double>(
              tween: Tween(end: (draft.step.index + 1) / BookingStep.values.length),
              duration: const Duration(milliseconds: 300),
              builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 2),
            ),
          ),
        ),
        body: FutureBuilder<void>(
          future: _starting,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const SkeletonList();
            if (snap.hasError) return ErrorState(error: snap.error!);
            return AnimatedSwitcher(
              duration: const Duration(milliseconds: 250),
              transitionBuilder: (child, anim) => FadeTransition(
                opacity: anim,
                child: SlideTransition(
                  position: Tween(begin: const Offset(.04, 0), end: Offset.zero).animate(anim),
                  child: child,
                ),
              ),
              child: KeyedSubtree(
                key: ValueKey(draft.step),
                child: switch (draft.step) {
                  BookingStep.branch => const _BranchStep(),
                  BookingStep.services => const _ServicesStep(),
                  BookingStep.staff => const _StaffStep(),
                  BookingStep.time => const _TimeStep(),
                  BookingStep.review => const _ReviewStep(),
                },
              ),
            );
          },
        ),
        bottomNavigationBar: _BottomBar(
          draft: draft,
          label: draft.step == BookingStep.review
              ? (draft.payment.isOnline ? l10n.confirmAndPay : l10n.confirmBooking)
              : l10n.continueLabel,
          onPressed: draft.canContinue ? (draft.step == BookingStep.review ? _submit : notifier.next) : null,
        ),
      ),
    );
  }
}

class _BottomBar extends StatelessWidget {
  const _BottomBar({required this.draft, required this.label, required this.onPressed});

  final BookingDraft draft;
  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    final text = Theme.of(context).textTheme;
    return Container(
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        border: Border(top: BorderSide(color: tokens.hairline)),
      ),
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            if (draft.services.isNotEmpty) ...[
              Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(context.money(draft.total), style: text.titleMedium),
                  Text(context.duration(draft.durationMinutes), style: text.bodySmall),
                ],
              ),
              const SizedBox(width: 16),
            ],
            Expanded(
              child: LuxButton(label: label, onPressed: onPressed, busy: draft.submitting),
            ),
          ],
        ),
      ),
    );
  }
}

class _BranchStep extends ConsumerWidget {
  const _BranchStep();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final draft = ref.watch(bookingFlowProvider);
    final branches = ref.watch(branchesProvider);
    return AsyncValueView<List<Branch>>(
      value: branches,
      onRetry: () => ref.invalidate(branchesProvider),
      data: (list) => ListView.separated(
        padding: const EdgeInsets.all(20),
        itemCount: list.length,
        separatorBuilder: (_, _) => const SizedBox(height: 12),
        itemBuilder: (context, i) {
          final b = list[i];
          return LuxCard(
            selected: draft.branch?.id == b.id,
            onTap: b.bookingEnabled
                ? () {
                    ref.read(bookingFlowProvider.notifier)
                      ..selectBranch(b)
                      ..next();
                  }
                : null,
            child: Row(
              children: [
                AppImage(url: b.coverUrl, width: 64, height: 64, fallbackIcon: Icons.storefront_outlined),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(b.name, style: Theme.of(context).textTheme.titleMedium),
                      if (b.subtitle.isNotEmpty) Text(b.subtitle, style: Theme.of(context).textTheme.bodySmall),
                      const SizedBox(height: 6),
                      StatusPill(
                        label: b.isOpenNow ? context.l10n.openNow : context.l10n.closedNow,
                        color: b.isOpenNow ? LuxeTokens.of(context).success : LuxeTokens.of(context).muted,
                      ),
                    ],
                  ),
                ),
                Icon(Icons.chevron_right, color: LuxeTokens.of(context).muted),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _ServicesStep extends ConsumerWidget {
  const _ServicesStep();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final draft = ref.watch(bookingFlowProvider);
    final services = ref.watch(servicesProvider(ServicesQuery(branchId: draft.branch?.id)));
    final categories = ref.watch(categoriesProvider).asData?.value ?? const <ServiceCategory>[];
    final tokens = LuxeTokens.of(context);

    return AsyncValueView<List<SalonService>>(
      value: services,
      onRetry: () => ref.invalidate(servicesProvider),
      data: (list) {
        final grouped = <int?, List<SalonService>>{};
        for (final s in list) {
          grouped.putIfAbsent(s.categoryId, () => []).add(s);
        }
        String catName(int? id) => categories.where((c) => c.id == id).map((c) => c.name).firstOrNull ?? context.l10n.otherServices;

        return ListView(
          padding: const EdgeInsets.only(bottom: 24),
          children: [
            for (final entry in grouped.entries) ...[
              SectionHeader(title: catName(entry.key), padding: const EdgeInsets.fromLTRB(20, 20, 20, 8)),
              for (final s in entry.value)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 5),
                  child: _ServiceSelectTile(
                    service: s,
                    selected: draft.services.any((x) => x.id == s.id),
                    variantId: draft.variants[s.id],
                    onTap: () => ref.read(bookingFlowProvider.notifier).toggleService(s),
                    onVariant: (v) => ref.read(bookingFlowProvider.notifier).selectVariant(s.id, v),
                    tokens: tokens,
                  ),
                ),
            ],
          ],
        );
      },
    );
  }
}

class _ServiceSelectTile extends StatelessWidget {
  const _ServiceSelectTile({
    required this.service,
    required this.selected,
    required this.onTap,
    required this.onVariant,
    required this.tokens,
    this.variantId,
  });

  final SalonService service;
  final bool selected;
  final int? variantId;
  final VoidCallback onTap;
  final ValueChanged<int> onVariant;
  final LuxeTokens tokens;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return LuxCard(
      selected: selected,
      shadow: false,
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(service.name, style: text.titleMedium),
                    const SizedBox(height: 4),
                    Text('${context.duration(service.durationMinutes)} · ${context.money(service.price)}', style: text.bodySmall),
                  ],
                ),
              ),
              AnimatedSwitcher(
                duration: const Duration(milliseconds: 180),
                child: Icon(
                  selected ? Icons.check_circle : Icons.add_circle_outline,
                  key: ValueKey(selected),
                  color: selected ? tokens.gold : tokens.muted,
                ),
              ),
            ],
          ),
          if (selected && service.variants.isNotEmpty) ...[
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final v in service.variants)
                  ChoiceChip(
                    label: Text('${v.name} · ${context.money(v.price)}'),
                    selected: (variantId ?? service.variants.first.id) == v.id,
                    onSelected: (_) => onVariant(v.id),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _StaffStep extends ConsumerWidget {
  const _StaffStep();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final draft = ref.watch(bookingFlowProvider);
    final serviceId = draft.services.length == 1 ? draft.services.first.id : null;
    final staff = ref.watch(staffProvider(StaffQuery(branchId: draft.branch?.id, serviceId: serviceId)));
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    void pick(StaffMember? s) {
      ref.read(bookingFlowProvider.notifier)
        ..selectStaff(s)
        ..next();
    }

    return AsyncValueView<List<StaffMember>>(
      value: staff,
      onRetry: () => ref.invalidate(staffProvider),
      data: (list) {
        final eligible = list.where((s) => draft.services.every((svc) => s.serviceIds.isEmpty || s.serviceIds.contains(svc.id))).toList();
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            LuxCard(
              selected: draft.staff == null,
              onTap: () => pick(null),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 26,
                    backgroundColor: tokens.rose,
                    child: Icon(Icons.auto_awesome, color: tokens.goldDark),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(l10n.noPreference, style: text.titleMedium),
                        Text(l10n.noPreferenceHint, style: text.bodySmall),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            for (final s in eligible) ...[
              LuxCard(
                selected: draft.staff?.id == s.id,
                onTap: () => pick(s),
                child: Row(
                  children: [
                    s.photoUrl == null
                        ? CircleAvatar(
                            radius: 26,
                            backgroundColor: tokens.rose,
                            child: Text(s.initials, style: text.titleMedium),
                          )
                        : SizedBox(width: 52, height: 52, child: AppImage(url: s.photoUrl, radius: 26)),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(s.name, style: text.titleMedium),
                          if (s.title != null) Text(s.title!, style: text.bodySmall),
                        ],
                      ),
                    ),
                    if (s.ratingsCount > 0) ...[
                      Icon(Icons.star_rounded, size: 18, color: tokens.gold),
                      const SizedBox(width: 2),
                      Text(s.rating.toStringAsFixed(1), style: text.labelLarge),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 12),
            ],
          ],
        );
      },
    );
  }
}

class _TimeStep extends ConsumerWidget {
  const _TimeStep();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final draft = ref.watch(bookingFlowProvider);
    final query = draft.availabilityQuery;
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final availability = query == null ? const AsyncValue<Availability>.loading() : ref.watch(availabilityProvider(query));

    return ListView(
      padding: const EdgeInsets.only(top: 16, bottom: 24),
      children: [
        SlotPicker(
          today: today,
          date: draft.date,
          availability: availability,
          selected: draft.slot,
          onDateChanged: ref.read(bookingFlowProvider.notifier).selectDate,
          onSlotSelected: ref.read(bookingFlowProvider.notifier).selectSlot,
          onRetry: query == null ? null : () => ref.invalidate(availabilityProvider(query)),
        ),
      ],
    );
  }
}

class _ReviewStep extends ConsumerStatefulWidget {
  const _ReviewStep();

  @override
  ConsumerState<_ReviewStep> createState() => _ReviewStepState();
}

class _ReviewStepState extends ConsumerState<_ReviewStep> {
  late final TextEditingController _notes = TextEditingController(text: ref.read(bookingFlowProvider).notes);

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final draft = ref.watch(bookingFlowProvider);
    final notifier = ref.read(bookingFlowProvider.notifier);
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final slot = draft.slot!;

    Widget row(IconData icon, String label, String value, BookingStep step) => InkWell(
      onTap: () => notifier.goTo(step),
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Row(
          children: [
            Icon(icon, size: 20, color: tokens.goldDark),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: text.labelMedium),
                  Text(value, style: text.bodyLarge),
                ],
              ),
            ),
            Icon(Icons.edit_outlined, size: 16, color: tokens.muted),
          ],
        ),
      ),
    );

    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        LuxCard(
          child: Column(
            children: [
              row(Icons.event_outlined, l10n.when, '${context.longDate(slot.startsAt)} · ${context.time(slot.startsAt)}', BookingStep.time),
              const Divider(),
              row(Icons.storefront_outlined, l10n.where, draft.branch!.name, BookingStep.branch),
              const Divider(),
              row(Icons.person_outline, l10n.withWhom, draft.staff?.name ?? l10n.noPreference, BookingStep.staff),
            ],
          ),
        ),
        const SizedBox(height: 16),
        LuxCard(
          child: Column(
            children: [
              for (final s in draft.services)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(
                    children: [
                      Expanded(child: Text(s.name, style: text.bodyLarge)),
                      Text(context.money(s.price), style: text.bodyLarge),
                    ],
                  ),
                ),
              const Divider(height: 24),
              Row(
                children: [
                  Expanded(child: Text(l10n.total, style: text.titleMedium)),
                  Text(context.money(draft.total), style: text.titleMedium),
                ],
              ),
            ],
          ),
        ),
        SectionHeader(title: l10n.paymentTitle, padding: const EdgeInsets.fromLTRB(4, 24, 4, 8)),
        RadioGroup<PaymentPreference>(
          groupValue: draft.payment,
          onChanged: (v) => v == null ? null : notifier.setPayment(v),
          child: Column(
            children: [
              for (final p in PaymentPreference.values)
                if (!(p == PaymentPreference.payAtSalon && draft.requiresDeposit))
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: LuxCard(
                      shadow: false,
                      selected: draft.payment == p,
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      onTap: () => notifier.setPayment(p),
                      child: Row(
                        children: [
                          Radio<PaymentPreference>(value: p),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(switch (p) {
                                  PaymentPreference.deposit => l10n.payDeposit,
                                  PaymentPreference.full => l10n.payFull,
                                  PaymentPreference.payAtSalon => l10n.payAtSalon,
                                }, style: text.titleSmall),
                                Text(switch (p) {
                                  PaymentPreference.deposit => l10n.payDepositHint(context.money(draft.deposit)),
                                  PaymentPreference.full => l10n.payFullHint(context.money(draft.total)),
                                  PaymentPreference.payAtSalon => l10n.payAtSalonHint,
                                }, style: text.bodySmall),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _notes,
          maxLines: 3,
          minLines: 2,
          decoration: InputDecoration(labelText: l10n.notesLabel, hintText: l10n.notesHint),
          onChanged: notifier.setNotes,
        ),
        const SizedBox(height: 12),
        Text(l10n.cancellationPolicy, style: text.bodySmall),
      ],
    );
  }
}
