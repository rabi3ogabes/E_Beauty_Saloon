import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';

import '../theme/app_theme.dart';
import 'skeleton.dart';

/// Haptic feedback on key actions (booking confirmed, slot picked, add to cart…).
abstract final class Haptics {
  static Future<void> tap() => HapticFeedback.selectionClick();
  static Future<void> light() => HapticFeedback.lightImpact();
  static Future<void> success() => HapticFeedback.mediumImpact();
  static Future<void> warning() => HapticFeedback.heavyImpact();
}

/// 16px radius card with hairline border and soft shadow.
class LuxCard extends StatelessWidget {
  const LuxCard({
    super.key,
    required this.child,
    this.onTap,
    this.padding = const EdgeInsets.all(16),
    this.color,
    this.shadow = true,
    this.selected = false,
  });

  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;
  final Color? color;
  final bool shadow;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    final scheme = Theme.of(context).colorScheme;
    final radius = BorderRadius.circular(LuxeRadius.card);
    return AnimatedContainer(
      duration: const Duration(milliseconds: 180),
      decoration: BoxDecoration(
        color: color ?? scheme.surface,
        borderRadius: radius,
        border: Border.all(color: selected ? tokens.gold : tokens.hairline, width: selected ? 1.4 : 1),
        boxShadow: shadow ? tokens.shadow : null,
      ),
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          borderRadius: radius,
          onTap: onTap == null
              ? null
              : () {
                  Haptics.tap();
                  onTap!();
                },
          child: Padding(padding: padding, child: child),
        ),
      ),
    );
  }
}

/// Primary pill button with an inline (non-spinner) busy state.
class LuxButton extends StatelessWidget {
  const LuxButton({
    super.key,
    required this.label,
    this.onPressed,
    this.busy = false,
    this.icon,
    this.variant = LuxButtonVariant.primary,
    this.expand = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool busy;
  final IconData? icon;
  final LuxButtonVariant variant;
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final onTap = (busy || onPressed == null)
        ? null
        : () {
            Haptics.light();
            onPressed!();
          };
    final content = AnimatedSwitcher(
      duration: const Duration(milliseconds: 180),
      child: busy
          ? const _BusyDots(key: ValueKey('busy'))
          : Row(
              key: const ValueKey('label'),
              mainAxisSize: MainAxisSize.min,
              children: [
                if (icon != null) ...[Icon(icon, size: 18), const SizedBox(width: 8)],
                Flexible(child: Text(label, overflow: TextOverflow.ellipsis)),
              ],
            ),
    );
    final button = switch (variant) {
      LuxButtonVariant.primary => FilledButton(onPressed: onTap, child: content),
      LuxButtonVariant.gold => ElevatedButton(onPressed: onTap, child: content),
      LuxButtonVariant.secondary => OutlinedButton(onPressed: onTap, child: content),
      LuxButtonVariant.ghost => TextButton(onPressed: onTap, child: content),
    };
    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}

enum LuxButtonVariant { primary, gold, secondary, ghost }

class _BusyDots extends StatefulWidget {
  const _BusyDots({super.key});

  @override
  State<_BusyDots> createState() => _BusyDotsState();
}

class _BusyDotsState extends State<_BusyDots> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final color = DefaultTextStyle.of(context).style.color ?? Colors.white;
    return Semantics(
      label: 'Loading',
      child: AnimatedBuilder(
        animation: _c,
        builder: (context, _) => Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(3, (i) {
            final phase = ((_c.value * 3) - i).clamp(0.0, 1.0);
            final o = .35 + .65 * (1 - (phase - .5).abs() * 2).clamp(0.0, 1.0);
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 3),
              child: Opacity(
                opacity: o,
                child: CircleAvatar(radius: 3.5, backgroundColor: color),
              ),
            );
          }),
        ),
      ),
    );
  }
}

class SectionHeader extends StatelessWidget {
  const SectionHeader({
    super.key,
    required this.title,
    this.actionLabel,
    this.onAction,
    this.padding = const EdgeInsets.fromLTRB(20, 28, 12, 12),
  });

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: padding,
      child: Row(
        children: [
          Expanded(child: Text(title, style: Theme.of(context).textTheme.titleLarge)),
          if (actionLabel != null) TextButton(onPressed: onAction, child: Text(actionLabel!)),
        ],
      ),
    );
  }
}

/// Small uppercase eyebrow label.
class Eyebrow extends StatelessWidget {
  const Eyebrow(this.text, {super.key, this.color});

  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: Theme.of(context).textTheme.labelSmall?.copyWith(color: color ?? LuxeTokens.of(context).goldDark, fontWeight: FontWeight.w600),
    );
  }
}

/// Network / file image with skeleton placeholder and an elegant gradient fallback.
class AppImage extends StatelessWidget {
  const AppImage({
    super.key,
    this.url,
    this.width,
    this.height,
    this.radius = LuxeRadius.card,
    this.fit = BoxFit.cover,
    this.fallbackIcon = Icons.spa_outlined,
  });

  final String? url;
  final double? width;
  final double? height;
  final double radius;
  final BoxFit fit;
  final IconData fallbackIcon;

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    final fallback = Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [tokens.rose, Color.lerp(tokens.rose, tokens.gold, .55)!],
        ),
      ),
      child: Icon(fallbackIcon, color: Colors.white.withValues(alpha: .85), size: 28),
    );

    final u = url;
    Widget image;
    if (u == null || u.isEmpty) {
      image = fallback;
    } else if (u.startsWith('http')) {
      image = CachedNetworkImage(
        imageUrl: u,
        width: width,
        height: height,
        fit: fit,
        placeholder: (_, _) => Shimmer(
          child: SkeletonBox(width: width, height: height ?? 80, radius: 0),
        ),
        errorWidget: (_, _, _) => fallback,
      );
    } else {
      image = Image.file(File(u.replaceFirst('file://', '')), width: width, height: height, fit: fit, errorBuilder: (_, _, _) => fallback);
    }
    return ClipRRect(borderRadius: BorderRadius.circular(radius), child: image);
  }
}

class StatusPill extends StatelessWidget {
  const StatusPill({super.key, required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(LuxeRadius.pill)),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelMedium?.copyWith(color: color, fontWeight: FontWeight.w600),
      ),
    );
  }
}

/// Dots indicator for onboarding / carousels.
class PageDots extends StatelessWidget {
  const PageDots({super.key, required this.count, required this.index});

  final int count;
  final int index;

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: List.generate(count, (i) {
        final active = i == index;
        return AnimatedContainer(
          duration: const Duration(milliseconds: 250),
          margin: const EdgeInsets.symmetric(horizontal: 4),
          width: active ? 22 : 7,
          height: 7,
          decoration: BoxDecoration(color: active ? tokens.gold : tokens.hairline, borderRadius: BorderRadius.circular(8)),
        );
      }),
    );
  }
}
