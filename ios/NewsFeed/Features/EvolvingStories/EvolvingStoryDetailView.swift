import SwiftUI

@MainActor
final class EvolvingStoryDetailViewModel: ObservableObject {
    @Published var story: EvolvingStorySummary?
    @Published var articles: [Article] = []
    @Published var timeline: EvolvingStoryTimeline?
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load(slug: String) async {
        isLoading = true; defer { isLoading = false }
        do {
            let resp = try await Endpoints.evolvingStory(slug: slug)
            story = resp.story
            articles = resp.articles
            timeline = resp.timeline
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct EvolvingStoryDetailView: View {
    let slug: String
    @StateObject private var vm = EvolvingStoryDetailViewModel()

    var body: some View {
        ScrollView {
            VStack(alignment: .trailing, spacing: 14) {
                if let story = vm.story {
                    ZStack(alignment: .bottomTrailing) {
                        RemoteImage(url: story.coverImage)
                            .frame(height: 220)
                            .clipped()
                        LinearGradient(colors: [.clear, .black.opacity(0.85)], startPoint: .top, endPoint: .bottom)
                        VStack(alignment: .trailing) {
                            Text("\(story.icon ?? "📚") \(story.name)")
                                .font(.system(size: 24, weight: .heavy))
                                .foregroundColor(.white)
                            if let desc = story.description {
                                Text(desc)
                                    .font(.system(size: 14))
                                    .foregroundColor(.white.opacity(0.9))
                            }
                        }
                        .padding(14)
                    }
                    .clipShape(RoundedRectangle(cornerRadius: 14))
                    .padding(.horizontal, 16)

                    if let tl = vm.timeline {
                        VStack(alignment: .trailing, spacing: 6) {
                            if let h = tl.headline {
                                Text(h).font(.system(size: 18, weight: .bold))
                            }
                            if let intro = tl.intro {
                                Text(intro).font(.system(size: 14)).foregroundColor(.secondary)
                            }
                        }
                        .padding(.horizontal, 16)
                    }

                    if !vm.articles.isEmpty {
                        Text("آخر المقالات").font(.headline).padding(.horizontal, 16)
                        ForEach(vm.articles) { a in
                            NavigationLink(value: a.id) {
                                ArticleCardView(article: a)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                } else if vm.isLoading {
                    ProgressView().padding(.top, 60)
                } else if let err = vm.errorMessage {
                    ErrorState(message: err) { Task { await vm.load(slug: slug) } }
                        .padding(.top, 40)
                }
            }
            .padding(.vertical, 12)
        }
        .task { await vm.load(slug: slug) }
    }
}
