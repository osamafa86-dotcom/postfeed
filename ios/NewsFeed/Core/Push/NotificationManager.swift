import Foundation
import UserNotifications
import UIKit

final class NotificationManager: NSObject, UNUserNotificationCenterDelegate {
    static let shared = NotificationManager()

    private let authKey = "nf.push.asked"

    /// Ask the user for notification permission on first launch after they log in
    /// (or immediately, if you prefer — we do it eagerly here, OS dedupes).
    func requestAuthorizationIfNeeded() async {
        let center = UNUserNotificationCenter.current()
        let settings = await center.notificationSettings()
        switch settings.authorizationStatus {
        case .notDetermined:
            _ = try? await center.requestAuthorization(options: [.alert, .sound, .badge])
            await MainActor.run {
                UIApplication.shared.registerForRemoteNotifications()
            }
        case .authorized, .provisional, .ephemeral:
            await MainActor.run {
                UIApplication.shared.registerForRemoteNotifications()
            }
        default:
            break
        }
    }

    func registerDeviceToken(_ token: String) async {
        #if DEBUG
        let isSandbox = true
        #else
        let isSandbox = false
        #endif
        do {
            _ = try await Endpoints.registerDevice(pushToken: token, isSandbox: isSandbox)
        } catch {
            #if DEBUG
            print("device register failed:", error)
            #endif
        }
    }

    // Show banners in-foreground so users don't miss breaking news.
    func userNotificationCenter(_ center: UNUserNotificationCenter,
                                willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .sound, .list, .badge]
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter,
                                didReceive response: UNNotificationResponse) async {
        let info = response.notification.request.content.userInfo
        if let idStr = info["article_id"] as? String, let id = Int(idStr) {
            await MainActor.run {
                NotificationCenter.default.post(name: .openArticle, object: id)
            }
        } else if let id = info["article_id"] as? Int {
            await MainActor.run {
                NotificationCenter.default.post(name: .openArticle, object: id)
            }
        }
    }
}

extension Notification.Name {
    static let openArticle = Notification.Name("nf.openArticle")
}
