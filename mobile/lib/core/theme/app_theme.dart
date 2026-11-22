import 'package:google_fonts/google_fonts.dart';
import 'package:material_ui/material_ui.dart';

/// "Quiet luxury" design tokens.
abstract final class LuxeColors {
  static const ivory = Color(0xFFFAF7F2);
  static const ink = Color(0xFF1C1A17);
  static const champagne = Color(0xFFC8A96A);
  static const champagneDark = Color(0xFFA88B4F);
  static const rose = Color(0xFFE9D6CF);
  static const plum = Color(0xFF1E1620);
  static const plumDeep = Color(0xFF141014);
  static const success = Color(0xFF4F7A5A);
  static const danger = Color(0xFFA4473F);
  static const mutedInk = Color(0xFF6E675E);
  static const hairlineLight = Color(0x1F1C1A17);
  static const hairlineDark = Color(0x26FAF7F2);
}

abstract final class LuxeRadius {
  static const card = 16.0;
  static const pill = 999.0;
  static const sheet = 28.0;
}

/// Brand colours after merging build-time white-label config and runtime `GET /salon` branding.
@immutable
class BrandPalette {
  const BrandPalette({
    this.primary = LuxeColors.champagne,
    this.primaryDark = LuxeColors.champagneDark,
    this.accent = LuxeColors.rose,
    this.ink = LuxeColors.ink,
    this.background = LuxeColors.ivory,
  });

  final Color primary;
  final Color primaryDark;
  final Color accent;
  final Color ink;
  final Color background;

  /// Parses `#RRGGBB` / `#AARRGGBB`; returns null for anything else.
  static Color? parseHex(String? hex) {
    if (hex == null) return null;
    var h = hex.trim().replaceFirst('#', '');
    if (h.length == 6) h = 'FF$h';
    if (h.length != 8) return null;
    final v = int.tryParse(h, radix: 16);
    return v == null ? null : Color(v);
  }

  BrandPalette merge({String? primary, String? accent, String? ink, String? background}) {
    final p = parseHex(primary);
    return BrandPalette(
      primary: p ?? this.primary,
      primaryDark: p == null ? primaryDark : Color.lerp(p, Colors.black, .16)!,
      accent: parseHex(accent) ?? this.accent,
      ink: parseHex(ink) ?? this.ink,
      background: parseHex(background) ?? this.background,
    );
  }

  @override
  bool operator ==(Object other) =>
      other is BrandPalette &&
      other.primary == primary &&
      other.primaryDark == primaryDark &&
      other.accent == accent &&
      other.ink == ink &&
      other.background == background;

  @override
  int get hashCode => Object.hash(primary, primaryDark, accent, ink, background);
}

/// Extra tokens not covered by [ColorScheme].
@immutable
class LuxeTokens extends ThemeExtension<LuxeTokens> {
  const LuxeTokens({
    required this.hairline,
    required this.muted,
    required this.gold,
    required this.goldDark,
    required this.rose,
    required this.success,
    required this.danger,
    required this.surfaceRaised,
    required this.skeletonBase,
    required this.skeletonHighlight,
    required this.shadow,
  });

  final Color hairline;
  final Color muted;
  final Color gold;
  final Color goldDark;
  final Color rose;
  final Color success;
  final Color danger;
  final Color surfaceRaised;
  final Color skeletonBase;
  final Color skeletonHighlight;
  final List<BoxShadow> shadow;

  static LuxeTokens of(BuildContext context) => Theme.of(context).extension<LuxeTokens>()!;

  @override
  LuxeTokens copyWith({Color? hairline, Color? muted, Color? gold}) => LuxeTokens(
    hairline: hairline ?? this.hairline,
    muted: muted ?? this.muted,
    gold: gold ?? this.gold,
    goldDark: goldDark,
    rose: rose,
    success: success,
    danger: danger,
    surfaceRaised: surfaceRaised,
    skeletonBase: skeletonBase,
    skeletonHighlight: skeletonHighlight,
    shadow: shadow,
  );

  @override
  LuxeTokens lerp(ThemeExtension<LuxeTokens>? other, double t) {
    if (other is! LuxeTokens) return this;
    return LuxeTokens(
      hairline: Color.lerp(hairline, other.hairline, t)!,
      muted: Color.lerp(muted, other.muted, t)!,
      gold: Color.lerp(gold, other.gold, t)!,
      goldDark: Color.lerp(goldDark, other.goldDark, t)!,
      rose: Color.lerp(rose, other.rose, t)!,
      success: Color.lerp(success, other.success, t)!,
      danger: Color.lerp(danger, other.danger, t)!,
      surfaceRaised: Color.lerp(surfaceRaised, other.surfaceRaised, t)!,
      skeletonBase: Color.lerp(skeletonBase, other.skeletonBase, t)!,
      skeletonHighlight: Color.lerp(skeletonHighlight, other.skeletonHighlight, t)!,
      shadow: t < .5 ? shadow : other.shadow,
    );
  }
}

/// Typography: Playfair Display headings, Inter body, IBM Plex Sans Arabic for Arabic.
abstract final class LuxeTypography {
  /// Disabled in tests (no network) — falls back to the platform font.
  static bool useGoogleFonts = true;

  static TextTheme build(TextTheme base, {required bool arabic, required Color ink, required Color muted}) {
    TextStyle display(TextStyle? s) {
      final style = (s ?? const TextStyle()).copyWith(color: ink, fontWeight: FontWeight.w500, letterSpacing: arabic ? 0 : -0.2);
      if (!useGoogleFonts) return style;
      return arabic
          ? GoogleFonts.ibmPlexSansArabic(textStyle: style.copyWith(fontWeight: FontWeight.w600))
          : GoogleFonts.playfairDisplay(textStyle: style);
    }

    TextStyle body(TextStyle? s, {Color? color}) {
      final style = (s ?? const TextStyle()).copyWith(color: color ?? ink);
      if (!useGoogleFonts) return style;
      return arabic ? GoogleFonts.ibmPlexSansArabic(textStyle: style) : GoogleFonts.inter(textStyle: style);
    }

    return base.copyWith(
      displayLarge: display(base.displayLarge),
      displayMedium: display(base.displayMedium),
      displaySmall: display(base.displaySmall),
      headlineLarge: display(base.headlineLarge),
      headlineMedium: display(base.headlineMedium),
      headlineSmall: display(base.headlineSmall),
      titleLarge: display(base.titleLarge),
      titleMedium: body(base.titleMedium?.copyWith(fontWeight: FontWeight.w600)),
      titleSmall: body(base.titleSmall?.copyWith(fontWeight: FontWeight.w600)),
      bodyLarge: body(base.bodyLarge),
      bodyMedium: body(base.bodyMedium),
      bodySmall: body(base.bodySmall, color: muted),
      labelLarge: body(base.labelLarge?.copyWith(fontWeight: FontWeight.w600, letterSpacing: .3)),
      labelMedium: body(base.labelMedium, color: muted),
      labelSmall: body(base.labelSmall?.copyWith(letterSpacing: 1.1), color: muted),
    );
  }
}

ThemeData buildLuxeTheme({required BrandPalette palette, required Brightness brightness, required Locale locale}) {
  final dark = brightness == Brightness.dark;
  final arabic = locale.languageCode == 'ar';

  final background = dark ? LuxeColors.plumDeep : palette.background;
  final surface = dark ? LuxeColors.plum : Colors.white;
  final ink = dark ? LuxeColors.ivory : palette.ink;
  final muted = dark ? const Color(0xFFB8AEB4) : LuxeColors.mutedInk;
  final hairline = dark ? LuxeColors.hairlineDark : LuxeColors.hairlineLight;
  final primary = palette.primary;
  final onPrimary = primary.computeLuminance() > .45 ? LuxeColors.ink : Colors.white;

  final scheme = ColorScheme(
    brightness: brightness,
    primary: primary,
    onPrimary: onPrimary,
    primaryContainer: dark ? Color.lerp(primary, LuxeColors.plum, .7)! : Color.lerp(primary, Colors.white, .78)!,
    onPrimaryContainer: ink,
    secondary: palette.accent,
    onSecondary: LuxeColors.ink,
    secondaryContainer: dark ? Color.lerp(palette.accent, LuxeColors.plum, .75)! : palette.accent,
    onSecondaryContainer: ink,
    tertiary: palette.primaryDark,
    onTertiary: Colors.white,
    error: LuxeColors.danger,
    onError: Colors.white,
    surface: surface,
    onSurface: ink,
    onSurfaceVariant: muted,
    surfaceContainerLowest: background,
    surfaceContainerLow: dark ? const Color(0xFF191319) : const Color(0xFFF5F0E8),
    surfaceContainer: surface,
    surfaceContainerHigh: dark ? const Color(0xFF261C28) : const Color(0xFFF2ECE3),
    surfaceContainerHighest: dark ? const Color(0xFF2E2330) : const Color(0xFFEDE6DB),
    outline: hairline,
    outlineVariant: hairline,
    shadow: Colors.black,
    inverseSurface: dark ? LuxeColors.ivory : LuxeColors.ink,
    onInverseSurface: dark ? LuxeColors.ink : LuxeColors.ivory,
  );

  final base = ThemeData(useMaterial3: true, brightness: brightness, colorScheme: scheme);
  final text = LuxeTypography.build(base.textTheme, arabic: arabic, ink: ink, muted: muted);

  const pill = StadiumBorder();
  const minSize = Size(64, 52);

  return base.copyWith(
    scaffoldBackgroundColor: background,
    textTheme: text,
    splashFactory: InkSparkle.splashFactory,
    extensions: [
      LuxeTokens(
        hairline: hairline,
        muted: muted,
        gold: primary,
        goldDark: palette.primaryDark,
        rose: palette.accent,
        success: LuxeColors.success,
        danger: LuxeColors.danger,
        surfaceRaised: surface,
        skeletonBase: dark ? const Color(0xFF2A2030) : const Color(0xFFEFE8DE),
        skeletonHighlight: dark ? const Color(0xFF3A2E40) : const Color(0xFFFBF8F3),
        shadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: dark ? .35 : .06),
            blurRadius: 24,
            offset: const Offset(0, 8),
          ),
        ],
      ),
    ],
    appBarTheme: AppBarTheme(
      backgroundColor: background,
      surfaceTintColor: Colors.transparent,
      foregroundColor: ink,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: true,
      titleTextStyle: text.titleLarge?.copyWith(fontSize: 20),
    ),
    cardTheme: CardThemeData(
      color: surface,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(LuxeRadius.card),
        side: BorderSide(color: hairline),
      ),
    ),
    dividerTheme: DividerThemeData(color: hairline, thickness: .7, space: 1),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: dark ? primary : ink,
        foregroundColor: dark ? onPrimary : LuxeColors.ivory,
        minimumSize: minSize,
        shape: pill,
        textStyle: text.labelLarge,
        padding: const EdgeInsets.symmetric(horizontal: 28),
      ),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: primary,
        foregroundColor: onPrimary,
        elevation: 0,
        minimumSize: minSize,
        shape: pill,
        textStyle: text.labelLarge,
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: ink,
        minimumSize: minSize,
        shape: pill,
        side: BorderSide(color: dark ? hairline : ink.withValues(alpha: .25)),
        textStyle: text.labelLarge,
        padding: const EdgeInsets.symmetric(horizontal: 24),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: dark ? primary : palette.primaryDark,
        minimumSize: const Size(44, 44),
        textStyle: text.labelLarge,
      ),
    ),
    iconButtonTheme: IconButtonThemeData(style: IconButton.styleFrom(minimumSize: const Size(44, 44))),
    chipTheme: ChipThemeData(
      backgroundColor: surface,
      selectedColor: dark ? primary : ink,
      secondarySelectedColor: primary,
      labelStyle: text.labelLarge?.copyWith(fontWeight: FontWeight.w500),
      secondaryLabelStyle: text.labelLarge?.copyWith(color: dark ? onPrimary : LuxeColors.ivory),
      side: BorderSide(color: hairline),
      shape: pill,
      showCheckmark: false,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: surface,
      contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(LuxeRadius.card),
        borderSide: BorderSide(color: hairline),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(LuxeRadius.card),
        borderSide: BorderSide(color: hairline),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(LuxeRadius.card),
        borderSide: BorderSide(color: primary, width: 1.4),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(LuxeRadius.card),
        borderSide: const BorderSide(color: LuxeColors.danger),
      ),
      labelStyle: text.bodyMedium?.copyWith(color: muted),
      hintStyle: text.bodyMedium?.copyWith(color: muted),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: surface,
      surfaceTintColor: Colors.transparent,
      indicatorColor: dark ? Color.lerp(primary, LuxeColors.plum, .6) : Color.lerp(primary, Colors.white, .7),
      elevation: 0,
      height: 68,
      labelTextStyle: WidgetStatePropertyAll(text.labelSmall?.copyWith(letterSpacing: .2, color: ink)),
    ),
    bottomSheetTheme: BottomSheetThemeData(
      backgroundColor: surface,
      surfaceTintColor: Colors.transparent,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(LuxeRadius.sheet))),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: surface,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      titleTextStyle: text.titleLarge,
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: dark ? LuxeColors.ivory : LuxeColors.ink,
      contentTextStyle: text.bodyMedium?.copyWith(color: dark ? LuxeColors.ink : LuxeColors.ivory),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(LuxeRadius.card)),
    ),
    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? Colors.white : null),
      trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? primary : null),
    ),
    tabBarTheme: TabBarThemeData(
      labelColor: ink,
      unselectedLabelColor: muted,
      indicatorColor: primary,
      dividerColor: hairline,
      labelStyle: text.labelLarge,
    ),
    progressIndicatorTheme: ProgressIndicatorThemeData(color: primary, linearTrackColor: hairline),
  );
}
