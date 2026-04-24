import Foundation

// MARK: - Common

struct OKResponse: Decodable { let ok: Bool }

// MARK: - Feed

struct FeedPage: Decodable {
    let ok: Bool
    let count: Int
    let items: [Article]
    let nextCursor: Int?

    enum CodingKeys: String, CodingKey { case ok, count, items; case nextCursor = "next_cursor" }
}

struct SearchResponse: Decodable {
    let ok: Bool
    let query: String
    let count: Int
    let items: [Article]
}

// MARK: - Article detail

struct ArticleDetailResponse: Decodable {
    let ok: Bool
    let article: Article
    let related: [Article]
    let userState: UserArticleState?

    enum CodingKeys: String, CodingKey { case ok, article, related; case userState = "user_state" }
}

struct UserArticleState: Decodable, Hashable {
    let bookmarked: Bool
    let reaction: String?
}

// MARK: - Trending

struct TrendingResponse: Decodable {
    let ok: Bool
    let count: Int
    let readersNow: Int
    let generatedAt: String
    let items: [TrendingItem]

    enum CodingKeys: String, CodingKey {
        case ok, count, items
        case readersNow = "readers_now"
        case generatedAt = "generated_at"
    }
}

struct TrendingItem: Identifiable, Decodable, Hashable {
    let id: Int?
    let title: String
    let imageURL: String?
    let publishedAt: String?
    let category: String?
    let categorySlug: String?
    let source: String?
    let velocityScore: Int?
    let viewsLastHour: Int
    let viewsLast6h: Int
    let clusterKey: String?
    let clusterSize: Int

    enum CodingKeys: String, CodingKey {
        case id, title, category, source
        case imageURL = "image_url"
        case publishedAt = "published_at"
        case categorySlug = "category_slug"
        case velocityScore = "velocity_score"
        case viewsLastHour = "views_last_hour"
        case viewsLast6h = "views_last_6h"
        case clusterKey = "cluster_key"
        case clusterSize = "cluster_size"
    }
}

// MARK: - Categories / Sources

struct CategoriesResponse: Decodable {
    let ok: Bool
    let items: [CategoryItem]
}

struct CategoryItem: Identifiable, Decodable, Hashable {
    let id: Int
    let name: String
    let slug: String
    let icon: String?
    let cssClass: String?
    let sortOrder: Int
    var following: Bool

    enum CodingKeys: String, CodingKey {
        case id, name, slug, icon, following
        case cssClass = "css_class"
        case sortOrder = "sort_order"
    }
}

struct SourcesResponse: Decodable {
    let ok: Bool
    let items: [SourceItem]
}

struct SourceItem: Identifiable, Decodable, Hashable {
    let id: Int
    let name: String
    let slug: String
    let logoLetter: String?
    let logoColor: String?
    let logoBg: String?
    let url: String?
    let articlesToday: Int
    var following: Bool

    enum CodingKeys: String, CodingKey {
        case id, name, slug, url, following
        case logoLetter = "logo_letter"
        case logoColor = "logo_color"
        case logoBg = "logo_bg"
        case articlesToday = "articles_today"
    }
}

// MARK: - Auth / Profile

struct AuthResponse: Decodable {
    let ok: Bool
    let token: String
    let tokenType: String
    let expiresIn: Int
    let user: User

    enum CodingKeys: String, CodingKey {
        case ok, token, user
        case tokenType = "token_type"
        case expiresIn = "expires_in"
    }
}

struct User: Decodable, Equatable {
    let id: Int
    let name: String
    let username: String?
    let email: String
    let avatarLetter: String?
    let bio: String?
    let theme: String
    let role: String
    let readingStreak: Int
    let notifyBreaking: Bool
    let notifyFollowed: Bool
    let notifyDigest: Bool
    let plan: String
    let createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, name, username, email, bio, theme, role, plan
        case avatarLetter = "avatar_letter"
        case readingStreak = "reading_streak"
        case notifyBreaking = "notify_breaking"
        case notifyFollowed = "notify_followed"
        case notifyDigest = "notify_digest"
        case createdAt = "created_at"
    }
}

struct ProfileResponse: Decodable {
    let ok: Bool
    let user: User
    let stats: ProfileStats
}

struct ProfileStats: Decodable, Equatable {
    let bookmarks: Int
    let followedCategories: Int
    let followedSources: Int
    let unreadNotifications: Int

    enum CodingKeys: String, CodingKey {
        case bookmarks
        case followedCategories = "followed_categories"
        case followedSources = "followed_sources"
        case unreadNotifications = "unread_notifications"
    }
}

struct ProfilePatch: Encodable {
    var name: String?
    var bio: String?
    var theme: String?
    var notify_breaking: Bool?
    var notify_followed: Bool?
    var notify_digest: Bool?
}

// MARK: - Bookmarks / Reactions / Comments

struct BookmarkToggleResponse: Decodable {
    let ok: Bool
    let saved: Bool
    let articleId: Int

    enum CodingKeys: String, CodingKey { case ok, saved; case articleId = "article_id" }
}

struct FollowResponse: Decodable {
    let ok: Bool
    let following: Bool
}

struct ReactionResponse: Decodable {
    let ok: Bool
    let reaction: String?
    let likes: Int
    let dislikes: Int
}

struct CommentsResponse: Decodable {
    let ok: Bool
    let count: Int
    let items: [Comment]
}

struct CommentResponse: Decodable {
    let ok: Bool
    let comment: Comment
}

struct Comment: Identifiable, Decodable, Hashable {
    let id: Int
    let articleId: Int
    let userId: Int
    let userName: String
    let avatarLetter: String
    let body: String
    let likes: Int
    let createdAt: String

    enum CodingKeys: String, CodingKey {
        case id, body, likes
        case articleId = "article_id"
        case userId = "user_id"
        case userName = "user_name"
        case avatarLetter = "avatar_letter"
        case createdAt = "created_at"
    }
}

// MARK: - Notifications

struct NotificationsResponse: Decodable {
    let ok: Bool
    let unread: Int
    let items: [AppNotification]
}

struct AppNotification: Identifiable, Decodable, Hashable {
    let id: Int
    let type: String
    let title: String
    let body: String?
    let icon: String?
    let articleId: Int?
    let isRead: Bool
    let createdAt: String

    enum CodingKeys: String, CodingKey {
        case id, type, title, body, icon
        case articleId = "article_id"
        case isRead = "is_read"
        case createdAt = "created_at"
    }
}

// MARK: - Ask

struct AskResponse: Decodable {
    let ok: Bool
    let question: String
    let answer: String
    let articles: [AskArticle]
    let followUps: [String]
    let confident: Bool

    enum CodingKeys: String, CodingKey {
        case ok, question, answer, articles, confident
        case followUps = "follow_ups"
    }
}

struct AskArticle: Identifiable, Decodable, Hashable {
    let id: Int
    let title: String
    let imageURL: String?
    let url: String?

    enum CodingKeys: String, CodingKey { case id, title, url; case imageURL = "image_url" }
}
