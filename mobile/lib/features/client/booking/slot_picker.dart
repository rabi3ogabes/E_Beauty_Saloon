import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/skeleton.dart';
import '../../../core/widgets/states.dart';
import '../../../data/models/booking.dart';
import '../../../l10n/l10n.dart';

/// Horizontal strip of upcoming days.
class DateStrip extends StatefulWidget {
  const DateStrip({super.key, required this.start, required this.selected, required this.onSelected, this.days = 21});

  final DateTime start;
  final DateTime selected;
  final ValueChanged<DateTime> onSelected;
  final int days;

  @override
  State<DateStrip> createState() => _DateStripState();
}

class _DateStripState extends State<DateStrip> {
  static const itemWidth = 60.0;
  late final ScrollController _scroll = ScrollController(initialScrollOffset: _offsetFor(widget.selected));

  double _offsetFor(DateTime d) {
    final i = DateTime(d.year, d.month, d.day).difference(widget.start).inDays;
    return ((i - 2).clamp(0, widget.days) * (itemWidth + 8)).toDouble();
  }

  @override
  void didUpdateWidget(DateStrip old) {
    super.didUpdateWidget(old);
    if (!_sameDay(old.selected, widget.selected) && _scroll.hasClients) {
      _scroll.animateTo(
        _offsetFor(widget.selected).clamp(0, _scroll.position.maxScrollExtent),
        duration: const Duration(milliseconds: 300),
        curve: Curves.easeOutCubic,
      );
    }
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    final scheme = Theme.of(context).colorScheme;
    final text = Theme.of(context).textTheme;
    final dark = Theme.of(context).brightness == Brightness.dark;

    return SizedBox(
      height: 84,
      child: ListView.separated(
        controller: _scroll,
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 20),
        itemCount: widget.days,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final day = widget.start.add(Duration(days: i));
          final selected = _sameDay(day, widget.selected);
          final fg = selected ? (dark ? scheme.onPrimary : LuxeColors.ivory) : scheme.onSurface;
          return Semantics(
            selected: selected,
            button: true,
            label: context.longDate(day),
            child: GestureDetector(
              key: Key('date-${day.year}-${day.month}-${day.day}'),
              onTap: () {
                Haptics.tap();
                widget.onSelected(day);
              },
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 180),
                width: itemWidth,
                decoration: BoxDecoration(
                  color: selected ? (dark ? tokens.gold : scheme.onSurface) : scheme.surface,
                  borderRadius: BorderRadius.circular(LuxeRadius.card),
                  border: Border.all(color: selected ? Colors.transparent : tokens.hairline),
                ),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(context.weekdayShort(day), style: text.labelMedium?.copyWith(color: fg.withValues(alpha: .75))),
                    const SizedBox(height: 4),
                    Text('${day.day}', style: text.titleLarge?.copyWith(color: fg, fontSize: 22)),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

bool _sameDay(DateTime a, DateTime b) => a.year == b.year && a.month == b.month && a.day == b.day;

/// Date strip + slots grouped into morning / afternoon / evening.
class SlotPicker extends StatelessWidget {
  const SlotPicker({
    super.key,
    required this.today,
    required this.date,
    required this.availability,
    required this.onDateChanged,
    required this.onSlotSelected,
    this.selected,
    this.onRetry,
  });

  final DateTime today;
  final DateTime date;
  final AsyncValue<Availability> availability;
  final ValueChanged<DateTime> onDateChanged;
  final ValueChanged<TimeSlot> onSlotSelected;
  final TimeSlot? selected;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DateStrip(start: today, selected: date, onSelected: onDateChanged),
        const SizedBox(height: 8),
        AnimatedSwitcher(
          duration: const Duration(milliseconds: 220),
          child: availability.when(
            skipLoadingOnReload: false,
            loading: () => const Padding(key: ValueKey('loading'), padding: EdgeInsets.all(20), child: SkeletonChips()),
            error: (e, _) => ErrorState(key: const ValueKey('error'), error: e, onRetry: onRetry, compact: true),
            data: (a) {
              if (a.isEmpty) {
                final next = a.nextAvailableDate == null ? null : DateTime.tryParse(a.nextAvailableDate!);
                return EmptyState(
                  key: const ValueKey('empty'),
                  compact: true,
                  icon: Icons.event_busy_outlined,
                  title: l10n.noSlotsTitle,
                  message: l10n.noSlotsMessage,
                  actionLabel: next == null ? null : l10n.jumpToDate(context.shortDate(next)),
                  onAction: next == null ? null : () => onDateChanged(next),
                );
              }
              return Column(
                key: ValueKey(a.date),
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final part in DayPart.values)
                    if (a.slots(part).isNotEmpty)
                      _SlotGroup(part: part, slots: a.slots(part), selected: selected, onSelected: onSlotSelected),
                ],
              );
            },
          ),
        ),
      ],
    );
  }
}

class _SlotGroup extends StatelessWidget {
  const _SlotGroup({required this.part, required this.slots, required this.selected, required this.onSelected});

  final DayPart part;
  final List<TimeSlot> slots;
  final TimeSlot? selected;
  final ValueChanged<TimeSlot> onSelected;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final label = switch (part) {
      DayPart.morning => l10n.morning,
      DayPart.afternoon => l10n.afternoon,
      DayPart.evening => l10n.evening,
    };
    final icon = switch (part) {
      DayPart.morning => Icons.wb_twilight_outlined,
      DayPart.afternoon => Icons.wb_sunny_outlined,
      DayPart.evening => Icons.nightlight_outlined,
    };
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 18, color: LuxeTokens.of(context).goldDark),
              const SizedBox(width: 8),
              Text(label, style: Theme.of(context).textTheme.titleSmall),
            ],
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final slot in slots)
                ChoiceChip(
                  key: Key('slot-${slot.startsAt.toIso8601String()}'),
                  label: Text(context.time(slot.startsAt)),
                  selected: slot == selected,
                  materialTapTargetSize: MaterialTapTargetSize.padded,
                  onSelected: (_) {
                    Haptics.tap();
                    onSelected(slot);
                  },
                ),
            ],
          ),
        ],
      ),
    );
  }
}
