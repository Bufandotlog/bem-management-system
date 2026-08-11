// lib/services/biometric_service.dart
import 'package:flutter/services.dart';
import 'package:local_auth/local_auth.dart';

class BiometricService {
  static final LocalAuthentication _auth = LocalAuthentication();

  static Future<bool> isBiometricAvailable() async {
    try {
      final bool canAuthenticateWithBiometrics = await _auth.canCheckBiometrics;
      final bool canAuthenticate = canAuthenticateWithBiometrics || await _auth.isDeviceSupported();
      return canAuthenticate;
    } catch (e) {
      return false;
    }
  }

  static Future<bool> authenticate() async {
    try {
      if (!await isBiometricAvailable()) return true;

      return await _auth.authenticate(
        localizedReason: 'Pindai sidik jari / Face ID untuk mengakses Admin BEM Astawidya',
        options: const AuthenticationOptions(
          stickyAuth: true,
          biometricOnly: false,
        ),
      );
    } on PlatformException catch (e) {
      print("Biometric Error: $e");
      return false;
    }
  }
}
