// lib/services/download_service.dart
import 'dart:io';
import 'package:flutter_downloader/flutter_downloader.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';

class DownloadService {
  static Future<void> initialize() async {
    await FlutterDownloader.initialize(debug: true, ignoreSsl: true);
  }

  /// Memicu pengunduhan file dari URL WebView & membukanya secara native
  static Future<void> downloadAndOpenFile(String url, String fileName) async {
    try {
      if (Platform.isAndroid) {
        var status = await Permission.storage.request();
        if (!status.isGranted) {
          status = await Permission.manageExternalStorage.request();
        }
      }

      final Directory? dir = Platform.isAndroid
          ? await getExternalStorageDirectory()
          : await getApplicationDocumentsDirectory();

      if (dir != null) {
        final taskId = await FlutterDownloader.enqueue(
          url: url,
          savedDir: dir.path,
          fileName: fileName,
          showNotification: true,
          openFileFromNotification: true,
        );

        print("Download enqueued taskId: $taskId");
        
        // Membuka file secara lokal menggunakan OpenFilex
        String filePath = "${dir.path}/$fileName";
        await Future.delayed(const Duration(seconds: 2));
        if (await File(filePath).exists()) {
          await OpenFilex.open(filePath);
        }
      }
    } catch (e) {
      print("Download error: $e");
    }
  }
}
