// lib/services/download_service.dart
import 'dart:async';
import 'dart:io';
import 'dart:isolate';
import 'dart:ui';
import 'package:flutter/foundation.dart';
import 'package:flutter_downloader/flutter_downloader.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';

const String _downloadPortName = 'downloader_send_port';

class DownloadService {
  static Future<void> initialize() async {
    // ignoreSsl dihilangkan: default false (validasi SSL aktif) — aman untuk production.
    await FlutterDownloader.initialize(debug: false);
  }

  /// Memicu pengunduhan file dari URL WebView & membukanya secara native
  /// setelah download benar-benar selesai (status complete).
  static Future<void> downloadAndOpenFile(String url, String fileName) async {
    ReceivePort? port;
    try {
      if (Platform.isAndroid) {
        // Penyimpanan menggunakan getExternalStorageDirectory() (app-private
        // external storage), sehingga Permission.storage sudah cukup.
        // MANAGE_EXTERNAL_STORAGE tidak diperlukan dan tidak digunakan.
        final status = await Permission.storage.request();
        if (!status.isGranted) {
          // Biarkan task tetap di-enqueue; file tersimpan di app-private dir.
          debugPrint('Izin storage tidak diberikan; download ke direktori app-private.');
        }
      }

      final Directory? dir = Platform.isAndroid
          ? await getExternalStorageDirectory()
          : await getApplicationDocumentsDirectory();

      if (dir == null) return;

      // Daftarkan listener status download dari background isolate.
      port = ReceivePort();
      IsolateNameServer.registerPortWithName(port.sendPort, _downloadPortName);

      final completer = Completer<String>();
      late StreamSubscription<dynamic> sub;
      sub = port.listen((dynamic data) {
        final DownloadTaskStatus status = DownloadTaskStatus.fromInt(data[1] as int);
        // data[2] = progress
        if (status == DownloadTaskStatus.complete) {
          completer.complete('$dir.path/$fileName');
          sub.cancel();
        } else if (status == DownloadTaskStatus.failed ||
            status == DownloadTaskStatus.canceled) {
          if (!completer.isCompleted) {
            completer.completeError('Download ${status.name}');
          }
          sub.cancel();
        }
      });

      final taskId = await FlutterDownloader.enqueue(
        url: url,
        savedDir: dir.path,
        fileName: fileName,
        showNotification: true,
        openFileFromNotification: true,
      );

      if (taskId == null) {
        sub.cancel();
        debugPrint('Gagal membuat task download.');
        return;
      }

      // Tunggu hingga callback melaporkan status final (complete/failed/canceled).
      final result = await completer.future;

      // Hanya buka bila status complete dan file benar-benar ada.
      if (await File(result).exists()) {
        await OpenFilex.open(result);
      }
    } catch (e) {
      debugPrint('Download error: $e');
    } finally {
      // Hindari listener/port leak.
      port?.close();
      IsolateNameServer.removePortNameMapping(_downloadPortName);
    }
  }
}
