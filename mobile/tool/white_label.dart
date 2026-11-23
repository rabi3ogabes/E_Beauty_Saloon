// White-label generator for the Luxe Salon client app.
//
// Usage (run from mobile/):
//   dart run tool/white_label.dart --tenant lumiere
//   dart run tool/white_label.dart --fetch lumiere --api https://lumiere.luxesalon.app [--bundle-id app.luxesalon.lumiere]
//   dart run tool/white_label.dart --tenant lumiere --app staff     # iOS bundle id/name for the staff app
//   dart run tool/white_label.dart --tenant lumiere --dry-run
//
// It writes:
//   lib/config/tenant_config.g.dart        Dart constants (name, slug, API, colours, logo)
//   android/tenant.properties              applicationId + label (read by android/app/build.gradle.kts)
//   ios/Runner.xcodeproj/project.pbxproj   PRODUCT_BUNDLE_IDENTIFIER
//   ios/Runner/Info.plist                  CFBundleDisplayName
//   assets/brand/logo.png                  bundled logo (copied from the config)
//   flutter_launcher_icons.yaml            then: dart run flutter_launcher_icons
//   flutter_native_splash.yaml             then: dart run flutter_native_splash:create
//
// No package dependencies, so it runs in CI before `flutter pub get`.

import 'dart:convert';
import 'dart:io';

void main(List<String> argv) async {
  final args = _Args.parse(argv);
  if (args.flag('help') || argv.isEmpty) {
    stdout.writeln(_usage);
    exit(argv.isEmpty ? 64 : 0);
  }

  final root = Directory.current;
  if (!File('${root.path}/pubspec.yaml').existsSync()) {
    stderr.writeln('Run this from the mobile/ directory.');
    exit(66);
  }

  late WhiteLabelConfig config;
  final fetchSlug = args.option('fetch');
  if (fetchSlug != null) {
    final api = args.option('api') ?? 'https://$fetchSlug.luxesalon.app';
    stdout.writeln('Fetching $api/api/v1/salon for "$fetchSlug"…');
    config = await WhiteLabelConfig.fetch(slug: fetchSlug, apiBaseUrl: api, bundleId: args.option('bundle-id'));
    final file = File('white_label/$fetchSlug.json');
    if (!args.flag('dry-run')) {
      file.parent.createSync(recursive: true);
      file.writeAsStringSync('${const JsonEncoder.withIndent('  ').convert(config.toJson())}\n');
      stdout.writeln('  wrote ${file.path}');
    }
  } else {
    final slug = args.option('tenant');
    final path = args.option('config') ?? (slug == null ? null : 'white_label/$slug.json');
    if (path == null) {
      stderr.writeln('Pass --tenant <slug>, --config <file> or --fetch <slug>.');
      exit(64);
    }
    final file = File(path);
    if (!file.existsSync()) {
      stderr.writeln('Config not found: $path');
      exit(66);
    }
    config = WhiteLabelConfig.fromJson(jsonDecode(file.readAsStringSync()) as Map<String, dynamic>);
  }

  final errors = config.validate();
  if (errors.isNotEmpty) {
    stderr.writeln('Invalid config:\n  - ${errors.join('\n  - ')}');
    exit(65);
  }

  final app = args.option('app') ?? 'client';
  if (!['client', 'staff', 'owner'].contains(app)) {
    stderr.writeln('--app must be client, staff or owner');
    exit(64);
  }

  final gen = Generator(config, app: app, dryRun: args.flag('dry-run'));
  gen.writeDartConfig();
  gen.writeAndroid();
  gen.writeIos();
  gen.copyLogo();
  gen.writeIconAndSplashConfigs();

  stdout.writeln('''

Done: ${config.appName} (${config.tenant}) → ${gen.iosBundleId}
Next:
  dart run flutter_launcher_icons
  dart run flutter_native_splash:create
  flutter build apk --flavor $app -t lib/main_$app.dart --release
  flutter build ipa -t lib/main_$app.dart --release   # iOS uses the bundle id written above
''');
}

const _usage = '''
Luxe Salon white-label generator

  --tenant <slug>       Use white_label/<slug>.json
  --config <file>       Use an explicit JSON config
  --fetch <slug>        Build the config from GET /api/v1/salon (X-Tenant: <slug>) and save it
  --api <url>           Backend origin for --fetch (default https://<slug>.luxesalon.app)
  --bundle-id <id>      Bundle id for --fetch (default app.luxesalon.<slug>)
  --app <client|staff|owner>  Which app iOS is configured for (default client)
  --dry-run             Print what would change
  --help
''';

class WhiteLabelConfig {
  WhiteLabelConfig({
    required this.tenant,
    required this.appName,
    required this.bundleId,
    required this.apiBaseUrl,
    required this.colors,
    this.defaultLocale = 'en',
    this.splashColor,
    this.splashColorDark,
    this.logo,
    this.icon,
    this.iconForeground,
    this.iconBackground,
    this.supportWhatsapp,
    this.staffBundleId,
    this.ownerBundleId,
  });

  factory WhiteLabelConfig.fromJson(Map<String, dynamic> j) => WhiteLabelConfig(
    tenant: '${j['tenant'] ?? ''}',
    appName: '${j['appName'] ?? ''}',
    bundleId: '${j['bundleId'] ?? ''}',
    apiBaseUrl: '${j['apiBaseUrl'] ?? ''}',
    defaultLocale: '${j['defaultLocale'] ?? 'en'}',
    colors: Map<String, String>.from((j['colors'] as Map?) ?? const {}),
    splashColor: j['splashColor'] as String?,
    splashColorDark: j['splashColorDark'] as String?,
    logo: j['logo'] as String?,
    icon: j['icon'] as String?,
    iconForeground: j['iconForeground'] as String?,
    iconBackground: j['iconBackground'] as String?,
    supportWhatsapp: j['supportWhatsapp'] as String?,
    staffBundleId: j['staffBundleId'] as String?,
    ownerBundleId: j['ownerBundleId'] as String?,
  );

  /// Builds a config from the live `GET /api/v1/salon` branding.
  static Future<WhiteLabelConfig> fetch({required String slug, required String apiBaseUrl, String? bundleId}) async {
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 15);
    try {
      final req = await client.getUrl(Uri.parse('${apiBaseUrl.replaceAll(RegExp(r'/+$'), '')}/api/v1/salon'));
      req.headers
        ..set('X-Tenant', slug)
        ..set('Accept', 'application/json');
      final res = await req.close();
      final body = await res.transform(utf8.decoder).join();
      if (res.statusCode != 200) {
        throw HttpException('GET /salon returned ${res.statusCode}: $body');
      }
      final data = (jsonDecode(body) as Map<String, dynamic>)['data'] as Map<String, dynamic>;
      final branding = Map<String, dynamic>.from((data['branding'] as Map?) ?? const {});
      final primary = (branding['primary'] as String?) ?? '#C8A96A';
      final background = (branding['background'] as String?) ?? '#FAF7F2';
      final locales = (data['locales'] as List?)?.cast<String>() ?? const ['en'];
      return WhiteLabelConfig(
        tenant: slug,
        appName: '${data['name'] ?? slug}',
        bundleId: bundleId ?? 'app.luxesalon.${slug.replaceAll(RegExp('[^a-z0-9]'), '')}',
        apiBaseUrl: apiBaseUrl,
        defaultLocale: locales.isNotEmpty ? locales.first : 'en',
        colors: {
          'primary': primary,
          'primaryDark': _darken(primary, .16),
          'accent': (branding['accent'] as String?) ?? '#E9D6CF',
          'ink': (branding['ink'] as String?) ?? '#1C1A17',
          'background': background,
        },
        splashColor: background,
        splashColorDark: '#141014',
        // logo_url is downloaded into white_label/assets/<slug>/ by copyLogo when it is remote.
        logo: branding['logo_url'] as String?,
        iconBackground: background,
      );
    } finally {
      client.close();
    }
  }

  final String tenant;
  final String appName;
  final String bundleId;
  final String apiBaseUrl;
  final String defaultLocale;
  final Map<String, String> colors;
  final String? splashColor;
  final String? splashColorDark;
  final String? logo;
  final String? icon;
  final String? iconForeground;
  final String? iconBackground;
  final String? supportWhatsapp;
  final String? staffBundleId;
  final String? ownerBundleId;

  String color(String key, String fallback) => colors[key] ?? fallback;

  List<String> validate() => [
    if (!RegExp(r'^[a-z0-9-]+$').hasMatch(tenant)) 'tenant must be a lowercase slug',
    if (appName.trim().isEmpty) 'appName is required',
    if (!RegExp(r'^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$').hasMatch(bundleId)) 'bundleId must look like com.company.app',
    if (Uri.tryParse(apiBaseUrl)?.hasScheme != true) 'apiBaseUrl must be an absolute URL',
    for (final e in colors.entries)
      if (_hex(e.value) == null) 'colors.${e.key} must be #RRGGBB',
  ];

  Map<String, dynamic> toJson() => {
    'tenant': tenant,
    'appName': appName,
    'bundleId': bundleId,
    'apiBaseUrl': apiBaseUrl,
    'defaultLocale': defaultLocale,
    'colors': colors,
    'splashColor': ?splashColor,
    'splashColorDark': ?splashColorDark,
    'logo': ?logo,
    'icon': ?icon,
    'iconForeground': ?iconForeground,
    'iconBackground': ?iconBackground,
    'supportWhatsapp': ?supportWhatsapp,
    'staffBundleId': ?staffBundleId,
    'ownerBundleId': ?ownerBundleId,
  };
}

class Generator {
  Generator(this.c, {required this.app, required this.dryRun});

  final WhiteLabelConfig c;
  final String app;
  final bool dryRun;

  static const _logoAsset = 'assets/brand/logo.png';

  String get iosBundleId => switch (app) {
    'staff' => c.staffBundleId ?? 'app.luxesalon.staff',
    'owner' => c.ownerBundleId ?? 'app.luxesalon.owner',
    _ => c.bundleId,
  };

  String get iosDisplayName => switch (app) {
    'staff' => 'Luxe Staff',
    'owner' => 'Luxe Owner',
    _ => c.appName,
  };

  void _write(String path, String content) {
    if (dryRun) {
      stdout.writeln('--- $path (dry run)\n$content');
      return;
    }
    File(path)
      ..parent.createSync(recursive: true)
      ..writeAsStringSync(content);
    stdout.writeln('  wrote $path');
  }

  void writeDartConfig() {
    String col(String key, String fallback) => 'Color(0x${_hex(c.color(key, fallback))})';
    _write('lib/config/tenant_config.g.dart', '''
// GENERATED by tool/white_label.dart from white_label/${c.tenant}.json — do not edit by hand.
// Re-run: dart run tool/white_label.dart --tenant ${c.tenant}

import 'dart:ui' show Color;

import 'tenant_config.dart';

const TenantConfig generatedTenantConfig = TenantConfig(
  appName: ${_dartString(c.appName)},
  tenantSlug: ${_dartString(c.tenant)},
  apiBaseUrl: ${_dartString(c.apiBaseUrl)},
  bundleId: ${_dartString(c.bundleId)},
  primary: ${col('primary', '#C8A96A')},
  primaryDark: ${col('primaryDark', '#A88B4F')},
  accent: ${col('accent', '#E9D6CF')},
  ink: ${col('ink', '#1C1A17')},
  background: ${col('background', '#FAF7F2')},
  splashColor: Color(0x${_hex(c.splashColor ?? c.color('background', '#FAF7F2'))}),
  logoAsset: ${c.logo == null ? 'null' : _dartString(_logoAsset)},
  defaultLocale: ${_dartString(c.defaultLocale)},
  supportWhatsapp: ${c.supportWhatsapp == null ? 'null' : _dartString(c.supportWhatsapp!)},
);
''');
  }

  void writeAndroid() {
    _write(
      'android/tenant.properties',
      '''
# GENERATED by tool/white_label.dart from white_label/${c.tenant}.json — do not edit by hand.
tenant=${c.tenant}
applicationId=${c.bundleId}
appName=${c.appName}
${c.staffBundleId == null ? '' : 'staffApplicationId=${c.staffBundleId}\n'}${c.ownerBundleId == null ? '' : 'ownerApplicationId=${c.ownerBundleId}\n'}''',
    );
  }

  void writeIos() {
    final pbx = File('ios/Runner.xcodeproj/project.pbxproj');
    if (pbx.existsSync()) {
      final id = iosBundleId;
      var text = pbx.readAsStringSync();
      text = text.replaceAllMapped(
        RegExp(r'PRODUCT_BUNDLE_IDENTIFIER = ([^;]+);'),
        (m) => m[1]!.endsWith('.RunnerTests') ? 'PRODUCT_BUNDLE_IDENTIFIER = $id.RunnerTests;' : 'PRODUCT_BUNDLE_IDENTIFIER = $id;',
      );
      if (dryRun) {
        stdout.writeln('--- ${pbx.path}: PRODUCT_BUNDLE_IDENTIFIER = $id');
      } else {
        pbx.writeAsStringSync(text);
        stdout.writeln('  updated ${pbx.path}');
      }
    }
    final plist = File('ios/Runner/Info.plist');
    if (plist.existsSync()) {
      final text = plist.readAsStringSync().replaceFirstMapped(
        RegExp(r'(<key>CFBundleDisplayName</key>\s*<string>)([^<]*)(</string>)'),
        (m) => '${m[1]}${_xml(iosDisplayName)}${m[3]}',
      );
      if (dryRun) {
        stdout.writeln('--- ${plist.path}: CFBundleDisplayName = $iosDisplayName');
      } else {
        plist.writeAsStringSync(text);
        stdout.writeln('  updated ${plist.path}');
      }
    }
  }

  void copyLogo() {
    final src = c.logo;
    if (src == null || src.startsWith('http')) {
      if (src != null) stdout.writeln('  note: remote logo $src — download it to white_label/assets/${c.tenant}/logo.png and set "logo".');
      return;
    }
    final file = File(src);
    if (!file.existsSync()) {
      stderr.writeln('  warning: logo not found at $src');
      return;
    }
    if (dryRun) {
      stdout.writeln('--- copy $src → $_logoAsset');
      return;
    }
    File(_logoAsset).parent.createSync(recursive: true);
    file.copySync(_logoAsset);
    stdout.writeln('  copied $src → $_logoAsset');
  }

  void writeIconAndSplashConfigs() {
    final icon = c.icon ?? c.logo ?? _logoAsset;
    _write('flutter_launcher_icons.yaml', '''
# GENERATED by tool/white_label.dart (${c.tenant}). Run: dart run flutter_launcher_icons
flutter_launcher_icons:
  android: "ic_launcher"
  ios: true
  remove_alpha_ios: true
  image_path: "$icon"
  adaptive_icon_background: "${c.iconBackground ?? c.color('background', '#FAF7F2')}"
  adaptive_icon_foreground: "${c.iconForeground ?? icon}"
  min_sdk_android: 23
''');
    _write('flutter_native_splash.yaml', '''
# GENERATED by tool/white_label.dart (${c.tenant}). Run: dart run flutter_native_splash:create
flutter_native_splash:
  color: "${c.splashColor ?? c.color('background', '#FAF7F2')}"
  color_dark: "${c.splashColorDark ?? '#141014'}"
  image: "${c.logo != null && !c.logo!.startsWith('http') ? c.logo : _logoAsset}"
  android_12:
    color: "${c.splashColor ?? c.color('background', '#FAF7F2')}"
    color_dark: "${c.splashColorDark ?? '#141014'}"
    image: "${c.iconForeground ?? icon}"
  fullscreen: false
''');
  }
}

/// `#C8A96A` → `FFC8A96A`
String? _hex(String value) {
  var h = value.trim().replaceFirst('#', '').toUpperCase();
  if (h.length == 6) h = 'FF$h';
  if (!RegExp(r'^[0-9A-F]{8}$').hasMatch(h)) return null;
  return h;
}

String _darken(String hex, double amount) {
  final h = _hex(hex);
  if (h == null) return hex;
  int ch(int i) => (int.parse(h.substring(i, i + 2), radix: 16) * (1 - amount)).round().clamp(0, 255);
  String two(int v) => v.toRadixString(16).padLeft(2, '0').toUpperCase();
  return '#${two(ch(2))}${two(ch(4))}${two(ch(6))}';
}

String _dartString(String s) => "'${s.replaceAll(r'\', r'\\').replaceAll("'", r"\'").replaceAll(r'$', r'\$')}'";

String _xml(String s) => s.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');

class _Args {
  _Args(this._options, this._flags);

  factory _Args.parse(List<String> argv) {
    final options = <String, String>{};
    final flags = <String>{};
    for (var i = 0; i < argv.length; i++) {
      final a = argv[i];
      if (!a.startsWith('--')) continue;
      final key = a.substring(2);
      if (key.contains('=')) {
        final parts = key.split('=');
        options[parts.first] = parts.sublist(1).join('=');
      } else if (i + 1 < argv.length && !argv[i + 1].startsWith('--')) {
        options[key] = argv[++i];
      } else {
        flags.add(key);
      }
    }
    return _Args(options, flags);
  }

  final Map<String, String> _options;
  final Set<String> _flags;

  String? option(String key) => _options[key];
  bool flag(String key) => _flags.contains(key);
}
