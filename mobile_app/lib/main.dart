// lib/main.dart
import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/material.dart';
import 'config/app_config.dart';
import 'services/download_service.dart';
import 'services/fcm_service.dart';
import 'views/splash_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  
  // Inisialisasi Firebase & FCM Notifikasi (Graceful handling jika google-services.json belum ada)
  try {
    await Firebase.initializeApp();
    await FcmService.initialize();
  } catch (e) {
    print("Firebase init diabaikan (Menunggu file google-services.json): $e");
  }

  // Inisialisasi Background Downloader
  await DownloadService.initialize();

  runApp(const BemMobileApp());
}

class BemMobileApp extends StatelessWidget {
  const BemMobileApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorSchemeSeed: Colors.blueAccent,
        fontFamily: 'Roboto',
      ),
      home: const SplashScreen(),
    );
  }
}
