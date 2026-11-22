import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';
import 'package:share_plus/share_plus.dart';

import '../../../app/flavor.dart';
import '../../../app/providers.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../core/widgets/skeleton.dart';
import '../../../core/widgets/states.dart';
import '../../../data/models/account.dart';
import '../../../l10n/l10n.dart';

final walletProvider = FutureProvider.autoDispose<Wallet>((ref) => ref.watch(profileRepositoryProvider).wallet());

final loyaltyProvider = FutureProvider.autoDispose<LoyaltySummary>((ref) => ref.watch(profileRepositoryProvider).loyalty());

class WalletScreen extends ConsumerWidget {
  const WalletScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final wallet = ref.watch(walletProvider);
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.wallet)),
      body: AsyncValueView<Wallet>(
        value: wallet,
        onRetry: () => ref.invalidate(walletProvider),
        data: (w) => RefreshIndicator(
          onRefresh: () => ref.refresh(walletProvider.future),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              _GoldCard(
                eyebrow: l10n.walletBalance,
                title: context.money(w.balance),
                subtitle: l10n.walletHint,
              ),
              SectionHeader(title: l10n.transactions, padding: const EdgeInsets.only(top: 28, bottom: 8)),
              if (w.transactions.isEmpty) EmptyState(icon: Icons.receipt_long_outlined, title: l10n.noTransactions, compact: true),
              for (final t in w.transactions)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: CircleAvatar(
                    backgroundColor: (t.isCredit ? tokens.success : tokens.danger).withValues(alpha: .1),
                    child: Icon(
                      t.isCredit ? Icons.south_west : Icons.north_east,
                      size: 18,
                      color: t.isCredit ? tokens.success : tokens.danger,
                    ),
                  ),
                  title: Text(t.note ?? t.type),
                  subtitle: t.createdAt == null ? null : Text(context.shortDate(t.createdAt!)),
                  trailing: Text(
                    '${t.isCredit ? '+' : ''}${context.money(t.amount)}',
                    style: text.titleSmall?.copyWith(color: t.isCredit ? tokens.success : null),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class LoyaltyScreen extends ConsumerWidget {
  const LoyaltyScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final loyalty = ref.watch(loyaltyProvider);
    final appName = ref.watch(tenantConfigProvider).appName;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    String tierName(String? t) => switch (t) {
      'silver' => l10n.tierSilver,
      'gold' => l10n.tierGold,
      'platinum' => l10n.tierPlatinum,
      'black' || 'diamond' => l10n.tierDiamond,
      _ => l10n.tierMember,
    };

    return Scaffold(
      appBar: AppBar(title: Text(l10n.loyalty)),
      body: AsyncValueView<LoyaltySummary>(
        value: loyalty,
        onRetry: () => ref.invalidate(loyaltyProvider),
        data: (s) => ListView(
          padding: const EdgeInsets.all(20),
          children: [
            _GoldCard(eyebrow: tierName(s.tier), title: l10n.pointsCount(s.points), subtitle: appName),
            if (s.referralCode != null) ...[
              const SizedBox(height: 16),
              LuxCard(
                child: Row(
                  children: [
                    Icon(Icons.card_giftcard, color: tokens.goldDark),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(l10n.referFriend, style: text.titleSmall),
                          Text(l10n.referralCodeLabel(s.referralCode!), style: text.bodySmall),
                        ],
                      ),
                    ),
                    TextButton.icon(
                      icon: const Icon(Icons.ios_share, size: 18),
                      label: Text(l10n.share),
                      onPressed: () => SharePlus.instance.share(ShareParams(text: l10n.referralShareText(appName, s.referralCode!))),
                    ),
                  ],
                ),
              ),
            ],
            SectionHeader(title: l10n.pointsHistory, padding: const EdgeInsets.only(top: 28, bottom: 8)),
            if (s.entries.isEmpty) EmptyState(icon: Icons.stars_outlined, title: l10n.noPointsYet, compact: true),
            for (final e in s.entries)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(e.note ?? e.type),
                subtitle: e.createdAt == null ? null : Text(context.shortDate(e.createdAt!)),
                trailing: Text(
                  '${e.points > 0 ? '+' : ''}${e.points}',
                  style: text.titleSmall?.copyWith(color: e.points >= 0 ? tokens.success : tokens.danger),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _GoldCard extends StatelessWidget {
  const _GoldCard({required this.eyebrow, required this.title, this.subtitle});

  final String eyebrow;
  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    final text = Theme.of(context).textTheme;
    return Container(
      height: 190,
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        boxShadow: tokens.shadow,
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [LuxeColors.ink, Color.lerp(LuxeColors.ink, tokens.gold, .45)!, tokens.goldDark],
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Eyebrow(eyebrow, color: tokens.gold),
          const Spacer(),
          Text(title, style: text.displaySmall?.copyWith(color: LuxeColors.ivory)),
          if (subtitle != null) Text(subtitle!, style: text.bodySmall?.copyWith(color: LuxeColors.ivory.withValues(alpha: .7))),
        ],
      ),
    );
  }
}

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final me = ref.watch(meProvider);
    final locale = ref.watch(localeProvider);
    final themeMode = ref.watch(themeModeProvider);
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final loyaltyOn = ref.watch(moduleEnabledProvider('loyalty'));
    final studioOn = ref.watch(moduleEnabledProvider('ai_studio'));

    return Scaffold(
      appBar: AppBar(title: Text(l10n.profile)),
      body: me.when(
        loading: () => const SkeletonList(count: 4),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(meProvider)),
        data: (profile) {
          if (profile == null) {
            return EmptyState(
              icon: Icons.person_outline,
              title: l10n.signInToContinue,
              message: l10n.signInBenefits,
              actionLabel: l10n.signIn,
              onAction: () => context.push('/sign-in?from=/profile'),
            );
          }
          return ListView(
            padding: const EdgeInsets.symmetric(vertical: 12),
            children: [
              ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 20),
                leading: CircleAvatar(
                  radius: 28,
                  backgroundColor: tokens.rose,
                  child: Text(profile.name.isEmpty ? '?' : String.fromCharCodes(profile.name.runes.take(1)), style: text.titleLarge),
                ),
                title: Text(profile.name.isEmpty ? l10n.addYourName : profile.name, style: text.titleMedium),
                subtitle: Text(profile.phone, textDirection: TextDirection.ltr),
                trailing: IconButton(
                  icon: const Icon(Icons.edit_outlined),
                  tooltip: l10n.editProfile,
                  onPressed: () => _editName(context, ref, profile),
                ),
              ),
              const SizedBox(height: 8),
              _tile(
                context,
                Icons.account_balance_wallet_outlined,
                l10n.wallet,
                () => context.push('/wallet'),
                trailing: context.money(profile.walletBalance),
              ),
              if (loyaltyOn)
                _tile(
                  context,
                  Icons.workspace_premium_outlined,
                  l10n.loyalty,
                  () => context.push('/loyalty'),
                  trailing: l10n.pointsCount(profile.loyaltyPoints),
                ),
              if (studioOn) _tile(context, Icons.auto_awesome_outlined, l10n.myLooks, () => context.push('/looks')),
              _tile(context, Icons.event_note_outlined, l10n.myBookings, () => context.go('/bookings')),
              SectionHeader(title: l10n.communication),
              for (final channel in ClientProfile.consentChannels)
                SwitchListTile(
                  contentPadding: const EdgeInsets.symmetric(horizontal: 20),
                  title: Text(switch (channel) {
                    'whatsapp' => l10n.consentWhatsapp,
                    'sms' => l10n.consentSms,
                    'email' => l10n.consentEmail,
                    _ => l10n.consentPush,
                  }),
                  value: profile.consents[channel] ?? false,
                  onChanged: (v) async {
                    Haptics.tap();
                    try {
                      await ref.read(profileRepositoryProvider).update(consents: {channel: v});
                      ref.invalidate(meProvider);
                    } catch (e) {
                      if (context.mounted) context.showSnack(context.errorMessage(e));
                    }
                  },
                ),
              SectionHeader(title: l10n.preferences),
              ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 20),
                leading: const Icon(Icons.translate),
                title: Text(l10n.language),
                trailing: SegmentedButton<String>(
                  showSelectedIcon: false,
                  segments: const [
                    ButtonSegment(value: 'en', label: Text('EN')),
                    ButtonSegment(value: 'ar', label: Text('عربي')),
                  ],
                  selected: {locale.languageCode},
                  onSelectionChanged: (s) async {
                    await ref.read(localeProvider.notifier).set(Locale(s.first));
                    try {
                      await ref.read(profileRepositoryProvider).update(locale: s.first);
                    } catch (_) {}
                  },
                ),
              ),
              ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 20),
                leading: const Icon(Icons.dark_mode_outlined),
                title: Text(l10n.appearance),
                trailing: DropdownButton<ThemeMode>(
                  value: themeMode,
                  underline: const SizedBox.shrink(),
                  items: [
                    DropdownMenuItem(value: ThemeMode.system, child: Text(l10n.themeSystem)),
                    DropdownMenuItem(value: ThemeMode.light, child: Text(l10n.themeLight)),
                    DropdownMenuItem(value: ThemeMode.dark, child: Text(l10n.themeDark)),
                  ],
                  onChanged: (m) => m == null ? null : ref.read(themeModeProvider.notifier).set(m),
                ),
              ),
              SectionHeader(title: l10n.privacy),
              _tile(context, Icons.download_outlined, l10n.exportData, () => _export(context, ref)),
              _tile(context, Icons.logout, l10n.signOut, () => ref.read(sessionProvider.notifier).signOut()),
              ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 20),
                leading: Icon(Icons.delete_outline, color: tokens.danger),
                title: Text(l10n.deleteAccount, style: TextStyle(color: tokens.danger)),
                onTap: () => _delete(context, ref),
              ),
              const SizedBox(height: 24),
            ],
          );
        },
      ),
    );
  }

  Widget _tile(BuildContext context, IconData icon, String title, VoidCallback onTap, {String? trailing}) => ListTile(
    contentPadding: const EdgeInsets.symmetric(horizontal: 20),
    leading: Icon(icon),
    title: Text(title),
    trailing: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (trailing != null) Text(trailing, style: Theme.of(context).textTheme.bodySmall),
        const Icon(Icons.chevron_right),
      ],
    ),
    onTap: onTap,
  );

  Future<void> _editName(BuildContext context, WidgetRef ref, ClientProfile profile) async {
    final controller = TextEditingController(text: profile.name);
    final l10n = context.l10n;
    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.editProfile),
        content: TextField(
          controller: controller,
          autofocus: true,
          decoration: InputDecoration(labelText: l10n.fullName),
          textCapitalization: TextCapitalization.words,
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(l10n.cancel)),
          TextButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: Text(l10n.save)),
        ],
      ),
    );
    controller.dispose();
    if (name == null || name.isEmpty) return;
    try {
      await ref.read(profileRepositoryProvider).update(name: name);
      ref.invalidate(meProvider);
    } catch (e) {
      if (context.mounted) context.showSnack(context.errorMessage(e));
    }
  }

  Future<void> _export(BuildContext context, WidgetRef ref) async {
    try {
      final data = await ref.read(profileRepositoryProvider).export();
      final json = const JsonEncoder.withIndent('  ').convert(data);
      await SharePlus.instance.share(ShareParams(text: json, subject: 'My data export'));
    } catch (e) {
      if (context.mounted) context.showSnack(context.errorMessage(e));
    }
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final l10n = context.l10n;
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l10n.deleteAccountTitle),
        content: Text(l10n.deleteAccountMessage),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(l10n.cancel)),
          TextButton(
            style: TextButton.styleFrom(foregroundColor: LuxeColors.danger),
            onPressed: () => Navigator.pop(context, true),
            child: Text(l10n.deleteAccount),
          ),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(profileRepositoryProvider).deleteAccount();
      await ref.read(sessionProvider.notifier).expire();
      if (context.mounted) context.showSnack(l10n.accountDeleted);
    } catch (e) {
      if (context.mounted) context.showSnack(context.errorMessage(e));
    }
  }
}
