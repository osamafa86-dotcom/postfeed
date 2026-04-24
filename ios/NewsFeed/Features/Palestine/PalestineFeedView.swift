import SwiftUI

@MainActor
final class PalestineFeedViewModel: ObservableObject {
    @Published var items: [Article] = []
    @Published var isLoading = false
    @Published var errorMessage: String?
    private var nextCursor: Int?
    private var hasMore = true

    func load(reset: Bool) async {
        if reset { nextCursor = nil; hasMore = true }
        guard hasMore else { return }
        isLoading = true
        defer { isLoading = false }
        do {
            let page = try await Endpoints.palestine(beforeId: nextCursor, limit: 20)
            if reset { items = page.items } else { items.append(contentsOf: page.items) }
            nextCursor = page.nextCursor
            hasMore = page.nextCursor != nil
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct PalestineFeedView: View {
    @StateObject private var vm = PalestineFeedViewModel()
    var body: some View {
        ScrollView {
            LazyVStack(spacing: 12) {
                if vm.isLoading && vm.items.isEmpty {
                    ProgressView().padding(.top, 60)
                } else {
                    ForEach(vm.items) { a in
                        NavigationLink(value: a.id) { ArticleCardView(article: a) }
                            .buttonStyle(.plain)
                    }
                    if vm.isLoading { ProgressView().padding(.vertical, 12) }
                }
            }
            .padding(.vertical, 12)
        }
        .refreshable { await vm.load(reset: true) }
        .task { if vm.items.isEmpty { await vm.load(reset: true) } }
    }
}
