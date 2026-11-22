import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/flavor.dart';
import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/lux_widgets.dart';
import '../../l10n/l10n.dart';
import 'brand_mark.dart';

class CountryCode {
  const CountryCode(this.iso, this.dial);
  final String iso;
  final String dial;
}

const countryCodes = [
  CountryCode('QA', '+974'),
  CountryCode('SA', '+966'),
  CountryCode('AE', '+971'),
  CountryCode('KW', '+965'),
  CountryCode('BH', '+973'),
  CountryCode('OM', '+968'),
];

/// Step 1 of phone OTP sign-in (shared by the three apps).
class PhoneScreen extends ConsumerStatefulWidget {
  const PhoneScreen({super.key, this.from});

  /// Where to go after sign-in.
  final String? from;

  @override
  ConsumerState<PhoneScreen> createState() => _PhoneScreenState();
}

class _PhoneScreenState extends ConsumerState<PhoneScreen> {
  final _phone = TextEditingController();
  late final TextEditingController _salon;
  CountryCode _country = countryCodes.first;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _salon = TextEditingController(text: ref.read(tenantProvider));
  }

  @override
  void dispose() {
    _phone.dispose();
    _salon.dispose();
    super.dispose();
  }

  String get _fullPhone => '${_country.dial}${_phone.text.replaceAll(RegExp(r'\D'), '')}';

  bool get _valid => _phone.text.replaceAll(RegExp(r'\D'), '').length >= 7;

  Future<void> _submit() async {
    if (!_valid || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final flavor = ref.read(flavorProvider);
    if (flavor != AppFlavor.client) {
      await ref.read(tenantProvider.notifier).set(_salon.text);
    }
    try {
      await ref.read(authRepositoryProvider).requestOtp(_fullPhone);
      if (!mounted) return;
      final from = widget.from == null ? '' : '&from=${Uri.encodeComponent(widget.from!)}';
      context.push('/otp?phone=${Uri.encodeComponent(_fullPhone)}$from');
    } on ApiException catch (e) {
      setState(() => _error = e.firstError('phone') ?? context.errorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final flavor = ref.watch(flavorProvider);

    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
          children: [
            const Center(child: BrandMark(size: 64)),
            const SizedBox(height: 28),
            Text(l10n.signInTitle, style: text.headlineMedium, textAlign: TextAlign.center),
            const SizedBox(height: 10),
            Text(
              l10n.signInSubtitle,
              style: text.bodyMedium?.copyWith(color: tokens.muted),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 32),
            if (flavor != AppFlavor.client) ...[
              TextField(
                controller: _salon,
                decoration: InputDecoration(labelText: l10n.salonCode, prefixIcon: const Icon(Icons.storefront_outlined)),
                textInputAction: TextInputAction.next,
                autocorrect: false,
              ),
              const SizedBox(height: 14),
            ],
            Directionality(
              // Phone numbers are always LTR, even in Arabic.
              textDirection: TextDirection.ltr,
              child: Row(
                children: [
                  SizedBox(
                    width: 116,
                    child: DropdownButtonFormField<CountryCode>(
                      initialValue: _country,
                      isExpanded: true,
                      decoration: const InputDecoration(contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 16)),
                      selectedItemBuilder: (_) => [for (final c in countryCodes) Text(c.dial, overflow: TextOverflow.ellipsis)],
                      items: [for (final c in countryCodes) DropdownMenuItem(value: c, child: Text('${c.iso} ${c.dial}'))],
                      onChanged: (c) => setState(() => _country = c ?? _country),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: TextField(
                      key: const Key('phone-field'),
                      controller: _phone,
                      autofocus: true,
                      keyboardType: TextInputType.phone,
                      autofillHints: const [AutofillHints.telephoneNumberNational],
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(12)],
                      decoration: InputDecoration(hintText: l10n.phoneHint, errorText: _error),
                      onChanged: (_) => setState(() {}),
                      onSubmitted: (_) => _submit(),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            LuxButton(label: l10n.sendCode, onPressed: _valid ? _submit : null, busy: _busy),
            const SizedBox(height: 16),
            Text(l10n.signInLegal, style: text.bodySmall, textAlign: TextAlign.center),
          ],
        ),
      ),
    );
  }
}
