import SwiftUI

struct RootView: View {
    @EnvironmentObject private var session: SessionStore
    @State private var selectedTab: AppTab = .feed

    var body: some View {
        TabView(selection: $selectedTab) {
            NavigationStack { FeedView() }
                .tabItem { Label("الرئيسية", systemImage: "newspaper.fill") }
                .tag(AppTab.feed)

            NavigationStack { TrendingView() }
                .tabItem { Label("الأكثر قراءة", systemImage: "flame.fill") }
                .tag(AppTab.trending)

            NavigationStack { SearchView() }
                .tabItem { Label("البحث", systemImage: "magnifyingglass") }
                .tag(AppTab.search)

            NavigationStack { AskView() }
                .tabItem { Label("اسأل", systemImage: "sparkles") }
                .tag(AppTab.ask)

            NavigationStack { ProfileView() }
                .tabItem { Label("حسابي", systemImage: "person.crop.circle.fill") }
                .tag(AppTab.profile)
        }
        .tint(Color.brand)
    }
}

enum AppTab: Hashable {
    case feed, trending, search, ask, profile
}
