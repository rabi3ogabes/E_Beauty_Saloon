import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/flavor.dart';
import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/lux_widgets.dart';
import '../../l10n/l10n.dart';

/// Step 2: enter the 6-digit code. Supports SMS one-time-code autofill
/// (iOS QuickType + Android autofill via `AutofillHints.oneTimeCode`).
class OtpScreen extends ConsumerStatefulWidget {
  const OtpScreen({super.key, required this.phone, this.onVerified});

  final String phone;

  /// Called after a successful sign-in (router redirect also handles it).
  final VoidCallback? onVerified;

  @override
  ConsumerState<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends ConsumerState<OtpScreen> {
  static const length = 6;
  static const resendSeconds = 30;

  final _code = TextEditingController();
  final _focus = FocusNode();
  bool _busy = false;
  String? _error;
  int _secondsLeft = resendSeconds;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _startTimer();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _code.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _startTimer() {
    _timer?.cancel();
    setState(() => _secondsLeft = resendSeconds);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return;
      if (_secondsLeft <= 1) {
        t.cancel();
        setState(() => _secondsLeft = 0);
      } else {
        setState(() => _secondsLeft--);
      }
    });
  }

  String get _deviceName {
    final app = ref.read(flavorProvider).name;
    final os = kIsWeb ? 'web' : (Platform.isIOS ? 'iOS' : (Platform.isAndroid ? 'Android' : Platform.operatingSystem));
    return 'luxe-$app-$os';
  }

  Future<void> _verify() async {
    final code = _code.text;
    if (code.length != length || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(authRepositoryProvider).verify(phone: widget.phone, code: code, deviceName: _deviceName);
      await ref.read(sessionProvider.notifier).signIn(result);
      Haptics.success();
      TextInput.finishAutofillContext();
      widget.onVerified?.call();
    } on ApiException catch (e) {
      Haptics.warning();
      if (!mounted) return;
      setState(() {
        _error = e.firstError('code') ?? context.errorMessage(e);
        _code.clear();
      });
      _focus.requestFocus();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    try {
      await ref.read(authRepositoryProvider).requestOtp(widget.phone);
      if (!mounted) return;
      context.showSnack(context.l10n.codeResent);
      _startTimer();
    } on ApiException catch (e) {
      if (mounted) context.showSnack(context.errorMessage(e));
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
        child: ListView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
          children: [
            Text(l10n.otpTitle, style: text.headlineMedium, textAlign: TextAlign.center),
            const SizedBox(height: 10),
            Text(
              l10n.otpSubtitle(widget.phone),
              style: text.bodyMedium?.copyWith(color: tokens.muted),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 32),
            Directionality(
              textDirection: TextDirection.ltr,
              child: AutofillGroup(
                child: _CodeInput(
                  controller: _code,
                  focusNode: _focus,
                  length: length,
                  hasError: _error != null,
                  onChanged: (v) {
                    setState(() => _error = null);
                    if (v.length == length) _verify();
                  },
                ),
              ),
            ),
            AnimatedSize(
              duration: const Duration(milliseconds: 200),
              child: _error == null
                  ? const SizedBox(height: 16)
                  : Padding(
                      padding: const EdgeInsets.only(top: 12),
                      child: Text(
                        _error!,
                        key: const Key('otp-error'),
                        style: text.bodyMedium?.copyWith(color: tokens.danger),
                        textAlign: TextAlign.center,
                      ),
                    ),
            ),
            const SizedBox(height: 16),
            LuxButton(label: l10n.verify, onPressed: _code.text.length == length ? _verify : null, busy: _busy),
            const SizedBox(height: 8),
            Center(
              child: _secondsLeft > 0
                  ? Padding(
                      padding: const EdgeInsets.all(12),
                      child: Text(l10n.resendIn(_secondsLeft), style: text.bodySmall),
                    )
                  : TextButton(onPressed: _resend, child: Text(l10n.resendCode)),
            ),
          ],
        ),
      ),
    );
  }
}

class _CodeInput extends StatelessWidget {
  const _CodeInput({
    required this.controller,
    required this.focusNode,
    required this.length,
    required this.onChanged,
    required this.hasError,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final int length;
  final ValueChanged<String> onChanged;
  final bool hasError;

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    final text = Theme.of(context).textTheme;
    final scheme = Theme.of(context).colorScheme;

    return SizedBox(
      height: 60,
      child: Stack(
        children: [
          ValueListenableBuilder<TextEditingValue>(
            valueListenable: controller,
            builder: (context, value, _) => Row(
              children: List.generate(length, (i) {
                final char = i < value.text.length ? value.text[i] : '';
                final active = i == value.text.length && focusNode.hasFocus;
                return Expanded(
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 150),
                    margin: const EdgeInsets.symmetric(horizontal: 4),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: scheme.surface,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(
                        color: hasError ? tokens.danger : (active ? tokens.gold : tokens.hairline),
                        width: active || hasError ? 1.5 : 1,
                      ),
                    ),
                    child: Text(char, style: text.headlineSmall),
                  ),
                );
              }),
            ),
          ),
          // Transparent field on top receives input, paste and SMS autofill.
          Positioned.fill(
            child: Opacity(
              opacity: 0.01,
              child: TextField(
                key: const Key('otp-field'),
                controller: controller,
                focusNode: focusNode,
                autofocus: true,
                keyboardType: TextInputType.number,
                autofillHints: const [AutofillHints.oneTimeCode],
                inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(length)],
                showCursor: false,
                enableInteractiveSelection: false,
                decoration: const InputDecoration(border: InputBorder.none, filled: false, counterText: ''),
                onChanged: onChanged,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
