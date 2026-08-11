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
    setState(() {
      _isOffline = result.contains(ConnectivityResult.none);
    });
  }

  void _initConnectivityListener() {
    _connectivitySubscription = Connectivity().onConnectivityChanged.listen((results) {
      setState(() {
        _isOffline = results.contains(ConnectivityResult.none);
      });
      if (!_isOffline) {
        _webViewController?.reload();
      }
    });
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

  @override
  Widget build(BuildContext context) {
    if (_isOffline) {
      return OfflineScreen(onRetry: () {
        _checkInitialConnectivity();
      });
    }

    return Scaffold(
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
                allowFileAccessFromFileURLs: true,
                allowUniversalAccessFromFileURLs: true,
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
                if (url.toString().contains('/admin/')) {
                  CookieManager cookieManager = CookieManager.instance();
                  List<Cookie> cookies = await cookieManager.getCookies(url: url!);
                  String cookieHeader = cookies.map((c) => "${c.name}=${c.value}").join("; ");
                  await FcmService.sendTokenToServer(cookieHeader);
                }
              },
              onProgressChanged: (controller, progress) {
                if (progress == 100) {
                  _pullToRefreshController?.endRefreshing();
                }
                setState(() {
                  _progress = progress / 100;
                });
              },
              onDownloadStartRequest: (controller, request) async {
                // Intercept pengunduhan file PDF / DOCX dari WebView BEM
                String fileName = request.suggestedFilename ?? "dokumen_bem.pdf";
                await DownloadService.downloadAndOpenFile(request.url.toString(), fileName);
              },
            ),
            if (_progress < 1.0)
              LinearProgressIndicator(
                value: _progress,
                backgroundColor: Colors.transparent,
                valueColor: const AlwaysStoppedAnimation<Color>(Colors.blueAccent),
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
    );
  }
}
