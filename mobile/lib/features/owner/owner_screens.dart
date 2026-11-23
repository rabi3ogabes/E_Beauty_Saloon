import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/lux_widgets.dart';
import '../../core/widgets/skeleton.dart';
import '../../core/widgets/states.dart';
import '../../data/models/catalog.dart';
import '../../data/models/team.dart';
import '../../l10n/l10n.dart';

class OwnerBranchController extends Notifier<int?> {
  @override
  int? build() => null;

  void set(int? id) => state = id;
}

/// `null` = all branches.
final ownerBranchProvider = NotifierProvider<OwnerBranchController, int?>(OwnerBranchController.new);

final ownerKpisProvider = FutureProvider.autoDispose.family<OwnerKpis, int?>(
  (ref, branchId) => ref.watch(ownerRepositoryProvider).kpis(branchId: branchId),
);

final ownerAlertsProvider = FutureProvider.autoDispose<List<OwnerAlert>>((ref) => ref.watch(ownerRepositoryProvider).alerts());

class OwnerDashboardScreen extends ConsumerWidget {
  const OwnerDashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final branchId = ref.watch(ownerBranchProvider);
    final branches = ref.watch(branchesProvider).asData?.value ?? const <Branch>[];
    final kpis = ref.watch(ownerKpisProvider(branchId));
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.today)),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(ownerKpisProvider(branchId).future),
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            SizedBox(
              height: 48,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  ChoiceChip(
                    label: Text(l10n.allBranches),
                    selected: branchId == null,
                    onSelected: (_) => ref.read(ownerBranchProvider.notifier).set(null),
                  ),
                  for (final b in branches) ...[
                    const SizedBox(width: 8),
                    ChoiceChip(
                      label: Text(b.name),
                      selected: branchId == b.id,
                      onSelected: (_) => ref.read(ownerBranchProvider.notifier).set(b.id),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 16),
            kpis.when(
              loading: () => const Shimmer(
                child: Column(
                  children: [
                    SkeletonBox(height: 180, radius: LuxeRadius.card),
                    SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(child: SkeletonBox(height: 110, radius: LuxeRadius.card)),
                        SizedBox(width: 12),
                        Expanded(child: SkeletonBox(height: 110, radius: LuxeRadius.card)),
                      ],
                    ),
                  ],
                ),
              ),
              error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(ownerKpisProvider(branchId))),
              data: (k) {
                final change = k.revenueChange;
                return Column(
                  children: [
                    LuxCard(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Eyebrow(l10n.revenueToday),
                          const SizedBox(height: 8),
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Expanded(child: Text(context.money(k.revenueToday), style: text.displaySmall)),
                              if (change != null)
                                StatusPill(
                                  label: '${change >= 0 ? '▲' : '▼'} ${(change.abs() * 100).toStringAsFixed(0)}%',
                                  color: change >= 0 ? tokens.success : tokens.danger,
                                ),
                            ],
                          ),
                          Text(l10n.vsYesterday(context.money(k.revenueYesterday)), style: text.bodySmall),
                          const SizedBox(height: 16),
                          SizedBox(
                            height: 56,
                            child: _Sparkline(values: k.hourlyRevenue, color: tokens.gold),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(
                          child: _KpiTile(label: l10n.bookingsLabel, value: '${k.bookingsToday}', icon: Icons.event_available_outlined),
                        ),
                        const SizedBox(width: 12),
                        Expanded(child: _UtilizationTile(value: k.utilization)),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(
                          child: _KpiTile(label: l10n.newClients, value: '${k.newClients}', icon: Icons.person_add_alt_outlined),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _KpiTile(label: l10n.averageTicket, value: context.money(k.averageTicket), icon: Icons.receipt_outlined),
                        ),
                      ],
                    ),
                  ],
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _KpiTile extends StatelessWidget {
  const _KpiTile({required this.label, required this.value, required this.icon});

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return LuxCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: LuxeTokens.of(context).goldDark),
          const SizedBox(height: 12),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(value, style: text.headlineSmall),
          ),
          Text(label, style: text.bodySmall),
        ],
      ),
    );
  }
}

class _UtilizationTile extends StatelessWidget {
  const _UtilizationTile({required this.value});

  final double value;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    return LuxCard(
      child: Row(
        children: [
          SizedBox(
            width: 52,
            height: 52,
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: value),
              duration: const Duration(milliseconds: 900),
              curve: Curves.easeOutCubic,
              builder: (_, v, _) =>
                  CircularProgressIndicator(value: v, strokeWidth: 5, backgroundColor: tokens.hairline, color: tokens.gold),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${(value * 100).round()}%', style: text.headlineSmall),
                Text(context.l10n.utilization, style: text.bodySmall),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Sparkline extends StatelessWidget {
  const _Sparkline({required this.values, required this.color});

  final List<int> values;
  final Color color;

  @override
  Widget build(BuildContext context) => CustomPaint(painter: _SparkPainter(values, color), size: Size.infinite);
}

class _SparkPainter extends CustomPainter {
  _SparkPainter(this.values, this.color);

  final List<int> values;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    if (values.length < 2) return;
    final maxV = values.reduce(math.max).toDouble();
    if (maxV <= 0) return;
    final dx = size.width / (values.length - 1);
    final path = Path();
    for (var i = 0; i < values.length; i++) {
      final p = Offset(i * dx, size.height - values[i] / maxV * size.height);
      i == 0 ? path.moveTo(p.dx, p.dy) : path.lineTo(p.dx, p.dy);
    }
    final fill = Path.from(path)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(
      fill,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [color.withValues(alpha: .25), color.withValues(alpha: 0)],
        ).createShader(Offset.zero & size),
    );
    canvas.drawPath(
      path,
      Paint()
        ..color = color
        ..strokeWidth = 2
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round,
    );
  }

  @override
  bool shouldRepaint(_SparkPainter old) => old.values != values || old.color != color;
}

class OwnerAlertsScreen extends ConsumerWidget {
  const OwnerAlertsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final alerts = ref.watch(ownerAlertsProvider);
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.alerts)),
      body: AsyncValueView<List<OwnerAlert>>(
        value: alerts,
        onRetry: () => ref.invalidate(ownerAlertsProvider),
        data: (list) => list.isEmpty
            ? EmptyState(icon: Icons.notifications_off_outlined, title: l10n.noAlerts)
            : ListView.separated(
                padding: const EdgeInsets.all(20),
                itemCount: list.length,
                separatorBuilder: (_, _) => const SizedBox(height: 12),
                itemBuilder: (context, i) {
                  final a = list[i];
                  final color = switch (a.severity) {
                    AlertSeverity.critical => tokens.danger,
                    AlertSeverity.warning => tokens.goldDark,
                    AlertSeverity.info => tokens.success,
                  };
                  return LuxCard(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Container(
                          width: 4,
                          height: 44,
                          decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(4)),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(a.title, style: text.titleMedium),
                              if (a.body != null) Text(a.body!, style: text.bodyMedium),
                              if (a.createdAt != null) Text(context.time(a.createdAt!), style: text.bodySmall),
                            ],
                          ),
                        ),
                      ],
                    ),
                  );
                },
              ),
      ),
    );
  }
}

/// Quick campaign send — stub until the marketing API is specified.
class CampaignScreen extends ConsumerStatefulWidget {
  const CampaignScreen({super.key});

  @override
  ConsumerState<CampaignScreen> createState() => _CampaignScreenState();
}

class _CampaignScreenState extends ConsumerState<CampaignScreen> {
  String _channel = 'whatsapp';
  String _audience = 'all';
  final _message = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => _busy = true);
    try {
      await ref.read(ownerRepositoryProvider).sendCampaign(channel: _channel, audience: _audience, message: _message.text.trim());
      Haptics.success();
      if (!mounted) return;
      context.showSnack(context.l10n.campaignQueued);
      _message.clear();
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
      appBar: AppBar(title: Text(l10n.campaigns)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Eyebrow(l10n.channel),
          const SizedBox(height: 8),
          SegmentedButton<String>(
            segments: [
              ButtonSegment(value: 'whatsapp', label: Text(l10n.consentWhatsapp)),
              ButtonSegment(value: 'sms', label: Text(l10n.consentSms)),
              ButtonSegment(value: 'push', label: Text(l10n.consentPush)),
            ],
            selected: {_channel},
            onSelectionChanged: (s) => setState(() => _channel = s.first),
          ),
          const SizedBox(height: 20),
          Eyebrow(l10n.audience),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final (key, label) in [
                ('all', l10n.audienceAll),
                ('lapsed', l10n.audienceLapsed),
                ('vip', l10n.audienceVip),
                ('birthday', l10n.audienceBirthday),
              ])
                ChoiceChip(label: Text(label), selected: _audience == key, onSelected: (_) => setState(() => _audience = key)),
            ],
          ),
          const SizedBox(height: 20),
          TextField(
            controller: _message,
            maxLines: 5,
            maxLength: 480,
            decoration: InputDecoration(labelText: l10n.message, hintText: l10n.campaignHint),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          Text(l10n.consentRespectedNote, style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 20),
          LuxButton(
            label: l10n.sendCampaign,
            icon: Icons.send_outlined,
            busy: _busy,
            onPressed: _message.text.trim().length < 5 ? null : _send,
          ),
        ],
      ),
    );
  }
}
