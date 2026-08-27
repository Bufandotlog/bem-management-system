// lib/services/fcm_service.dart
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:http/http.dart' as http;
import '../config/app_config.dart';

class FcmService {
  static final FirebaseMessaging _firebaseMessaging = FirebaseMessaging.instance;

  static Future<void> initialize() async {
    try {
      NotificationSettings settings = await _firebaseMessaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
      );

      if (settings.authorizationStatus == AuthorizationStatus.authorized) {
        // Token diambil untuk registrasi ke backend, tapi TIDAK dicetak ke log
        // (risiko kebocoran identifier perangkat via logcat).
        await _firebaseMessaging.getToken();

        // Listener jika token diperbarui oleh Firebase (tanpa mencetak token).
        _firebaseMessaging.onTokenRefresh.listen((_) {});
      }
    } catch (e) {
      debugPrint("FCM Service Init Error (Diabaikan jika belum ada google-services.json): $e");
    }
  }

  /// Setup Handler saat Notifikasi Ditekan Pengguna (Deep Linking)
  static void setupNotificationListeners(Function(String url) onOpenUrl) {
    try {
      FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
        final String? clickAction = message.data['click_action'];
        if (clickAction != null && clickAction.isNotEmpty) {
          final String fullUrl = clickAction.startsWith('http') 
              ? clickAction 
              : '${AppConfig.baseUrl}$clickAction';
          onOpenUrl(fullUrl);
        }
      });
    } catch (e) {
      debugPrint("FCM Notification Listener Error: $e");
    }
  }

  /// Mengirimkan FCM Token ke backend PHP BEM
  static Future<bool> sendTokenToServer(String sessionCookie) async {
    try {
      String? token = await _firebaseMessaging.getToken();
      if (token == null) return false;

      final response = await http.post(
        Uri.parse(AppConfig.registerFcmUrl),
        headers: {
          'Content-Type': 'application/json',
          'Cookie': sessionCookie,
        },
        body: jsonEncode({
          'fcm_token': token,
          'device_type': 'android',
          'app_version': '1.0.0',
        }),
      );

      return response.statusCode == 200;
    } catch (e) {
      debugPrint("Failed to send FCM token to server: $e");
      return false;
    }
  }
}
