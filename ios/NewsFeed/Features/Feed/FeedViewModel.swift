import Foundation

@MainActor
final class FeedViewModel: ObservableObject {
    @Published var articles: [Article] = []
    @Published var categories: [CategoryItem] = []
    @Published var selectedCategory: String? = nil
    @Published var isLoading = false
    @Published var isLoadingMore = false
    @Published var errorMessage: String?

    private var nextCursor: Int?
    private var hasMore: Bool = true

    func loadInitial() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        async let feedTask = Endpoints.feed(category: selectedCategory, limit: 20)
        async let catTask  = Endpoints.categories()
        do {
            let (feed, cats) = try await (feedTask, catTask)
            self.articles = feed.items
            self.nextCursor = feed.nextCursor
            self.hasMore = feed.nextCursor != nil
            self.categories = cats.items
        } catch {
            self.errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    func refresh() async {
        nextCursor = nil
        hasMore = true
        do {
            let feed = try await Endpoints.feed(category: selectedCategory, limit: 20)
            self.articles = feed.items
            self.nextCursor = feed.nextCursor
            self.hasMore = feed.nextCursor != nil
        } catch {
            self.errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    func loadMoreIfNeeded(current article: Article) async {
        guard hasMore, !isLoadingMore,
              let last = articles.last, last.id == article.id else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }
        do {
            let feed = try await Endpoints.feed(category: selectedCategory,
                                                beforeId: nextCursor, limit: 20)
            self.articles.append(contentsOf: feed.items)
            self.nextCursor = feed.nextCursor
            self.hasMore = feed.nextCursor != nil
        } catch {
            // Silently ignore pagination errors; the user can pull-to-refresh.
        }
    }

    func selectCategory(_ slug: String?) async {
        selectedCategory = slug
        await loadInitial()
    }
}
