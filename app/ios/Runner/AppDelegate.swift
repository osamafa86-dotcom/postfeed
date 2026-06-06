import Flutter
import UIKit
import FirebaseInstallations

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    // Native bridge that PushService calls after Firebase.initializeApp()
    // has run. Doing it through a channel guarantees Firebase is configured
    // before Installations.installations() touches it — calling it directly
    // in didFinishLaunchingWithOptions crashes since this app supplies
    // FirebaseOptions explicitly from Dart instead of using GoogleService-Info.plist.
    if let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "InstallationsBridge") {
      let channel = FlutterMethodChannel(
        name: "postfeed.feedsnews/installations",
        binaryMessenger: registrar.messenger()
      )
      channel.setMethodCallHandler { call, result in
        if call.method == "deleteInstallation" {
          Installations.installations().delete { error in
            if let error = error {
              result(FlutterError(code: "FAILED", message: error.localizedDescription, details: nil))
            } else {
              result(nil)
            }
          }
        } else {
          result(FlutterMethodNotImplemented)
        }
      }
    }
  }
}
