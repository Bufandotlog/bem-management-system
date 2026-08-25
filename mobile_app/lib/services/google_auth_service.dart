// lib/services/google_auth_service.dart
import 'dart:convert';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:http/http.dart' as http;
import '../config/app_config.dart';

class GoogleAuthService {
  static final GoogleSignIn _googleSignIn = GoogleSignIn(
    serverClientId: AppConfig.googleWebClientId.isNotEmpty ? AppConfig.googleWebClientId : null,
    scopes: ['email', 'profile', 'openid'],
  );

  /// Eksekusi Login Google Native di HP & Otentikasi ke Backend PHP
  static Future<Map<String, dynamic>?> signInWithGoogleNative() async {
    try {
      final GoogleSignInAccount? googleUser = await _googleSignIn.signIn();
      if (googleUser == null) return null; // User membatalkan login

      final GoogleSignInAuthentication googleAuth = await googleUser.authentication;
      final String? idToken = googleAuth.idToken;

      if (idToken == null) {
        print("Failed to retrieve Google ID Token");
        return null;
      }

      // Kirim ID token ke backend BEM PHP untuk verifikasi & set session
      final response = await http.post(
        Uri.parse(AppConfig.googleNativeLoginUrl),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'id_token': idToken}),
      );

      if (response.statusCode == 200) {
        final Map<String, dynamic> data = jsonDecode(response.body);
        
        // Ambil Set-Cookie header dari respon PHP jika ada
        String? rawCookie = response.headers['set-cookie'];
        data['session_cookie'] = rawCookie;
        return data;
      } else {
        print("Backend login error: ${response.body}");
        return {'status': 'error', 'message': jsonDecode(response.body)['message'] ?? 'Login failed'};
      }
    } catch (e) {
      print("Google Sign-In Error: $e");
      return {'status': 'error', 'message': 'Terjadi kesalahan autentikasi Google Native'};
    }
  }

  static Future<void> signOut() async {
    try {
      await _googleSignIn.signOut();
    } catch (e) {
      print("Google Sign-Out Error: $e");
    }
  }
}
