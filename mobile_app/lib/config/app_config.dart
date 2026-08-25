// lib/config/app_config.dart
class AppConfig {
  static const String appName = 'BEM Astawidya';
  
  // URL Server Backend PHP BEM (Production HTTPS)
  static const String baseUrl = 'https://bembudiutomo.my.id';
  
  static String get initialUrl => '$baseUrl/astawidya/bem.php?key=astawidya-bem';
  static String get dashboardUrl => '$baseUrl/admin/core/dashboard.php';
  static String get registerFcmUrl => '$baseUrl/api/mobile/register-fcm-token.php';
  static String get googleNativeLoginUrl => '$baseUrl/api/mobile/google-native-login.php';
  
  // Google OAuth 2.0 Web Client ID (Diambil dari .env Web BEM)
  static const String googleWebClientId = '17692236322-pk70et9hemin1cvdk5a8t3oeso7cpbis.apps.googleusercontent.com';

  // Custom User-Agent agar Google OAuth & BEM Server mengenali perangkat mobile
  static const String customUserAgent = 
      'Mozilla/5.0 (Linux; Android 13; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile BEMApp/1.0 Safari/537.36';
}
