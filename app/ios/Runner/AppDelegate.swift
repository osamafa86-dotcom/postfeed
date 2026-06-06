import Flutter
import UIKit
import FirebaseInstallations

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    // One-time Firebase Installation reset per build. The Installation ID
    // is cached in iOS Keychain and survives app uninstall, so an FCM
    // token paired with a sandbox APNs token (from a debug/Xcode build)
    // keeps causing BadEnvironmentKeyInToken on production sends even
    // after the user reinstalls from TestFlight. Deleting the Installation
    // once per CFBundleVersion forces a fresh FID + FCM + APNs chain.
    let bundleVersion = Bundle.main.infoDictionary?["CFBundleVersion"] as? String ?? ""
    let lastResetKey = "_fcm_installation_reset_for_build"
    let lastReset = UserDefaults.standard.string(forKey: lastResetKey) ?? ""
    if !bundleVersion.isEmpty && lastReset != bundleVersion {
      Installations.installations().delete { _ in /* non-fatal */ }
      UserDefaults.standard.set(bundleVersion, forKey: lastResetKey)
    }
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
  }
}
