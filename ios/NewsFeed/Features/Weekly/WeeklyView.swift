import SwiftUI

@MainActor
final class WeeklyViewModel: ObservableObject {
    @Published var rewind: WeeklyRewind?
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load() async {
        isLoading = true; defer { isLoading = false }
        do {
            rewind = try await Endpoints.latestWeekly().rewind
        } catch {
            errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct WeeklyView: View {
    @StateObject private var vm = WeeklyViewModel()

    var body: some View {
        ScrollView {
            VStack(alignment: .trailing, spacing: 14) {
                if vm.isLoading && vm.rewind == nil {
                    ProgressView().padding(.top, 60)
                } else if let r = vm.rewind {
                    if let cover = r.coverImageURL {
                        RemoteImage(url: cover)
                            .frame(height: 220)
                            .clipped()
                    }
                    VStack(alignment: .trailing, spacing: 8) {
                        Text(r.yearWeek)
                            .font(.caption.bold())
                            .foregroundColor(.secondary)
                        if let t = r.title {
                            Text(t).font(.system(size: 24, weight: .heavy))
                                .multilineTextAlignment(.trailing)
                        }
                        if let s = r.subtitle {
                            Text(s).font(.system(size: 16))
                                .foregroundColor(.secondary)
                                .multilineTextAlignment(.trailing)
                        }
                        if let intro = r.introText {
                            Text(intro)
                                .font(.system(size: 14))
                                .multilineTextAlignment(.trailing)
                                .padding(.top, 6)
                        }
                    }
                    .padding(16)
                    .frame(maxWidth: .infinity, alignment: .trailing)
                } else {
                    EmptyStateView(icon: "calendar", title: "لا تتوفر مراجعة هذا الأسبوع بعد")
                        .padding(.top, 60)
                }
            }
        }
        .refreshable { await vm.load() }
        .task { if vm.rewind == nil { await vm.load() } }
    }
}
