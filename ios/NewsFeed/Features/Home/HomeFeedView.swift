import SwiftUI

/// Vertical composition of every section present on the web homepage,
/// in the same order. Built from a single /api/v1/feed/home.php call.
@MainActor
final class HomeFeedViewModel: ObservableObject {
    @Published var feed: HomeFeedResponse?
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            feed = try await Endpoints.homeFeed()
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct HomeFeedView: View {
    @StateObject private var vm = HomeFeedViewModel()

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                if vm.isLoading && vm.feed == nil {
                    ProgressView().padding(.top, 60)
                } else if let err = vm.errorMessage, vm.feed == nil {
                    ErrorState(message: err) { Task { await vm.load() } }
                        .padding(.top, 40)
                } else if let feed = vm.feed {
                    StatsStripView(stats: feed.stats, readersNow: feed.readersNow)

                    if let hero = feed.hero {
                        HeroArticleCard(article: hero)
                    }

                    if !feed.breaking.isEmpty {
                        BreakingStripView(items: feed.breaking)
                    }

                    if !feed.featuredGrid.isEmpty {
                        SectionHeader(title: "المميّز", icon: "star.fill")
                        FeaturedGridView(items: feed.featuredGrid)
                    }

                    if feed.palestine.hero != nil || !feed.palestine.items.isEmpty {
                        SectionHeader(title: "فلسطين", icon: "flag.fill")
                        PalestineRailView(rail: feed.palestine)
                    }

                    if !feed.evolvingStories.isEmpty {
                        SectionHeader(title: "قصص متطوّرة", icon: "sparkles")
                        EvolvingStoriesScrollView(items: feed.evolvingStories)
                    }

                    ForEach(feed.categoryRails) { rail in
                        if !rail.items.isEmpty {
                            CategoryRailSection(rail: rail)
                        }
                    }

                    if !feed.mostRead.isEmpty {
                        SectionHeader(title: "الأكثر قراءة", icon: "flame.fill")
                        MostReadListView(items: feed.mostRead)
                    }

                    NewsletterBandView()
                        .padding(.top, 8)

                    FooterView()
                        .padding(.top, 24)
                }
            }
            .padding(.vertical, 12)
        }
        .refreshable { await vm.load() }
        .task { if vm.feed == nil { await vm.load() } }
    }
}

// MARK: - Components

struct SectionHeader: View {
    let title: String
    let icon: String
    var linkText: String? = nil
    var action: (() -> Void)? = nil

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: icon).foregroundColor(.brand)
            Text(title).font(.system(size: 20, weight: .heavy))
            Spacer()
            if let linkText, let action {
                Button(linkText, action: action)
                    .font(.system(size: 13, weight: .semibold))
                    .foregroundColor(.brand)
            }
        }
        .padding(.horizontal, 16)
        .padding(.top, 10)
    }
}

struct StatsStripView: View {
    let stats: HomeStats
    let readersNow: Int

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                statChip(icon: "doc.text.fill", label: "مقال", value: compact(stats.totalArticles), color: .brand)
                statChip(icon: "globe", label: "مصدر", value: "\(stats.activeSources)", color: .blue)
                statChip(icon: "eye.fill", label: "مشاهدات اليوم", value: compact(stats.viewsToday), color: .orange)
                if readersNow > 0 {
                    statChip(icon: "circle.fill", label: "يتصفحون الآن", value: "\(readersNow)", color: .green)
                }
            }
            .padding(.horizontal, 16)
        }
    }

    private func statChip(icon: String, label: String, value: String, color: Color) -> some View {
        HStack(spacing: 8) {
            Image(systemName: icon).foregroundColor(color)
            VStack(alignment: .leading, spacing: 0) {
                Text(value).font(.system(size: 14, weight: .bold))
                Text(label).font(.system(size: 10)).foregroundColor(.secondary)
            }
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(Color.secondary.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }

    private func compact(_ n: Int) -> String {
        if n >= 1_000_000 { return String(format: "%.1fم", Double(n) / 1_000_000) }
        if n >= 1_000 { return String(format: "%.1fك", Double(n) / 1_000) }
        return "\(n)"
    }
}

struct HeroArticleCard: View {
    let article: Article
    var body: some View {
        NavigationLink(value: article.id) {
            ZStack(alignment: .bottomTrailing) {
                RemoteImage(url: article.imageURL)
                    .frame(height: 280)
                    .clipped()

                LinearGradient(
                    colors: [.clear, .black.opacity(0.8)],
                    startPoint: .top, endPoint: .bottom
                )

                VStack(alignment: .trailing, spacing: 8) {
                    if article.isBreaking {
                        Label("عاجل", systemImage: "bolt.fill")
                            .font(.caption.bold())
                            .padding(.horizontal, 8).padding(.vertical, 4)
                            .background(Color.breakingRed)
                            .foregroundColor(.white)
                            .clipShape(Capsule())
                    }
                    Text(article.title)
                        .font(.system(size: 22, weight: .heavy))
                        .foregroundColor(.white)
                        .multilineTextAlignment(.trailing)
                        .lineLimit(3)
                        .shadow(radius: 4)
                    HStack {
                        Text(article.source.name ?? "")
                            .font(.caption.bold())
                            .foregroundColor(.white.opacity(0.95))
                        Spacer()
                        Text(RelativeTime.arabic(from: article.publishedAt))
                            .font(.caption)
                            .foregroundColor(.white.opacity(0.85))
                    }
                }
                .padding(16)
            }
            .clipShape(RoundedRectangle(cornerRadius: 18))
            .padding(.horizontal, 16)
        }
        .buttonStyle(.plain)
    }
}

struct BreakingStripView: View {
    let items: [Article]
    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 6) {
                Image(systemName: "bolt.fill").foregroundColor(.breakingRed)
                Text("أخبار عاجلة")
                    .font(.system(size: 17, weight: .heavy))
                Spacer()
                LiveIndicator()
            }
            .padding(.horizontal, 16)

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    ForEach(items) { article in
                        NavigationLink(value: article.id) {
                            BreakingCard(article: article)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.horizontal, 16)
            }
        }
    }
}

struct BreakingCard: View {
    let article: Article
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ZStack(alignment: .topLeading) {
                RemoteImage(url: article.imageURL)
                    .frame(width: 240, height: 140)
                    .clipped()
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                Label("عاجل", systemImage: "bolt.fill")
                    .font(.caption2.bold())
                    .padding(.horizontal, 6).padding(.vertical, 3)
                    .background(Color.breakingRed)
                    .foregroundColor(.white)
                    .clipShape(Capsule())
                    .padding(8)
            }
            Text(article.title)
                .font(.system(size: 13, weight: .semibold))
                .lineLimit(2)
                .multilineTextAlignment(.trailing)
                .frame(width: 240, alignment: .trailing)
            Text(RelativeTime.arabic(from: article.publishedAt))
                .font(.caption2)
                .foregroundColor(.secondary)
        }
    }
}

struct LiveIndicator: View {
    @State private var pulse = false
    var body: some View {
        HStack(spacing: 4) {
            Circle()
                .fill(Color.breakingRed)
                .frame(width: 8, height: 8)
                .scaleEffect(pulse ? 1.2 : 1.0)
                .opacity(pulse ? 0.6 : 1.0)
                .onAppear {
                    withAnimation(.easeInOut(duration: 1.2).repeatForever()) { pulse = true }
                }
            Text("مباشر").font(.caption2.bold()).foregroundColor(.breakingRed)
        }
    }
}

struct FeaturedGridView: View {
    let items: [Article]
    private let columns = [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)]

    var body: some View {
        LazyVGrid(columns: columns, spacing: 10) {
            ForEach(items) { article in
                NavigationLink(value: article.id) {
                    FeaturedTile(article: article)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 16)
    }
}

struct FeaturedTile: View {
    let article: Article
    var body: some View {
        VStack(alignment: .trailing, spacing: 6) {
            RemoteImage(url: article.imageURL)
                .aspectRatio(4/3, contentMode: .fill)
                .clipped()
                .clipShape(RoundedRectangle(cornerRadius: 10))
            if let cat = article.category.name {
                Text(cat)
                    .font(.caption2.bold())
                    .padding(.horizontal, 6).padding(.vertical, 2)
                    .background(Color.category(article.category.slug).opacity(0.15))
                    .foregroundColor(Color.category(article.category.slug))
                    .clipShape(Capsule())
            }
            Text(article.title)
                .font(.system(size: 13, weight: .bold))
                .lineLimit(3)
                .multilineTextAlignment(.trailing)
                .frame(maxWidth: .infinity, alignment: .trailing)
            Text(RelativeTime.arabic(from: article.publishedAt))
                .font(.caption2)
                .foregroundColor(.secondary)
        }
    }
}

struct PalestineRailView: View {
    let rail: PalestineRail
    var body: some View {
        VStack(spacing: 10) {
            if let hero = rail.hero {
                NavigationLink(value: hero.id) {
                    HStack(alignment: .top, spacing: 10) {
                        RemoteImage(url: hero.imageURL)
                            .frame(width: 140, height: 110)
                            .clipped()
                            .clipShape(RoundedRectangle(cornerRadius: 10))
                        VStack(alignment: .trailing, spacing: 6) {
                            Label("فلسطين", systemImage: "flag.fill")
                                .font(.caption2.bold())
                                .padding(.horizontal, 6).padding(.vertical, 2)
                                .background(Color.breakingRed.opacity(0.15))
                                .foregroundColor(.breakingRed)
                                .clipShape(Capsule())
                            Text(hero.title)
                                .font(.system(size: 15, weight: .bold))
                                .lineLimit(3)
                                .multilineTextAlignment(.trailing)
                            Text(RelativeTime.arabic(from: hero.publishedAt))
                                .font(.caption2).foregroundColor(.secondary)
                        }
                        .frame(maxWidth: .infinity, alignment: .trailing)
                    }
                    .padding(10)
                    .background(RoundedRectangle(cornerRadius: 12).fill(Color.secondary.opacity(0.05)))
                }
                .buttonStyle(.plain)
            }

            ForEach(rail.items.prefix(3)) { a in
                NavigationLink(value: a.id) {
                    CompactArticleRow(article: a)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 16)
    }
}

struct CompactArticleRow: View {
    let article: Article
    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            RemoteImage(url: article.imageURL)
                .frame(width: 80, height: 80)
                .clipped()
                .clipShape(RoundedRectangle(cornerRadius: 8))
            VStack(alignment: .trailing, spacing: 4) {
                Text(article.title)
                    .font(.system(size: 14, weight: .semibold))
                    .lineLimit(3)
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: .infinity, alignment: .trailing)
                HStack {
                    Text(article.source.name ?? "")
                        .font(.caption2)
                        .foregroundColor(.secondary)
                    Spacer()
                    Text(RelativeTime.arabic(from: article.publishedAt))
                        .font(.caption2).foregroundColor(.secondary)
                }
            }
        }
    }
}

struct EvolvingStoriesScrollView: View {
    let items: [EvolvingStorySummary]
    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 10) {
                ForEach(items) { story in
                    NavigationLink(value: story.slug) {
                        EvolvingStoryCard(story: story)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 16)
        }
    }
}

struct EvolvingStoryCard: View {
    let story: EvolvingStorySummary
    var body: some View {
        ZStack(alignment: .bottomTrailing) {
            RemoteImage(url: story.coverImage)
                .frame(width: 220, height: 140)
                .clipped()
            LinearGradient(colors: [.clear, .black.opacity(0.8)], startPoint: .top, endPoint: .bottom)
            VStack(alignment: .trailing, spacing: 4) {
                HStack(spacing: 4) {
                    Text(story.icon ?? "📚")
                    Text("\(story.articleCount) مقال")
                        .font(.caption2)
                        .foregroundColor(.white.opacity(0.9))
                }
                Text(story.name)
                    .font(.system(size: 15, weight: .bold))
                    .foregroundColor(.white)
                    .multilineTextAlignment(.trailing)
                    .lineLimit(2)
            }
            .padding(10)
        }
        .frame(width: 220, height: 140)
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

struct CategoryRailSection: View {
    let rail: CategoryRail
    var body: some View {
        VStack(alignment: .trailing, spacing: 10) {
            SectionHeader(title: rail.name, icon: "tag.fill")
            LazyVGrid(columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)], spacing: 10) {
                ForEach(rail.items) { a in
                    NavigationLink(value: a.id) {
                        FeaturedTile(article: a)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 16)
        }
    }
}

struct MostReadListView: View {
    let items: [Article]
    var body: some View {
        VStack(spacing: 8) {
            ForEach(Array(items.enumerated()), id: \.element.id) { idx, a in
                NavigationLink(value: a.id) {
                    HStack(alignment: .top, spacing: 10) {
                        Text("\(idx + 1)")
                            .font(.system(size: 28, weight: .heavy))
                            .foregroundColor(.brand.opacity(0.7))
                            .frame(width: 42)
                        RemoteImage(url: a.imageURL)
                            .frame(width: 72, height: 72)
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                        VStack(alignment: .trailing, spacing: 4) {
                            Text(a.title)
                                .font(.system(size: 13, weight: .semibold))
                                .lineLimit(3)
                                .multilineTextAlignment(.trailing)
                                .frame(maxWidth: .infinity, alignment: .trailing)
                            HStack(spacing: 6) {
                                Image(systemName: "eye.fill").font(.caption2)
                                Text("\(a.viewCount)").font(.caption2)
                            }.foregroundColor(.secondary)
                        }
                    }
                    .padding(10)
                    .background(RoundedRectangle(cornerRadius: 12).fill(Color(.systemBackground))
                        .shadow(color: .black.opacity(0.03), radius: 4, y: 2))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 16)
    }
}

struct NewsletterBandView: View {
    @State private var email = ""
    @State private var submitted = false
    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: "envelope.badge.fill")
                .font(.system(size: 28))
                .foregroundColor(.white)
            Text("اشترك في النشرة البريدية")
                .font(.system(size: 16, weight: .bold))
                .foregroundColor(.white)
            Text("استلم أهم الأخبار كل صباح على بريدك")
                .font(.caption)
                .foregroundColor(.white.opacity(0.9))
                .multilineTextAlignment(.center)
            HStack(spacing: 6) {
                TextField("بريدك الإلكتروني", text: $email)
                    .keyboardType(.emailAddress)
                    .autocapitalization(.none)
                    .padding(10)
                    .background(Color.white)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                Button(submitted ? "✓" : "اشترك") {
                    submitted = true
                }
                .font(.system(size: 13, weight: .bold))
                .padding(.horizontal, 14).padding(.vertical, 10)
                .background(Color.white)
                .foregroundColor(.brand)
                .clipShape(RoundedRectangle(cornerRadius: 8))
                .disabled(email.isEmpty)
            }
        }
        .padding(16)
        .background(
            LinearGradient(colors: [Color.brand, Color.brand.opacity(0.75)],
                           startPoint: .topLeading, endPoint: .bottomTrailing)
        )
        .clipShape(RoundedRectangle(cornerRadius: 14))
        .padding(.horizontal, 16)
    }
}

struct FooterView: View {
    var body: some View {
        VStack(spacing: 10) {
            Text("نيوز فيد © \(Calendar.current.component(.year, from: Date()))")
                .font(.caption).foregroundColor(.secondary)
            Text("مجمع المصادر الإخبارية")
                .font(.caption2).foregroundColor(.secondary)
            HStack(spacing: 16) {
                Link("عن الموقع",
                     destination: URL(string: "\(APIConfig.webBaseURL)/about.php")!)
                Link("الخصوصية",
                     destination: URL(string: "\(APIConfig.webBaseURL)/privacy.php")!)
                Link("الدعم",
                     destination: URL(string: "\(APIConfig.webBaseURL)/contact.php")!)
            }
            .font(.caption2)
            .foregroundColor(.brand)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 16)
        .background(Color.secondary.opacity(0.06))
    }
}
