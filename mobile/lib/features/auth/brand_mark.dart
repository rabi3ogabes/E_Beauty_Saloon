import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_ui/material_ui.dart';

import '../../app/flavor.dart';
import '../../app/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/lux_widgets.dart';

/// Salon logo: runtime `logo_url` → bundled white-label asset → monogram.
class BrandMark extends ConsumerWidget {
  const BrandMark({super.key, this.size = 56});

  final double size;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(tenantConfigProvider);
    final logoUrl = ref.watch(salonProvider).asData?.value.branding.logoUrl;
    final tokens = LuxeTokens.of(context);

    final monogram = Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: tokens.gold, width: 1.2),
      ),
      child: Text(
        config.appName.isEmpty ? 'L' : String.fromCharCodes(config.appName.runes.take(1)),
        style: Theme.of(context).textTheme.headlineMedium?.copyWith(color: tokens.goldDark, fontSize: size * .45),
      ),
    );

    if (logoUrl != null) {
      return SizedBox(
        width: size,
        height: size,
        child: AppImage(url: logoUrl, radius: size, fit: BoxFit.contain),
      );
    }
    if (config.logoAsset != null) {
      return Image.asset(config.logoAsset!, width: size, height: size, errorBuilder: (_, _, _) => monogram);
    }
    return monogram;
  }
}
