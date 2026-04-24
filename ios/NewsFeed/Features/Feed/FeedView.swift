import SwiftUI

struct FeedView: View {
    @StateObject private var vm = FeedViewModel()
    @Environment(\.deepLink) private var deepLink

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 12) {
                categoryBar
                if vm.isLoading && vm.articles.isEmpty {
                    ProgressView().padding(.top, 40)
                } else if let err = vm.errorMessage, vm.articles.isEmpty {
                    ErrorState(message: err) { Task { await vm.loadInitial() } }
                } else {
                    ForEach(vm.articles) { article in
                        NavigationLink(value: article.id) {
                            ArticleCardView(article: article)
                        }
                        .buttonStyle(.plain)
                        .onAppear { Task { await vm.loadMoreIfNeeded(current: article) } }
                    }
                    if vm.isLoadingMore {
                        ProgressView().padding(.vertical, 16)
                    }
                }
            }
            .padding(.vertical, 12)
        }
        .navigationTitle("نيوز فيد")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                NavigationLink(destination: NotificationsView()) {
                    Image(systemName: "bell.fill")
                }
            }
        }
        .refreshable { await vm.refresh() }
        .task { if vm.articles.isEmpty { await vm.loadInitial() } }
        .navigationDestination(for: Int.self) { articleId in
            ArticleDetailView(articleId: articleId)
        }
        .onReceive(NotificationCenter.default.publisher(for: .openArticle)) { note in
            // Handled by navigation inside ArticleDetailView via deep link.
            _ = note
        }
    }

    private var categoryBar: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                chip(title: "الكل", slug: nil)
                ForEach(vm.categories) { c in
                    chip(title: (c.icon.map { "\($0) " } ?? "") + c.name, slug: c.slug)
                }
            }
            .padding(.horizontal, 16)
        }
    }

    @ViewBuilder
    private func chip(title: String, slug: String?) -> some View {
        let selected = vm.selectedCategory == slug
        Button {
            Task { await vm.selectCategory(slug) }
        } label: {
            Text(title)
                .font(.system(size: 14, weight: selected ? .semibold : .medium))
                .foregroundColor(selected ? .white : .primary)
                .padding(.horizontal, 14)
                .padding(.vertical, 8)
                .background(selected ? Color.brand : Color.secondary.opacity(0.12))
                .clipShape(Capsule())
        }
    }
}

struct ErrorState: View {
    let message: String
    let retry: () -> Void
    var body: some View {
        VStack(spacing: 12) {
            Image(systemName: "wifi.exclamationmark")
                .font(.system(size: 40))
                .foregroundColor(.secondary)
            Text(message)
                .multilineTextAlignment(.center)
                .foregroundColor(.secondary)
            Button("إعادة المحاولة", action: retry)
                .buttonStyle(.borderedProminent)
                .tint(.brand)
        }
        .padding(32)
    }
}
