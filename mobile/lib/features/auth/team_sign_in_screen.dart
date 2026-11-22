import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/lux_widgets.dart';
import '../../l10n/l10n.dart';
import 'brand_mark.dart';

/// Staff / owner sign-in: salon code, e-mail + password of the dashboard account, then the
/// authenticator code when the account has app MFA (`error.code == mfa_required`).
/// `POST /staff/auth/login` or `/owner/auth/login` (prefix from the flavor).
class TeamSignInScreen extends ConsumerStatefulWidget {
  const TeamSignInScreen({super.key, this.from});

  /// Where to go after sign-in (the router redirect also handles it).
  final String? from;

  @override
  ConsumerState<TeamSignInScreen> createState() => _TeamSignInScreenState();
}

class _TeamSignInScreenState extends ConsumerState<TeamSignInScreen> {
  late final TextEditingController _salon;
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  bool _needsCode = false;
  bool _busy = false;
  bool _obscure = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _salon = TextEditingController(text: ref.read(tenantProvider));
  }

  @override
  void dispose() {
    _salon.dispose();
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  bool get _valid =>
      _salon.text.trim().isNotEmpty &&
      _email.text.contains('@') &&
      _password.text.isNotEmpty &&
      (!_needsCode || _code.text.trim().length >= 6);

  Future<void> _submit() async {
    if (!_valid || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    await ref.read(tenantProvider.notifier).set(_salon.text);
    try {
      final result = await ref
          .read(authRepositoryProvider)
          .signInWithPassword(
            email: _email.text,
            password: _password.text,
            code: _needsCode ? _code.text : null,
            deviceName: 'Luxe ${ref.read(tenantProvider)} app',
          );
      await ref.read(sessionProvider.notifier).signIn(result);
      if (mounted && widget.from != null) context.go(widget.from!);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (e.code == 'mfa_required') {
          _needsCode = true;
          _error = null;
        } else {
          _error = e.firstError('email') ?? context.errorMessage(e);
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);

    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: AutofillGroup(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
            children: [
              const Center(child: BrandMark(size: 64)),
              const SizedBox(height: 28),
              Text(l10n.signInTitle, style: text.headlineMedium, textAlign: TextAlign.center),
              const SizedBox(height: 10),
              Text(
                l10n.teamSignInSubtitle,
                style: text.bodyMedium?.copyWith(color: tokens.muted),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 32),
              TextField(
                controller: _salon,
                decoration: InputDecoration(labelText: l10n.salonCode, prefixIcon: const Icon(Icons.storefront_outlined)),
                textInputAction: TextInputAction.next,
                autocorrect: false,
                onChanged: (_) => setState(() {}),
              ),
              const SizedBox(height: 14),
              TextField(
                key: const Key('email-field'),
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autofillHints: const [AutofillHints.username, AutofillHints.email],
                textInputAction: TextInputAction.next,
                autocorrect: false,
                decoration: InputDecoration(labelText: l10n.emailLabel, prefixIcon: const Icon(Icons.alternate_email)),
                onChanged: (_) => setState(() {}),
              ),
              const SizedBox(height: 14),
              TextField(
                key: const Key('password-field'),
                controller: _password,
                obscureText: _obscure,
                autofillHints: const [AutofillHints.password],
                textInputAction: _needsCode ? TextInputAction.next : TextInputAction.done,
                decoration: InputDecoration(
                  labelText: l10n.passwordLabel,
                  prefixIcon: const Icon(Icons.lock_outline),
                  errorText: _needsCode ? null : _error,
                  suffixIcon: IconButton(
                    icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                    onPressed: () => setState(() => _obscure = !_obscure),
                  ),
                ),
                onChanged: (_) => setState(() {}),
                onSubmitted: (_) => _submit(),
              ),
              if (_needsCode) ...[
                const SizedBox(height: 14),
                Directionality(
                  // Codes are always LTR, even in Arabic.
                  textDirection: TextDirection.ltr,
                  child: TextField(
                    key: const Key('mfa-field'),
                    controller: _code,
                    autofocus: true,
                    autofillHints: const [AutofillHints.oneTimeCode],
                    inputFormatters: [LengthLimitingTextInputFormatter(32)],
                    decoration: InputDecoration(
                      labelText: l10n.authenticatorCode,
                      helperText: l10n.authenticatorCodeHint,
                      prefixIcon: const Icon(Icons.verified_user_outlined),
                      errorText: _error,
                    ),
                    onChanged: (_) => setState(() {}),
                    onSubmitted: (_) => _submit(),
                  ),
                ),
              ],
              const SizedBox(height: 24),
              LuxButton(label: l10n.signIn, onPressed: _valid ? _submit : null, busy: _busy),
              const SizedBox(height: 16),
              Text(l10n.signInLegal, style: text.bodySmall, textAlign: TextAlign.center),
            ],
          ),
        ),
      ),
    );
  }
}
