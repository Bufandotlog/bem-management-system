package com.bem.astawidya

// Pakai FlutterFragmentActivity (bukan FlutterActivity) supaya plugin
// local_auth (sidik jari) bisa attach ke FragmentActivity. Tanpa ini,
// pemanggilan BiometricService.authenticate() gagal dengan
// PlatformException(no_fragment_activity, ...). Gejala di logcat:
//   flutter: Biometric Error: PlatformException(no_fragment_activity, ...)
// Lihat pub.dev/local_auth_android-1.0.56/lib/messages.g.dart.
import io.flutter.embedding.android.FlutterFragmentActivity

class MainActivity : FlutterFragmentActivity()
