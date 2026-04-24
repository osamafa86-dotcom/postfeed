import SwiftUI

/// Reusable vertical list of articles for a category slug, with cursor
/// pagination. Used by Breaking, Latest, Political, Economy, etc.
@MainActor
final class CategoryFeedViewModel: ObservableObject {
    @Published var items: [Article] = []
    @Published var isLoading = false
    @Published var errorMessage: String?
    private var nextCursor: Int?
    private var hasMore = true

    func load(categorySlug: String?, breaking: Bool, reset: Bool) async {
        if reset { nextCursor = nil; hasMore = true }
        guard hasMore else { return }
        isLoading = true
        defer { isLoading = false }
        do {
            let page = try await Endpoints.feed(
                category: categorySlug == "breaking" ? nil : categorySlug,
                breaking: breaking || categorySlug == "breaking",
                beforeId: nextCursor,
                limit: 20
            )
            if reset { items = page.items } else { items.append(contentsOf: page.items) }
            nextCursor = page.nextCursor
            hasMore = page.nextCursor != nil
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    func loadMoreIfNeeded(categorySlug: String?, breaking: Bool, current: Article) async {
        guard let last = items.last, last.id == current.id, hasMore, !isLoading else { return }
        await load(categorySlug: categorySlug, breaking: breaking, reset: false)
    }
}

struct CategoryFeedView: View {
    let categorySlug: String?
    let title: String
    var breaking: Bool = false

    @StateObject private var vm = CategoryFeedViewModel()

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 12) {
                if vm.isLoading && vm.items.isEmpty {
                    ProgressView().padding(.top, 60)
                } else if let err = vm.errorMessage, vm.items.isEmpty {
                    ErrorState(message: err) {
                        Task { await vm.load(categorySlug: categorySlug, breaking: breaking, reset: true) }
                    }
                    .padding(.top, 40)
                } else {
                    ForEach(vm.items) { a in
                        NavigationLink(value: a.id) {
                            ArticleCardView(article: a)
                        }
                        .buttonStyle(.plain)
                        .task { await vm.loadMoreIfNeeded(categorySlug: categorySlug, breaking: breaking, current: a) }
                    }
                    if vm.isLoading { ProgressView().padding(.vertical, 16) }
                }
            }
            .padding(.vertical, 12)
        }
        .refreshable { await vm.load(categorySlug: categorySlug, breaking: breaking, reset: true) }
        .task { if vm.items.isEmpty { await vm.load(categorySlug: categorySlug, breaking: breaking, reset: true) } }
    }
}

struct LatestFeedView: View {
    var body: some View {
        CategoryFeedView(categorySlug: nil, title: "آخر الأخبار")
    }
}
