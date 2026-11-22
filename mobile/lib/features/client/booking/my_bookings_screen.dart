import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../../app/providers.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/states.dart';
import '../../../data/models/booking.dart';
import '../../../data/repositories/booking_repository.dart';
import '../../../l10n/l10n.dart';
import 'booking_flow_controller.dart';
import 'slot_picker.dart';

class MyBookingsScreen extends ConsumerWidget {
  const MyBookingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final bookings = ref.watch(myBookingsProvider);

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(l10n.myBookings),
          bottom: TabBar(
            tabs: [
              Tab(text: l10n.upcoming),
              Tab(text: l10n.past),
            ],
          ),
        ),
        body: AsyncValueView<List<Booking>>(
          value: bookings,
          onRetry: () => ref.invalidate(myBookingsProvider),
          data: (list) {
            final upcoming = list.where((b) => b.isUpcoming).toList()..sort((a, b) => a.startsAt.compareTo(b.startsAt));
            final past = list.where((b) => !b.isUpcoming).toList()..sort((a, b) => b.startsAt.compareTo(a.startsAt));
            return TabBarView(
              children: [
                _BookingList(bookings: upcoming, upcoming: true),
                _BookingList(bookings: past, upcoming: false),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _BookingList extends ConsumerWidget {
  const _BookingList({required this.bookings, required this.upcoming});

  final List<Booking> bookings;
  final bool upcoming;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    if (bookings.isEmpty) {
      return EmptyState(
        icon: Icons.event_available_outlined,
        title: upcoming ? l10n.noUpcomingTitle : l10n.noPastTitle,
        message: upcoming ? l10n.noUpcomingMessage : null,
        actionLabel: upcoming ? l10n.bookNow : null,
        onAction: upcoming ? () => context.push('/book') : null,
      );
    }
    return RefreshIndicator(
      onRefresh: () => ref.refresh(myBookingsProvider.future),
      child: ListView.separated(
        padding: const EdgeInsets.all(20),
        itemCount: bookings.length,
        separatorBuilder: (_, _) => const SizedBox(height: 12),
        itemBuilder: (_, i) => BookingCard(booking: bookings[i]),
      ),
    );
  }
}

class BookingCard extends ConsumerWidget {
  const BookingCard({super.key, required this.booking});

  final Booking booking;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    final (label, color) = switch (booking.status) {
      BookingStatus.confirmed => (l10n.statusConfirmed, tokens.success),
      BookingStatus.pending => (l10n.statusPending, tokens.goldDark),
      BookingStatus.checkedIn => (l10n.statusCheckedIn, tokens.success),
      BookingStatus.completed => (l10n.statusCompleted, tokens.muted),
      BookingStatus.cancelled => (l10n.statusCancelled, tokens.danger),
      BookingStatus.noShow => (l10n.statusNoShow, tokens.danger),
      BookingStatus.unknown => ('—', tokens.muted),
    };

    return LuxCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 56,
                padding: const EdgeInsets.symmetric(vertical: 8),
                decoration: BoxDecoration(color: tokens.rose.withValues(alpha: .5), borderRadius: BorderRadius.circular(12)),
                child: Column(
                  children: [
                    Text(context.weekdayShort(booking.startsAt), style: text.labelMedium),
                    Text('${booking.startsAt.day}', style: text.titleLarge),
                  ],
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(booking.title, style: text.titleMedium, maxLines: 2, overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 2),
                    Text(
                      [
                        context.time(booking.startsAt),
                        booking.branchName,
                        booking.items.firstOrNull?.staffName,
                      ].whereType<String>().join(' · '),
                      style: text.bodySmall,
                    ),
                  ],
                ),
              ),
              StatusPill(label: label, color: color),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              if (booking.isUpcoming && booking.canReschedule)
                Expanded(
                  child: OutlinedButton(onPressed: () => _reschedule(context, ref), child: Text(l10n.reschedule)),
                ),
              if (booking.isUpcoming && booking.canCancel) ...[
                const SizedBox(width: 8),
                Expanded(
                  child: TextButton(
                    style: TextButton.styleFrom(foregroundColor: tokens.danger),
                    onPressed: () => _cancel(context, ref),
                    child: Text(l10n.cancel),
                  ),
                ),
              ],
              if (!booking.isUpcoming)
                Expanded(
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.replay, size: 18),
                    onPressed: () {
                      final ids = booking.items.map((i) => i.serviceId).join(',');
                      final staff = booking.items.firstOrNull?.staffId;
                      context.push('/book?services=$ids&branch=${booking.branchId}${staff == null ? '' : '&staff=$staff'}');
                    },
                    label: Text(l10n.rebook),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final l10n = context.l10n;
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.cancelBookingTitle),
        content: Text(l10n.cancelBookingMessage),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(l10n.keepBooking)),
          TextButton(onPressed: () => Navigator.pop(context, true), child: Text(l10n.cancelBooking)),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(bookingRepositoryProvider).cancel(booking);
      Haptics.success();
      ref.invalidate(myBookingsProvider);
      if (context.mounted) context.showSnack(l10n.bookingCancelled);
    } catch (e) {
      if (context.mounted) context.showSnack(context.errorMessage(e));
    }
  }

  Future<void> _reschedule(BuildContext context, WidgetRef ref) async {
    final moved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => RescheduleSheet(booking: booking),
    );
    if (moved == true && context.mounted) {
      ref.invalidate(myBookingsProvider);
      context.showSnack(context.l10n.bookingRescheduled);
    }
  }
}

class RescheduleSheet extends ConsumerStatefulWidget {
  const RescheduleSheet({super.key, required this.booking});

  final Booking booking;

  @override
  ConsumerState<RescheduleSheet> createState() => _RescheduleSheetState();
}

class _RescheduleSheetState extends ConsumerState<RescheduleSheet> {
  late DateTime _date = DateTime(widget.booking.startsAt.year, widget.booking.startsAt.month, widget.booking.startsAt.day);
  TimeSlot? _slot;
  bool _busy = false;

  DateTime get _today {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day);
  }

  AvailabilityQuery get _query => AvailabilityQuery(
    branchId: widget.booking.branchId,
    serviceIds: widget.booking.items.map((i) => i.serviceId).toList(),
    staffId: widget.booking.items.firstOrNull?.staffId,
    date: availabilityDate(_date.isBefore(_today) ? _today : _date),
  );

  Future<void> _confirm() async {
    if (_slot == null) return;
    setState(() => _busy = true);
    try {
      await ref.read(bookingRepositoryProvider).reschedule(widget.booking, _slot!.startsAt);
      Haptics.success();
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) context.showSnack(context.errorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: .8,
      maxChildSize: .95,
      builder: (context, scroll) => Column(
        children: [
          Text(l10n.reschedule, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 12),
          Expanded(
            child: ListView(
              controller: scroll,
              children: [
                SlotPicker(
                  today: _today,
                  date: _date.isBefore(_today) ? _today : _date,
                  availability: ref.watch(availabilityProvider(_query)),
                  selected: _slot,
                  onDateChanged: (d) => setState(() {
                    _date = d;
                    _slot = null;
                  }),
                  onSlotSelected: (s) => setState(() => _slot = s),
                  onRetry: () => ref.invalidate(availabilityProvider(_query)),
                ),
              ],
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: LuxButton(label: l10n.confirmNewTime, onPressed: _slot == null ? null : _confirm, busy: _busy),
            ),
          ),
        ],
      ),
    );
  }
}
