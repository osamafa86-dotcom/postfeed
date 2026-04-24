import SwiftUI

/// Top-level navigation modeled on the web header:
/// a horizontally-scrolling section bar + a drawer menu for the overflow.
/// There is no bottom tab bar — this mirrors feedsnews.net, which uses
/// a magazine-style header and a side drawer.
struct RootView: View {
    @EnvironmentObject private var session: SessionStore
    @State private var selectedSection: HeaderSection = .home
    @State private var showDrawer = false
    @State private var showNotifications = false
    @State private var showSearch = false

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                TopHeaderBar(
                    selected: $selectedSection,
                    onMenu: { showDrawer = true },
                    onNotifications: { showNotifications = true },
                    onSearch: { showSearch = true }
                )

                Group {
                    switch selectedSection {
                    case .breaking: CategoryFeedView(categorySlug: "breaking", title: "عاجل")
                    case .home:     HomeFeedView()
                    case .latest:   LatestFeedView()
                    case .palestine: PalestineFeedView()
                    case .weekly:   WeeklyView()
                    case .map:      ComingSoonView(title: "الخريطة", icon: "map.fill")
                    case .podcast:  PodcastView()
                    case .evolving: EvolvingStoriesListView()
                    case .timelines: ComingSoonView(title: "خطوط زمنية", icon: "clock.fill")
                    case .political: CategoryFeedView(categorySlug: "political", title: "سياسة")
                    case .economy:   CategoryFeedView(categorySlug: "economy", title: "اقتصاد")
                    case .sports:    CategoryFeedView(categorySlug: "sports", title: "رياضة")
                    case .arts:      CategoryFeedView(categorySlug: "arts", title: "فنون")
                    case .media:     CategoryFeedView(categorySlug: "media", title: "ميديا")
                    case .reports:   CategoryFeedView(categorySlug: "reports", title: "تقارير")
                    case .telegram:  TelegramView()
                    case .reels:     ReelsView()
                    }
                }
            }
            .navigationBarHidden(true)
            .navigationDestination(for: Int.self) { articleId in
                ArticleDetailView(articleId: articleId)
            }
            .navigationDestination(for: String.self) { storySlug in
                EvolvingStoryDetailView(slug: storySlug)
            }
        }
        .sheet(isPresented: $showDrawer) {
            DrawerMenuView(onSelect: { section in
                selectedSection = section
                showDrawer = false
            })
                .presentationDetents([.large])
        }
        .sheet(isPresented: $showNotifications) {
            NavigationStack { NotificationsView() }
        }
        .sheet(isPresented: $showSearch) {
            NavigationStack { SearchView() }
        }
    }
}

enum HeaderSection: Hashable, CaseIterable {
    case breaking, home, latest, palestine, weekly, map, podcast, evolving,
         timelines, political, economy, sports, arts, media, reports,
         telegram, reels

    var title: String {
        switch self {
        case .breaking:  return "عاجل"
        case .home:      return "الرئيسية"
        case .latest:    return "آخر الأخبار"
        case .palestine: return "فلسطين"
        case .weekly:    return "مراجعة الأسبوع"
        case .map:       return "الخريطة"
        case .podcast:   return "البودكاست"
        case .evolving:  return "قصص متطوّرة"
        case .timelines: return "خطوط زمنية"
        case .political: return "سياسة"
        case .economy:   return "اقتصاد"
        case .sports:    return "رياضة"
        case .arts:      return "فنون"
        case .media:     return "ميديا"
        case .reports:   return "تقارير"
        case .telegram:  return "تلغرام"
        case .reels:     return "ريلز"
        }
    }

    var icon: String {
        switch self {
        case .breaking:  return "bolt.fill"
        case .home:      return "house.fill"
        case .latest:    return "clock.fill"
        case .palestine: return "flag.fill"
        case .weekly:    return "calendar"
        case .map:       return "map.fill"
        case .podcast:   return "mic.fill"
        case .evolving:  return "sparkles"
        case .timelines: return "timeline.selection"
        case .political: return "building.columns.fill"
        case .economy:   return "chart.line.uptrend.xyaxis"
        case .sports:    return "sportscourt.fill"
        case .arts:      return "paintpalette.fill"
        case .media:     return "tv.fill"
        case .reports:   return "doc.text.fill"
        case .telegram:  return "paperplane.fill"
        case .reels:     return "play.rectangle.fill"
        }
    }
}
