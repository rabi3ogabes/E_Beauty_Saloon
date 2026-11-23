import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/lux_widgets.dart';
import '../../core/widgets/states.dart';
import '../../data/models/booking.dart';
import '../../data/models/team.dart';
import '../../l10n/l10n.dart';
import '../client/booking/slot_picker.dart';

DateTime _day(DateTime d) => DateTime(d.year, d.month, d.day);

class StaffDateController extends Notifier<DateTime> {
  @override
  DateTime build() => _day(DateTime.now());

  void set(DateTime d) => state = _day(d);
}

final staffDateProvider = NotifierProvider<StaffDateController, DateTime>(StaffDateController.new);

final staffCalendarProvider = FutureProvider.autoDispose.family<List<CalendarEntry>, DateTime>(
  (ref, date) => ref.watch(staffRepositoryProvider).calendar(date),
);

final commissionsProvider = FutureProvider.autoDispose<CommissionSummary>((ref) => ref.watch(staffRepositoryProvider).commissions());

/// Day view of my appointments.
class StaffCalendarScreen extends ConsumerWidget {
  const StaffCalendarScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final date = ref.watch(staffDateProvider);
    final entries = ref.watch(staffCalendarProvider(date));
    final today = _day(DateTime.now());

    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.myCalendar),
        actions: [
          IconButton(
            tooltip: l10n.today,
            onPressed: () => ref.read(staffDateProvider.notifier).set(today),
            icon: const Icon(Icons.today_outlined),
          ),
        ],
      ),
      body: Column(
        children: [
          const SizedBox(height: 8),
          DateStrip(
            start: today.subtract(const Duration(days: 3)),
            selected: date,
            days: 31,
            onSelected: ref.read(staffDateProvider.notifier).set,
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 4),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: Text(context.longDate(date), style: Theme.of(context).textTheme.titleMedium),
            ),
          ),
          Expanded(
            child: AsyncValueView<List<CalendarEntry>>(
              value: entries,
              onRetry: () => ref.invalidate(staffCalendarProvider(date)),
              data: (list) => list.isEmpty
                  ? EmptyState(icon: Icons.free_breakfast_outlined, title: l10n.noAppointments)
                  : RefreshIndicator(
                      onRefresh: () => ref.refresh(staffCalendarProvider(date).future),
                      child: ListView.builder(
                        padding: const EdgeInsets.fromLTRB(12, 8, 20, 24),
                        itemCount: list.length,
                        itemBuilder: (_, i) => _TimelineEntry(entry: list[i], isLast: i == list.length - 1),
                      ),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _TimelineEntry extends StatelessWidget {
  const _TimelineEntry({required this.entry, required this.isLast});

  final CalendarEntry entry;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final checkedIn = entry.status == BookingStatus.checkedIn;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 64,
            child: Padding(
              padding: const EdgeInsets.only(top: 16),
              child: Text(context.time(entry.startsAt), style: text.labelMedium, textAlign: TextAlign.center),
            ),
          ),
          Column(
            children: [
              const SizedBox(height: 18),
              Container(
                width: 10,
                height: 10,
                decoration: BoxDecoration(shape: BoxShape.circle, color: checkedIn ? tokens.success : tokens.gold),
              ),
              if (!isLast) Expanded(child: Container(width: 1, color: tokens.hairline)),
            ],
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: LuxCard(
                onTap: () => context.push('/calendar/booking/${entry.bookingId}', extra: entry),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(child: Text(entry.clientName, style: text.titleMedium)),
                        if (entry.isFirstVisit) StatusPill(label: context.l10n.firstVisit, color: tokens.goldDark),
                        if (checkedIn) StatusPill(label: context.l10n.statusCheckedIn, color: tokens.success),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(entry.services.join(' · '), style: text.bodyMedium),
                    Text('${context.time(entry.startsAt)} – ${context.time(entry.endsAt)}', style: text.bodySmall),
                    if (entry.clientNotes != null) ...[
                      const SizedBox(height: 8),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Icon(Icons.sticky_note_2_outlined, size: 16, color: tokens.goldDark),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(entry.clientNotes!, style: text.bodySmall, maxLines: 2, overflow: TextOverflow.ellipsis),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class StaffBookingDetailScreen extends ConsumerStatefulWidget {
  const StaffBookingDetailScreen({super.key, required this.entry});

  final CalendarEntry entry;

  @override
  ConsumerState<StaffBookingDetailScreen> createState() => _StaffBookingDetailScreenState();
}

class _StaffBookingDetailScreenState extends ConsumerState<StaffBookingDetailScreen> {
  late CalendarEntry _entry = widget.entry;
  bool _busy = false;

  Future<void> _checkIn() async {
    setState(() => _busy = true);
    try {
      await ref.read(staffRepositoryProvider).checkIn(_entry);
      Haptics.success();
      setState(() => _entry = _entry.copyWith(status: BookingStatus.checkedIn));
      ref.invalidate(staffCalendarProvider);
      if (mounted) context.showSnack(context.l10n.checkedInMessage(_entry.clientName));
    } catch (e) {
      if (mounted) context.showSnack(context.errorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final e = _entry;
    final checkedIn = e.status == BookingStatus.checkedIn || e.status == BookingStatus.completed;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.appointment)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(e.clientName, style: text.headlineMedium),
          if (e.clientPhone != null)
            Row(
              children: [
                Text(e.clientPhone!, style: text.bodyMedium, textDirection: TextDirection.ltr),
                IconButton(
                  tooltip: l10n.call,
                  icon: Icon(Icons.call_outlined, color: tokens.goldDark),
                  onPressed: () => launchUrl(Uri.parse('tel:${e.clientPhone}')),
                ),
                IconButton(
                  tooltip: l10n.chatWhatsapp,
                  icon: Icon(Icons.chat_outlined, color: tokens.goldDark),
                  onPressed: () => launchUrl(
                    Uri.parse('https://wa.me/${e.clientPhone!.replaceAll(RegExp(r'\D'), '')}'),
                    mode: LaunchMode.externalApplication,
                  ),
                ),
              ],
            ),
          const SizedBox(height: 16),
          LuxCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Eyebrow(l10n.services),
                const SizedBox(height: 6),
                for (final s in e.services) Text(s, style: text.titleMedium),
                const SizedBox(height: 12),
                Text('${context.longDate(e.startsAt)} · ${context.time(e.startsAt)} – ${context.time(e.endsAt)}', style: text.bodyMedium),
              ],
            ),
          ),
          const SizedBox(height: 16),
          LuxCard(
            color: tokens.rose.withValues(alpha: .3),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Eyebrow(l10n.clientNotes),
                const SizedBox(height: 6),
                Text(e.clientNotes ?? l10n.noClientNotes, style: text.bodyLarge),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
          child: LuxButton(
            label: checkedIn ? l10n.statusCheckedIn : l10n.checkIn,
            icon: checkedIn ? Icons.check : Icons.how_to_reg_outlined,
            onPressed: checkedIn ? null : _checkIn,
            busy: _busy,
          ),
        ),
      ),
    );
  }
}

class CommissionsScreen extends ConsumerWidget {
  const CommissionsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final summary = ref.watch(commissionsProvider);
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.commissions)),
      body: AsyncValueView<CommissionSummary>(
        value: summary,
        onRetry: () => ref.invalidate(commissionsProvider),
        data: (s) => ListView(
          padding: const EdgeInsets.all(20),
          children: [
            LuxCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Eyebrow(s.periodLabel),
                  const SizedBox(height: 8),
                  Text(context.money(s.total), style: text.displaySmall),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: _Metric(label: l10n.servicesLabel, value: context.money(s.servicesTotal)),
                      ),
                      Expanded(
                        child: _Metric(label: l10n.retail, value: context.money(s.productsTotal)),
                      ),
                      Expanded(
                        child: _Metric(label: l10n.tips, value: context.money(s.tips)),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            SectionHeader(title: l10n.recent, padding: const EdgeInsets.only(top: 24, bottom: 8)),
            for (final line in s.lines)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(line.label),
                subtitle: line.date == null ? null : Text(context.shortDate(line.date!)),
                trailing: Text(context.money(line.amount), style: text.titleSmall?.copyWith(color: tokens.success)),
              ),
          ],
        ),
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: text.labelMedium),
        const SizedBox(height: 2),
        Text(value, style: text.titleSmall),
      ],
    );
  }
}

class TimeOffScreen extends ConsumerStatefulWidget {
  const TimeOffScreen({super.key});

  @override
  ConsumerState<TimeOffScreen> createState() => _TimeOffScreenState();
}

class _TimeOffScreenState extends ConsumerState<TimeOffScreen> {
  DateTimeRange? _range;
  final _reason = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      firstDate: _day(now),
      lastDate: _day(now).add(const Duration(days: 365)),
      initialDateRange: _range,
    );
    if (range != null) setState(() => _range = range);
  }

  Future<void> _submit() async {
    if (_range == null) return;
    setState(() => _busy = true);
    try {
      await ref.read(staffRepositoryProvider).requestTimeOff(from: _range!.start, to: _range!.end, reason: _reason.text.trim());
      Haptics.success();
      if (!mounted) return;
      context.showSnack(context.l10n.timeOffSent);
      setState(() {
        _range = null;
        _reason.clear();
      });
    } catch (e) {
      if (mounted) context.showSnack(context.errorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Scaffold(
      appBar: AppBar(title: Text(l10n.timeOff)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(l10n.timeOffIntro, style: Theme.of(context).textTheme.bodyMedium),
          const SizedBox(height: 20),
          LuxCard(
            onTap: _pick,
            child: Row(
              children: [
                Icon(Icons.date_range_outlined, color: LuxeTokens.of(context).goldDark),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    _range == null ? l10n.chooseDates : '${context.shortDate(_range!.start)} → ${context.shortDate(_range!.end)}',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                const Icon(Icons.chevron_right),
              ],
            ),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _reason,
            maxLines: 3,
            decoration: InputDecoration(labelText: l10n.reasonOptional),
          ),
          const SizedBox(height: 24),
          LuxButton(label: l10n.sendRequest, onPressed: _range == null ? null : _submit, busy: _busy),
        ],
      ),
    );
  }
}
