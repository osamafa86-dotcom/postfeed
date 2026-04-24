import Foundation
import SwiftUI

@MainActor
final class SessionStore: ObservableObject {
    @Published var user: User?
    @Published var stats: ProfileStats?
    @Published var isLoading: Bool = false
    @Published var lastError: String?

    var isLoggedIn: Bool { user != nil && TokenStore.shared.hasToken }

    /// Called once on app launch to restore a previously logged-in session.
    func restore() async {
        guard TokenStore.shared.hasToken else { return }
        await refreshProfile()
    }

    func refreshProfile() async {
        do {
            let resp = try await Endpoints.profile()
            self.user = resp.user
            self.stats = resp.stats
        } catch APIError.unauthorized {
            // Stale token — clear it so the UI shows the login screen.
            signOutLocally()
        } catch {
            // Keep existing user state on transient failures.
            self.lastError = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    func signIn(email: String, password: String) async throws {
        isLoading = true
        defer { isLoading = false }
        let resp = try await Endpoints.login(email: email, password: password)
        TokenStore.shared.set(resp.token)
        self.user = resp.user
        await refreshProfile()
    }

    func register(name: String, email: String, password: String, username: String?) async throws {
        isLoading = true
        defer { isLoading = false }
        let resp = try await Endpoints.register(name: name, email: email, password: password, username: username)
        TokenStore.shared.set(resp.token)
        self.user = resp.user
        await refreshProfile()
    }

    func signOut() async {
        _ = try? await Endpoints.logout()
        signOutLocally()
    }

    func deleteAccount(password: String) async throws {
        _ = try await Endpoints.deleteAccount(password: password)
        signOutLocally()
    }

    func signOutLocally() {
        TokenStore.shared.set(nil)
        user = nil
        stats = nil
    }
}
