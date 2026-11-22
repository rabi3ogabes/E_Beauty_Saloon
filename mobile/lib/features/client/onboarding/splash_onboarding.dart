import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_ui/material_ui.dart';

import '../../../app/flavor.dart';
import '../../../app/providers.dart';
import '../../../core/storage/key_value_store.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/lux_widgets.dart';
import '../../../l10n/l10n.dart';
import '../../auth/brand_mark.dart';

/// Brand splash: warms the salon/branding cache, then routes on.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key, required this.next});

  /// Where to go once ready (decided by the router for the current flavor).
  final String Function(WidgetRef ref) next;

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _go();
  }

  Future<void> _go() async {
    final minDelay = Future<void>.delayed(const Duration(milliseconds: 900));
    try {
      // Branding override; do not block on failure (offline first launch).
      await ref.read(salonProvider.future).timeout(const Duration(seconds: 4));
    } catch (_) {}
    await minDelay;
    if (mounted) context.go(widget.next(ref));
  }

  @override
  Widget build(BuildContext context) {
    final config = ref.watch(tenantConfigProvider);
    final name = ref.watch(salonProvider).asData?.value.name ?? config.appName;
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      backgroundColor: dark ? LuxeColors.plumDeep : config.splashColor,
      body: Center(
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: 1),
          duration: const Duration(milliseconds: 900),
          curve: Curves.easeOutCubic,
          builder: (_, v, child) => Opacity(
            opacity: v,
            child: Transform.translate(offset: Offset(0, 12 * (1 - v)), child: child),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const BrandMark(size: 88),
              const SizedBox(height: 24),
              Text(name, style: Theme.of(context).textTheme.displaySmall, textAlign: TextAlign.center),
            ],
          ),
        ),
      ),
    );
  }
}

class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _pages = PageController();
  int _index = 0;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  Future<void> _finish() async {
    await ref.read(keyValueStoreProvider).write(StorageKeys.onboardingSeen, '1');
    if (mounted) context.go('/home');
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final text = Theme.of(context).textTheme;
    final tokens = LuxeTokens.of(context);
    final slides = [
      (Icons.spa_outlined, l10n.onboarding1Title, l10n.onboarding1Body),
      (Icons.auto_awesome_outlined, l10n.onboarding2Title, l10n.onboarding2Body),
      (Icons.workspace_premium_outlined, l10n.onboarding3Title, l10n.onboarding3Body),
    ];
    final last = _index == slides.length - 1;

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton(onPressed: _finish, child: Text(l10n.skip)),
            ),
            Expanded(
              child: PageView.builder(
                controller: _pages,
                itemCount: slides.length,
                onPageChanged: (i) => setState(() => _index = i),
                itemBuilder: (_, i) {
                  final (icon, title, body) = slides[i];
                  return Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 32),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Container(
                          width: 200,
                          height: 200,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            gradient: RadialGradient(colors: [tokens.rose, tokens.rose.withValues(alpha: .0)]),
                          ),
                          child: Icon(icon, size: 72, color: tokens.goldDark),
                        ),
                        const SizedBox(height: 32),
                        Text(title, style: text.headlineMedium, textAlign: TextAlign.center),
                        const SizedBox(height: 14),
                        Text(
                          body,
                          style: text.bodyLarge?.copyWith(color: tokens.muted),
                          textAlign: TextAlign.center,
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
            PageDots(count: slides.length, index: _index),
            Padding(
              padding: const EdgeInsets.all(24),
              child: LuxButton(
                label: last ? l10n.getStarted : l10n.next,
                onPressed: last ? _finish : () => _pages.nextPage(duration: const Duration(milliseconds: 350), curve: Curves.easeOutCubic),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
