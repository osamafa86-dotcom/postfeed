import Foundation

// Aggregated homepage response — mirrors /api/v1/feed/home.php
struct HomeFeedResponse: Decodable {
    let ok: Bool
    let generatedAt: String
    let stats: HomeStats
    let hero: Article?
    let featuredGrid: [Article]
    let palestine: PalestineRail
    let breaking: [Article]
    let categoryRails: [CategoryRail]
    let mostRead: [Article]
    let evolvingStories: [EvolvingStorySummary]
    let readersNow: Int

    enum CodingKeys: String, CodingKey {
        case ok, stats, hero, palestine, breaking
        case generatedAt = "generated_at"
        case featuredGrid = "featured_grid"
        case categoryRails = "category_rails"
        case mostRead = "most_read"
        case evolvingStories = "evolving_stories"
        case readersNow = "readers_now"
    }
}

struct HomeStats: Decodable {
    let totalArticles: Int
    let activeSources: Int
    let viewsToday: Int
    let lastUpdate: String?

    enum CodingKeys: String, CodingKey {
        case totalArticles = "total_articles"
        case activeSources = "active_sources"
        case viewsToday = "views_today"
        case lastUpdate = "last_update"
    }
}

struct PalestineRail: Decodable {
    let hero: Article?
    let items: [Article]
}

struct CategoryRail: Identifiable, Decodable {
    var id: String { slug }
    let slug: String
    let name: String
    let icon: String?
    let cssClass: String?
    let items: [Article]

    enum CodingKeys: String, CodingKey {
        case slug, name, icon, items
        case cssClass = "css_class"
    }
}

struct EvolvingStorySummary: Identifiable, Decodable, Hashable {
    let id: Int
    let slug: String
    let name: String
    let description: String?
    let icon: String?
    let coverImage: String?
    let accentColor: String?
    let articleCount: Int

    enum CodingKeys: String, CodingKey {
        case id, slug, name, description, icon
        case coverImage = "cover_image"
        case accentColor = "accent_color"
        case articleCount = "article_count"
    }
}

// Telegram

struct TelegramResponse: Decodable {
    let ok: Bool
    let count: Int
    let items: [TelegramMessage]
    let sources: [TelegramSource]
}

struct TelegramMessage: Identifiable, Decodable, Hashable {
    let id: Int
    let messageId: Int
    let postURL: String?
    let text: String
    let imageURL: String?
    let postedAt: String
    let source: TelegramSource

    enum CodingKeys: String, CodingKey {
        case id, text, source
        case messageId = "message_id"
        case postURL = "post_url"
        case imageURL = "image_url"
        case postedAt = "posted_at"
    }
}

struct TelegramSource: Identifiable, Decodable, Hashable {
    let id: Int
    let username: String
    let name: String
    let avatarURL: String?

    enum CodingKeys: String, CodingKey {
        case id, username, name
        case avatarURL = "avatar_url"
    }
}

// Reels

struct ReelsResponse: Decodable {
    let ok: Bool
    let count: Int
    let items: [Reel]
}

struct Reel: Identifiable, Decodable, Hashable {
    let id: Int
    let shortcode: String
    let caption: String?
    let instagramURL: String
    let embedURL: String
    let thumbnailURL: String?
    let createdAt: String
    let source: ReelSource?

    enum CodingKeys: String, CodingKey {
        case id, shortcode, caption, source
        case instagramURL = "instagram_url"
        case embedURL = "embed_url"
        case thumbnailURL = "thumbnail_url"
        case createdAt = "created_at"
    }
}

struct ReelSource: Identifiable, Decodable, Hashable {
    let id: Int
    let name: String
    let username: String
    let avatarURL: String?

    enum CodingKeys: String, CodingKey {
        case id, name, username
        case avatarURL = "avatar_url"
    }
}

// Evolving stories

struct EvolvingStoriesResponse: Decodable {
    let ok: Bool
    let count: Int
    let items: [EvolvingStorySummary]
}

struct EvolvingStoryDetailResponse: Decodable {
    let ok: Bool
    let story: EvolvingStorySummary
    let articles: [Article]
    let timeline: EvolvingStoryTimeline?
}

struct EvolvingStoryTimeline: Decodable {
    let headline: String?
    let intro: String?
    let generatedAt: String?

    enum CodingKeys: String, CodingKey {
        case headline, intro
        case generatedAt = "generated_at"
    }
}

// Podcast

struct PodcastResponse: Decodable {
    let ok: Bool
    let episode: PodcastEpisode?
}

struct PodcastArchiveResponse: Decodable {
    let ok: Bool
    let count: Int
    let items: [PodcastEpisodeSummary]
}

struct PodcastEpisode: Identifiable, Decodable, Hashable {
    let id: Int
    let date: String
    let title: String
    let subtitle: String?
    let intro: String?
    let script: String?
    let chapters: [PodcastChapter]
    let articleIDs: [Int]
    let audioURL: String?
    let durationSeconds: Int
    let ttsProvider: String?
    let playCount: Int

    enum CodingKeys: String, CodingKey {
        case id, date, title, subtitle, intro, script, chapters
        case articleIDs = "article_ids"
        case audioURL = "audio_url"
        case durationSeconds = "duration_seconds"
        case ttsProvider = "tts_provider"
        case playCount = "play_count"
    }
}

struct PodcastEpisodeSummary: Identifiable, Decodable, Hashable {
    let id: Int
    let date: String
    let title: String
    let subtitle: String?
    let durationSeconds: Int
    let audioURL: String?
    let playCount: Int

    enum CodingKeys: String, CodingKey {
        case id, date, title, subtitle
        case durationSeconds = "duration_seconds"
        case audioURL = "audio_url"
        case playCount = "play_count"
    }
}

struct PodcastChapter: Decodable, Hashable {
    let time: Int?
    let title: String
    let articleID: Int?

    enum CodingKeys: String, CodingKey {
        case time, title
        case articleID = "article_id"
    }
}

// Weekly

struct WeeklyResponse: Decodable {
    let ok: Bool
    let rewind: WeeklyRewind?
}

struct WeeklyRewind: Decodable {
    let yearWeek: String
    let startDate: String
    let endDate: String
    let title: String?
    let subtitle: String?
    let coverImageURL: String?
    let introText: String?

    enum CodingKeys: String, CodingKey {
        case title, subtitle
        case yearWeek = "year_week"
        case startDate = "start_date"
        case endDate = "end_date"
        case coverImageURL = "cover_image_url"
        case introText = "intro_text"
    }
}
