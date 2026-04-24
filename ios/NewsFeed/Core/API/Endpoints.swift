import Foundation

/// High-level calls matching /api/v1/*.php endpoints.
/// Kept separate from APIClient so feature code reads naturally.
enum Endpoints {

    // MARK: - Articles

    static func feed(category: String? = nil,
                     source: String? = nil,
                     breaking: Bool = false,
                     beforeId: Int? = nil,
                     limit: Int = 20) async throws -> FeedPage {
        try await APIClient.shared.get("articles.php", query: [
            "category": category,
            "source": source,
            "breaking": breaking ? "1" : nil,
            "before_id": beforeId.map(String.init),
            "limit": String(limit),
        ], authed: TokenStore.shared.hasToken)
    }

    static func article(id: Int) async throws -> ArticleDetailResponse {
        try await APIClient.shared.get("article.php", query: ["id": String(id)],
                                       authed: TokenStore.shared.hasToken)
    }

    static func search(_ q: String, limit: Int = 20) async throws -> SearchResponse {
        try await APIClient.shared.get("search.php", query: [
            "q": q,
            "limit": String(limit),
        ])
    }

    static func trending(limit: Int = 10) async throws -> TrendingResponse {
        try await APIClient.shared.get("trending.php", query: ["limit": String(limit)])
    }

    static func categories() async throws -> CategoriesResponse {
        try await APIClient.shared.get("categories.php", authed: TokenStore.shared.hasToken)
    }

    static func sources() async throws -> SourcesResponse {
        try await APIClient.shared.get("sources.php", authed: TokenStore.shared.hasToken)
    }

    static func ask(_ question: String) async throws -> AskResponse {
        try await APIClient.shared.get("ask.php", query: ["q": question])
    }

    // MARK: - Auth

    static func login(email: String, password: String) async throws -> AuthResponse {
        let body = LoginBody(email: email, password: password,
                             device_name: UIDeviceInfo.deviceName,
                             app_version: APIConfig.appVersion,
                             platform: "ios")
        return try await APIClient.shared.post("auth/login.php", body: body)
    }

    static func register(name: String, email: String, password: String, username: String?) async throws -> AuthResponse {
        let body = RegisterBody(name: name, email: email, password: password,
                                username: username,
                                device_name: UIDeviceInfo.deviceName,
                                app_version: APIConfig.appVersion)
        return try await APIClient.shared.post("auth/register.php", body: body)
    }

    static func logout() async throws -> OKResponse {
        try await APIClient.shared.post("auth/logout.php", body: EmptyBody(), authed: true)
    }

    static func deleteAccount(password: String) async throws -> OKResponse {
        try await APIClient.shared.post("auth/delete_account.php",
                                        body: PasswordBody(password: password), authed: true)
    }

    // MARK: - User

    static func profile() async throws -> ProfileResponse {
        try await APIClient.shared.get("user/me.php", authed: true)
    }

    static func updateProfile(_ patch: ProfilePatch) async throws -> ProfileResponse {
        try await APIClient.shared.post("user/me.php", body: patch, authed: true)
    }

    static func bookmarks(beforeId: Int? = nil, limit: Int = 20) async throws -> FeedPage {
        try await APIClient.shared.get("user/bookmarks.php", query: [
            "before_id": beforeId.map(String.init),
            "limit": String(limit),
        ], authed: true)
    }

    static func toggleBookmark(articleId: Int) async throws -> BookmarkToggleResponse {
        try await APIClient.shared.post("user/bookmarks.php",
                                        body: IDBody(article_id: articleId),
                                        authed: true)
    }

    static func follow(kind: FollowKind, id: Int, action: FollowAction = .toggle) async throws -> FollowResponse {
        try await APIClient.shared.post("user/follow.php",
                                        body: FollowBody(kind: kind.rawValue, id: id, action: action.rawValue),
                                        authed: true)
    }

    static func react(articleId: Int, reaction: String?) async throws -> ReactionResponse {
        try await APIClient.shared.post("reactions.php",
                                        body: ReactionBody(article_id: articleId, reaction: reaction),
                                        authed: true)
    }

    static func comments(articleId: Int) async throws -> CommentsResponse {
        try await APIClient.shared.get("comments.php", query: ["article_id": String(articleId)])
    }

    static func addComment(articleId: Int, body: String) async throws -> CommentResponse {
        try await APIClient.shared.post("comments.php",
                                        body: CommentBody(article_id: articleId, body: body),
                                        authed: true)
    }

    static func report(kind: String, targetId: Int, reason: String?) async throws -> OKResponse {
        try await APIClient.shared.post("report.php",
                                        body: ReportBody(kind: kind, target_id: targetId, reason: reason),
                                        authed: true)
    }

    static func notifications() async throws -> NotificationsResponse {
        try await APIClient.shared.get("notifications.php", authed: true)
    }

    static func markNotificationsRead(id: Int? = nil) async throws -> OKResponse {
        try await APIClient.shared.post("notifications.php",
                                        body: NotificationReadBody(action: id == nil ? "read_all" : "read", id: id),
                                        authed: true)
    }

    // MARK: - Push

    static func registerDevice(pushToken: String, isSandbox: Bool) async throws -> OKResponse {
        let body = DeviceRegisterBody(
            push_token: pushToken,
            platform: "ios",
            bundle_id: Bundle.main.bundleIdentifier ?? "net.feedsnews.newsfeed",
            locale: Locale.current.identifier,
            app_version: APIConfig.appVersion,
            os_version: UIDeviceInfo.systemVersion,
            device_model: UIDeviceInfo.model,
            is_sandbox: isSandbox
        )
        return try await APIClient.shared.post("devices/register.php", body: body,
                                               authed: TokenStore.shared.hasToken)
    }
}

// MARK: - Request bodies

enum FollowKind: String { case category, source }
enum FollowAction: String { case toggle, follow, unfollow }

struct EmptyBody: Encodable {}
struct LoginBody: Encodable { let email, password, device_name, app_version, platform: String }
struct RegisterBody: Encodable { let name, email, password: String; let username: String?; let device_name, app_version: String }
struct PasswordBody: Encodable { let password: String }
struct IDBody: Encodable { let article_id: Int }
struct FollowBody: Encodable { let kind: String; let id: Int; let action: String }
struct ReactionBody: Encodable { let article_id: Int; let reaction: String? }
struct CommentBody: Encodable { let article_id: Int; let body: String }
struct ReportBody: Encodable { let kind: String; let target_id: Int; let reason: String? }
struct NotificationReadBody: Encodable { let action: String; let id: Int? }
struct DeviceRegisterBody: Encodable {
    let push_token, platform, bundle_id, locale, app_version, os_version, device_model: String
    let is_sandbox: Bool
}
