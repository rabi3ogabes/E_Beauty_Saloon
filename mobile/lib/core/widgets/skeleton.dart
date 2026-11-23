import 'package:material_ui/material_ui.dart';

import '../theme/app_theme.dart';

/// Shimmer wrapper — every loading state uses skeletons, never a lone spinner.
class Shimmer extends StatefulWidget {
  const Shimmer({super.key, required this.child});

  final Widget child;

  @override
  State<Shimmer> createState() => _ShimmerState();
}

class _ShimmerState extends State<Shimmer> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = LuxeTokens.of(context);
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) {
      _c.stop();
    }
    return AnimatedBuilder(
      animation: _c,
      child: widget.child,
      builder: (context, child) {
        final t = _c.value;
        return ShaderMask(
          blendMode: BlendMode.srcATop,
          shaderCallback: (bounds) => LinearGradient(
            begin: Alignment(-1.6 + 3.2 * t, -.3),
            end: Alignment(-0.6 + 3.2 * t, .3),
            colors: [tokens.skeletonBase, tokens.skeletonHighlight, tokens.skeletonBase],
            stops: const [0, .5, 1],
          ).createShader(bounds),
          child: child,
        );
      },
    );
  }
}

/// A rounded placeholder block.
class SkeletonBox extends StatelessWidget {
  const SkeletonBox({super.key, this.width, this.height = 14, this.radius = 8});

  final double? width;
  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(color: LuxeTokens.of(context).skeletonBase, borderRadius: BorderRadius.circular(radius)),
    );
  }
}

/// Skeleton of a list of cards (image + two lines).
class SkeletonList extends StatelessWidget {
  const SkeletonList({super.key, this.count = 5, this.itemHeight = 88, this.padding = const EdgeInsets.all(20), this.shrinkWrap = false});

  final int count;
  final double itemHeight;
  final EdgeInsets padding;
  final bool shrinkWrap;

  @override
  Widget build(BuildContext context) {
    return Shimmer(
      child: ListView.separated(
        padding: padding,
        shrinkWrap: shrinkWrap,
        physics: shrinkWrap ? const NeverScrollableScrollPhysics() : null,
        itemCount: count,
        separatorBuilder: (_, _) => const SizedBox(height: 12),
        itemBuilder: (_, _) => SizedBox(
          height: itemHeight,
          child: Row(
            children: [
              SkeletonBox(width: itemHeight, height: itemHeight, radius: LuxeRadius.card),
              const SizedBox(width: 14),
              const Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SkeletonBox(width: 160, height: 16),
                    SizedBox(height: 10),
                    SkeletonBox(width: 100),
                    SizedBox(height: 10),
                    SkeletonBox(width: 60),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Skeleton grid of chips (slots / categories).
class SkeletonChips extends StatelessWidget {
  const SkeletonChips({super.key, this.count = 9});

  final int count;

  @override
  Widget build(BuildContext context) {
    return Shimmer(
      child: Wrap(
        spacing: 10,
        runSpacing: 10,
        children: List.generate(count, (i) => const SkeletonBox(width: 84, height: 44, radius: LuxeRadius.pill)),
      ),
    );
  }
}
