// lib/views/webview_screen.dart
import 'dart:async';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import '../config/app_config.dart';
import '../services/download_service.dart';
import '../services/fcm_service.dart';
import '../services/google_auth_service.dart';
import 'offline_screen.dart';

class WebViewScreen extends StatefulWidget {
  const WebViewScreen({super.key});

  @override
  State<WebViewScreen> createState() => _WebViewScreenState();
}

class _WebViewScreenState extends State<WebViewScreen> {
  InAppWebViewController? _webViewController;
  PullToRefreshController? _pullToRefreshController;

  bool _isOffline = false;
  double _progress = 0;
  String? _loadError;
  late StreamSubscription<List<ConnectivityResult>> _connectivitySubscription;

  @override
  void initState() {
    super.initState();
    _checkInitialConnectivity();
    _initConnectivityListener();

    FcmService.setupNotificationListeners((targetUrl) {
      _webViewController?.loadUrl(
        urlRequest: URLRequest(url: WebUri(targetUrl)),
      );
    });

    _pullToRefreshController = PullToRefreshController(
      settings: PullToRefreshSettings(color: Colors.blueAccent),
      onRefresh: () async {
        _clearError();
        _webViewController?.reload();
      },
    );
  }

  @override
  void dispose() {
    _connectivitySubscription.cancel();
    super.dispose();
  }

  Future<void> _checkInitialConnectivity() async {
    final result = await Connectivity().checkConnectivity();
    if (!mounted) return;
    setState(() {
      _isOffline = result.contains(ConnectivityResult.none);
    });
  }

  void _initConnectivityListener() {
    _connectivitySubscription = Connectivity().onConnectivityChanged.listen((results) {
      if (!mounted) return;
      final offline = results.contains(ConnectivityResult.none);
      setState(() {
        _isOffline = offline;
      });
      if (!offline) {
        _clearError();
        _webViewController?.reload();
      }
    });
  }

  void _clearError() {
    if (_loadError != null && mounted) {
      setState(() => _loadError = null);
    }
  }

  Future<void> _handleGoogleNativeLogin() async {
    final result = await GoogleAuthService.signInWithGoogleNative();
    if (result != null && result['status'] == 'success') {
      String? cookieHeader = result['session_cookie'];
      if (cookieHeader != null) {
        // Sync cookie to WebView
        CookieManager cookieManager = CookieManager.instance();
        await cookieManager.setCookie(
          url: WebUri(AppConfig.baseUrl),
          name: "PHPSESSID",
          value: cookieHeader.split(';')[0].replaceAll("PHPSESSID=", ""),
        );
        await FcmService.sendTokenToServer(cookieHeader);
      }

      _clearError();
      _webViewController?.loadUrl(
        urlRequest: URLRequest(url: WebUri(AppConfig.dashboardUrl)),
      );
    } else if (result != null && result.containsKey('message')) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(result['message']),
            backgroundColor: Colors.redAccent,
          ),
        );
      }
    }
  }

  Future<void> _onBackPressed() async {
    if (_webViewController != null && await _webViewController!.canGoBack()) {
      await _webViewController!.goBack();
    } else {
      // Tidak ada history WebView -> biarkan sistem menangani (keluar app).
      if (mounted) Navigator.of(context).maybePop();
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isOffline) {
      return OfflineScreen(onRetry: () {
        _checkInitialConnectivity();
      });
    }

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        // Intercept back: kembali dalam WebView bila ada history,
        // baru keluar app bila sudah di halaman awal.
        _onBackPressed();
      },
      child: Scaffold(
        backgroundColor: Colors.white,
        appBar: PreferredSize(
          preferredSize: const Size.fromHeight(0), // Full Screen WebView dengan Status Bar
          child: AppBar(backgroundColor: const Color(0xFF0F172A), elevation: 0),
        ),
        body: SafeArea(
          child: Stack(
            children: [
              InAppWebView(
                initialUrlRequest: URLRequest(url: WebUri(AppConfig.initialUrl)),
                initialSettings: InAppWebViewSettings(
                  userAgent: AppConfig.customUserAgent,
                  javaScriptEnabled: true,
                  domStorageEnabled: true,
                  useOnDownloadStart: true,
                  // File-access flags sengaja TIDAK diaktifkan: aplikasi hanya
                  // memuat https://bembudiutomo.my.id (tidak ada file://), sehingga
                  // allowFileAccessFromFileURLs/allowUniversalAccessFromFileURLs
                  // tidak diperlukan dan dibiarkan pada default aman (false).
                  // Mengaktifkannya hanya memperluas attack surface (page bisa
                  // membaca file lokal via file://).
                  hardwareAcceleration: true,
                  supportMultipleWindows: false,
                ),
                pullToRefreshController: _pullToRefreshController,
                onWebViewCreated: (controller) {
                  _webViewController = controller;

                  // Daftarkan JavaScript Channel untuk komunikasi Native -> Web
                  controller.addJavaScriptHandler(
                    handlerName: 'NativeGoogleLogin',
                    callback: (args) {
                      _handleGoogleNativeLogin();
                    },
                  );
                },
                onLoadStop: (controller, url) async {
                  _pullToRefreshController?.endRefreshing();

                  // Jika user berhasil login di web BEM, otomatis Daftarkan FCM Token
                  if (url != null && url.toString().contains('/admin/')) {
                    CookieManager cookieManager = CookieManager.instance();
                    List<Cookie> cookies = await cookieManager.getCookies(url: url);
                    String cookieHeader = cookies.map((c) => "${c.name}=${c.value}").join("; ");
                    await FcmService.sendTokenToServer(cookieHeader);
                  }
                  _clearError();
                },
                onProgressChanged: (controller, progress) {
                  if (progress == 100) {
                    _pullToRefreshController?.endRefreshing();
                  }
                  if (mounted) {
                    setState(() {
                      _progress = progress / 100;
                    });
                  }
                },
                onReceivedError: (controller, request, error) async {
                  // Gagal load resource (mis. tidak ada koneksi / DNS / timeout).
                  if (mounted) {
                    setState(() {
                      _loadError = error.description.isNotEmpty
                          ? error.description
                          : 'Gagal memuat halaman';
                    });
                  }
                },
                onReceivedHttpError: (controller, request, response) async {
                  // Respons HTTP error (4xx/5xx) dari server.
                  if (mounted) {
                    setState(() {
                      _loadError = 'Halaman mengembalikan kesalahan '
                          '(HTTP ${response.statusCode ?? '?'}).';
                    });
                  }
                },
                onDownloadStartRequest: (controller, request) async {
                  // Intercept pengunduhan file PDF / DOCX dari WebView BEM
                  String fileName = request.suggestedFilename ?? "dokumen_bem.pdf";
                  await DownloadService.downloadAndOpenFile(request.url.toString(), fileName);
                },
              ),
              if (_progress < 1.0 && _loadError == null)
                LinearProgressIndicator(
                  value: _progress,
                  backgroundColor: Colors.transparent,
                  valueColor: const AlwaysStoppedAnimation<Color>(Colors.blueAccent),
                ),
              if (_loadError != null)
                _ErrorOverlay(
                  message: _loadError!,
                  onRetry: () {
                    _clearError();
                    _webViewController?.loadUrl(
                      urlRequest: URLRequest(url: WebUri(AppConfig.initialUrl)),
                    );
                  },
                ),
            ],
          ),
        ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: _handleGoogleNativeLogin,
          backgroundColor: const Color(0xFF0F172A),
          icon: const Icon(Icons.g_mobiledata, size: 30, color: Colors.white),
          label: const Text(
            "Login Google",
            style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
          ),
        ),
      ),
    );
  }
}

class _ErrorOverlay extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;

  const _ErrorOverlay({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: Colors.white,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(24.0),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.cloud_off, size: 64, color: Colors.blueAccent),
              const SizedBox(height: 16),
              Text(
                message,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 16, color: Colors.black87),
              ),
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: onRetry,
                child: const Text('Coba Lagi'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
