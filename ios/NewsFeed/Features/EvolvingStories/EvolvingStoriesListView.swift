import SwiftUI

@MainActor
final class EvolvingStoriesListViewModel: ObservableObject {
    @Published var items: [EvolvingStorySummary] = []
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load() async {
        isLoading = true; defer { isLoading = false }
        do {
            items = try await Endpoints.evolvingStories().items
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct EvolvingStoriesListView: View {
    @StateObject private var vm = EvolvingStoriesListViewModel()

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 12) {
                if vm.isLoading && vm.items.isEmpty {
                    ProgressView().padding(.top, 60)
                } else if vm.items.isEmpty {
                    EmptyStateView(icon: "sparkles", title: "لا توجد قصص متطوّرة حالياً")
                        .padding(.top, 60)
                } else {
                    ForEach(vm.items) { story in
                        NavigationLink(value: story.slug) {
                            EvolvingStoryBigCard(story: story)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .padding(16)
        }
        .refreshable { await vm.load() }
        .task { if vm.items.isEmpty { await vm.load() } }
    }
}

struct EvolvingStoryBigCard: View {
    let story: EvolvingStorySummary
    var body: some View {
        ZStack(alignment: .bottomTrailing) {
            RemoteImage(url: story.coverImage)
                .frame(height: 180)
                .clipped()
            LinearGradient(colors: [.clear, .black.opacity(0.8)], startPoint: .top, endPoint: .bottom)
            VStack(alignment: .trailing, spacing: 6) {
                if let accent = story.accentColor {
                    Text(story.icon ?? "📚")
                        .padding(6)
                        .background(Color(hex: accent) ?? .brand)
                        .foregroundColor(.white)
                        .clipShape(Circle())
                }
                Text(story.name)
                    .font(.system(size: 20, weight: .heavy))
                    .foregroundColor(.white)
                    .multilineTextAlignment(.trailing)
                if let desc = story.description {
                    Text(desc)
                        .font(.system(size: 13))
                        .foregroundColor(.white.opacity(0.9))
                        .multilineTextAlignment(.trailing)
                        .lineLimit(2)
                }
                HStack {
                    Image(systemName: "doc.text.fill")
                    Text("\(story.articleCount) مقال")
                }
                .font(.caption)
                .foregroundColor(.white.opacity(0.85))
            }
            .padding(14)
        }
        .frame(maxWidth: .infinity)
        .clipShape(RoundedRectangle(cornerRadius: 14))
    }
}
