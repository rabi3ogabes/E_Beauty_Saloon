import 'package:add_2_calendar/add_2_calendar.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/flavor.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/states.dart';
import '../../../data/models/booking.dart';
import '../../../l10n/l10n.dart';
import 'booking_flow_controller.dart';

class BookingConfirmationScreen extends ConsumerWidget {
  const BookingConfirmationScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final booking = ref.watch(bookingFlowProvider).result;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final appName = ref.watch(tenantConfigProvider).appName;

    if (booking == null) {
      return Scaffold(
        appBar: AppBar(),
        body: EmptyState(
          icon: Icons.event_outlined,
          title: l10n.noBookingTitle,
          actionLabel: l10n.goHome,
          onAction: () => context.go('/home'),
        ),
      );
    }

    final awaitingPayment = booking.status == BookingStatus.pending && booking.payment?.checkoutUrl != null;

    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(24, 48, 24, 24),
          children: [
            TweenAnimationBuilder<double>(
              tween: Tween(begin: .6, end: 1),
              duration: const Duration(milliseconds: 600),
              curve: Curves.elasticOut,
              builder: (_, v, child) => Transform.scale(scale: v, child: child),
              child: Center(
                child: Container(
                  width: 96,
                  height: 96,
                  decoration: BoxDecoration(shape: BoxShape.circle, color: tokens.success.withValues(alpha: .12)),
                  child: Icon(Icons.check_rounded, size: 48, color: tokens.success),
                ),
              ),
            ),
            const SizedBox(height: 24),
            Text(
              awaitingPayment ? l10n.bookingPendingPayment : l10n.bookingConfirmed,
              style: text.headlineMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              l10n.bookingConfirmedSubtitle,
              style: text.bodyMedium?.copyWith(color: tokens.muted),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 28),
            LuxCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (booking.reference != null) Eyebrow(booking.reference!),
                  const SizedBox(height: 8),
                  Text(booking.title, style: text.titleLarge),
                  const SizedBox(height: 12),
                  _line(context, Icons.event_outlined, '${context.longDate(booking.startsAt)} · ${context.time(booking.startsAt)}'),
                  if (booking.branchName != null) _line(context, Icons.storefront_outlined, booking.branchName!),
                  _line(context, Icons.payments_outlined, context.money(booking.total)),
                ],
              ),
            ),
            const SizedBox(height: 24),
            if (awaitingPayment) ...[
              LuxButton(
                label: l10n.completePayment,
                variant: LuxButtonVariant.gold,
                onPressed: () => launchUrl(Uri.parse(booking.payment!.checkoutUrl!), mode: LaunchMode.inAppBrowserView),
              ),
              const SizedBox(height: 12),
            ],
            LuxButton(
              label: l10n.addToCalendar,
              icon: Icons.calendar_month_outlined,
              variant: LuxButtonVariant.secondary,
              onPressed: () => Add2Calendar.addEvent2Cal(
                Event(
                  title: '${booking.title} — $appName',
                  location: booking.branchName,
                  startDate: booking.startsAt,
                  endDate: booking.endsAt,
                  description: booking.reference,
                ),
              ),
            ),
            const SizedBox(height: 12),
            LuxButton(label: l10n.viewMyBookings, onPressed: () => context.go('/bookings')),
            const SizedBox(height: 4),
            LuxButton(label: l10n.goHome, variant: LuxButtonVariant.ghost, onPressed: () => context.go('/home')),
          ],
        ),
      ),
    );
  }

  Widget _line(BuildContext context, IconData icon, String value) => Padding(
    padding: const EdgeInsets.only(top: 8),
    child: Row(
      children: [
        Icon(icon, size: 18, color: LuxeTokens.of(context).goldDark),
        const SizedBox(width: 10),
        Expanded(child: Text(value, style: Theme.of(context).textTheme.bodyLarge)),
      ],
    ),
  );
}
