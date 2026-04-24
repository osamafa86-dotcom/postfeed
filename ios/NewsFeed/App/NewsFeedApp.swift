import SwiftUI
import UserNotifications

@main
struct NewsFeedApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @StateObject private var session = SessionStore()
    @StateObject private var theme = ThemeStore()
    @State private var deepLink: DeepLink?

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(session)
                .environmentObject(theme)
                .environment(\.layoutDirection, .rightToLeft)
                .preferredColorScheme(theme.colorScheme)
                .onOpenURL { url in
                    deepLink = DeepLink(url: url)
                }
                .environment(\.deepLink, deepLink)
                .task {
                    await session.restore()
                    await NotificationManager.shared.requestAuthorizationIfNeeded()
                }
        }
    }
}
