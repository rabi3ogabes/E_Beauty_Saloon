import 'dart:async';
import 'dart:io' show Platform;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../../config/env.dart';

/// A push message reduced to what the UI needs.
class PushMessage {
  const PushMessage({this.title, this.body, this.route});

  final String? title;
  final String? body;

  /// In-app route to open, from `data.route` (e.g. `/bookings`).
  final String? route;
}

/// Firebase Cloud Messaging, fully guarded: if Firebase is not configured
/// (no google-services.json / GoogleService-Info.plist / dart-defines) the
/// app keeps running with push disabled.
class PushService {
  PushService._();

  static final PushService instance = PushService._();

  bool _available = false;
  bool get isAvailable => _available;

  final _foreground = StreamController<PushMessage>.broadcast();
  final _tokens = StreamController<String>.broadcast();
  String? _token;

  /// Last FCM registration token, if registered.
  String? get token => _token;

  /// Emits the FCM token on registration and on every refresh.
  Stream<String> get onToken => _tokens.stream;
  final _opened = StreamController<PushMessage>.broadcast();

  /// Messages received while the app is open.
  Stream<PushMessage> get onForegroundMessage => _foreground.stream;

  /// Notification taps (app in background or terminated).
  Stream<PushMessage> get onOpened => _opened.stream;

  Future<void> init() async {
    if (kIsWeb || !(Platform.isAndroid || Platform.isIOS)) return;
    try {
      if (Firebase.apps.isEmpty) {
        if (Env.firebaseApiKey.isNotEmpty && Env.firebaseAppId.isNotEmpty) {
          await Firebase.initializeApp(
            options: const FirebaseOptions(
              apiKey: Env.firebaseApiKey,
              appId: Env.firebaseAppId,
              messagingSenderId: Env.firebaseSenderId,
              projectId: Env.firebaseProjectId,
            ),
          );
        } else {
          await Firebase.initializeApp();
        }
      }
      _available = true;
    } catch (e) {
      debugPrint('Push disabled: Firebase is not configured ($e)');
      _available = false;
      return;
    }

    FirebaseMessaging.instance.onRegistered.listen((t) {
      _token = t;
      _tokens.add(t);
    });
    FirebaseMessaging.onMessage.listen((m) => _foreground.add(_map(m)));
    FirebaseMessaging.onMessageOpenedApp.listen((m) => _opened.add(_map(m)));
    try {
      final initial = await FirebaseMessaging.instance.getInitialMessage();
      if (initial != null) {
        // Delay so the router is mounted before we navigate.
        Future<void>.delayed(const Duration(milliseconds: 600), () => _opened.add(_map(initial)));
      }
    } catch (_) {}
  }

  /// Asks for permission (iOS / Android 13+) and registers with FCM.
  /// The token arrives on [onToken].
  Future<void> requestPermissionAndRegister() async {
    if (!_available) return;
    try {
      final settings = await FirebaseMessaging.instance.requestPermission();
      if (settings.authorizationStatus == AuthorizationStatus.denied) return;
      await FirebaseMessaging.instance.register();
    } catch (e) {
      debugPrint('Push registration failed: $e');
    }
  }

  static String get platformName => kIsWeb ? 'web' : (Platform.isIOS ? 'ios' : 'android');

  PushMessage _map(RemoteMessage m) => PushMessage(
    title: m.notification?.title ?? m.data['title'] as String?,
    body: m.notification?.body ?? m.data['body'] as String?,
    route: m.data['route'] as String?,
  );
}
