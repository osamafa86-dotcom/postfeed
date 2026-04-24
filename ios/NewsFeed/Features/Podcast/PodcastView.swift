import SwiftUI
import AVFoundation

@MainActor
final class PodcastViewModel: ObservableObject {
    @Published var episode: PodcastEpisode?
    @Published var archive: [PodcastEpisodeSummary] = []
    @Published var isLoading = false
    @Published var errorMessage: String?

    func load() async {
        isLoading = true; defer { isLoading = false }
        async let latestTask = Endpoints.latestPodcast()
        async let archiveTask = Endpoints.podcastArchive(limit: 30)
        do {
            let (latest, arch) = try await (latestTask, archiveTask)
            self.episode = latest.episode
            self.archive = arch.items
        } catch {
            self.errorMessage = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }
}

struct PodcastView: View {
    @StateObject private var vm = PodcastViewModel()
    @StateObject private var player = AudioPlayer()

    var body: some View {
        ScrollView {
            VStack(alignment: .trailing, spacing: 16) {
                if vm.isLoading && vm.episode == nil {
                    ProgressView().padding(.top, 60)
                } else if let ep = vm.episode {
                    heroCard(for: ep)
                    if !ep.chapters.isEmpty {
                        chaptersView(ep.chapters)
                    }
                    if let script = ep.script, !script.isEmpty {
                        transcript(script)
                    }
                    if !vm.archive.isEmpty {
                        archiveView()
                    }
                } else {
                    EmptyStateView(icon: "mic.slash",
                                   title: "لا يوجد بودكاست متاح حالياً")
                        .padding(.top, 60)
                }
            }
            .padding(16)
        }
        .refreshable { await vm.load() }
        .task { if vm.episode == nil { await vm.load() } }
    }

    private func heroCard(for ep: PodcastEpisode) -> some View {
        VStack(alignment: .trailing, spacing: 10) {
            ZStack(alignment: .center) {
                LinearGradient(colors: [.brand, .brand.opacity(0.7)],
                               startPoint: .topLeading, endPoint: .bottomTrailing)
                    .frame(height: 160)
                    .clipShape(RoundedRectangle(cornerRadius: 14))

                Image(systemName: "waveform.circle.fill")
                    .font(.system(size: 72))
                    .foregroundColor(.white.opacity(0.9))
            }

            Text(ep.date)
                .font(.caption.bold())
                .foregroundColor(.secondary)

            Text(ep.title)
                .font(.system(size: 20, weight: .heavy))
                .multilineTextAlignment(.trailing)
                .frame(maxWidth: .infinity, alignment: .trailing)

            if let sub = ep.subtitle {
                Text(sub)
                    .font(.system(size: 14))
                    .foregroundColor(.secondary)
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: .infinity, alignment: .trailing)
            }

            if let audioStr = ep.audioURL, let url = URL(string: audioStr) {
                PodcastPlayerView(url: url, player: player)
            } else {
                Text("الحلقة الصوتية قيد الإعداد")
                    .font(.caption)
                    .foregroundColor(.secondary)
                    .padding(.vertical, 8)
            }
        }
    }

    private func chaptersView(_ chapters: [PodcastChapter]) -> some View {
        VStack(alignment: .trailing, spacing: 8) {
            Text("الفصول").font(.headline)
            ForEach(Array(chapters.enumerated()), id: \.offset) { idx, ch in
                HStack {
                    Text(idx + 1, format: .number)
                        .font(.caption.bold())
                        .frame(width: 24, height: 24)
                        .background(Color.brand.opacity(0.15))
                        .foregroundColor(.brand)
                        .clipShape(Circle())
                    Text(ch.title).font(.system(size: 14))
                    Spacer()
                    if let t = ch.time {
                        Text(formatTime(t))
                            .font(.caption).foregroundColor(.secondary)
                    }
                }
                .padding(10)
                .background(Color.secondary.opacity(0.06))
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }
        }
    }

    private func transcript(_ text: String) -> some View {
        VStack(alignment: .trailing, spacing: 8) {
            Text("النص").font(.headline)
            Text(text)
                .font(.system(size: 14))
                .multilineTextAlignment(.trailing)
                .frame(maxWidth: .infinity, alignment: .trailing)
        }
    }

    private func archiveView() -> some View {
        VStack(alignment: .trailing, spacing: 8) {
            Text("الحلقات السابقة").font(.headline)
            ForEach(vm.archive) { ep in
                HStack(spacing: 10) {
                    Image(systemName: "play.circle.fill").foregroundColor(.brand)
                    VStack(alignment: .trailing, spacing: 2) {
                        Text(ep.title).font(.system(size: 13, weight: .semibold))
                            .lineLimit(2)
                        Text(ep.date).font(.caption2).foregroundColor(.secondary)
                    }
                    Spacer()
                    Text(formatDuration(ep.durationSeconds))
                        .font(.caption2)
                        .foregroundColor(.secondary)
                }
                .padding(10)
                .background(Color.secondary.opacity(0.06))
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }
        }
    }

    private func formatTime(_ seconds: Int) -> String {
        let m = seconds / 60, s = seconds % 60
        return String(format: "%d:%02d", m, s)
    }
    private func formatDuration(_ seconds: Int) -> String {
        if seconds < 60 { return "\(seconds)ث" }
        let m = seconds / 60
        return "\(m) دقيقة"
    }
}

// MARK: - Audio player

final class AudioPlayer: ObservableObject {
    private var player: AVPlayer?
    @Published var isPlaying = false

    func play(url: URL) {
        if player?.currentItem?.asset as? AVURLAsset == nil || (player?.currentItem?.asset as? AVURLAsset)?.url != url {
            player = AVPlayer(url: url)
        }
        player?.play()
        isPlaying = true
    }

    func pause() {
        player?.pause()
        isPlaying = false
    }

    func toggle(url: URL) {
        if isPlaying { pause() } else { play(url: url) }
    }
}

struct PodcastPlayerView: View {
    let url: URL
    @ObservedObject var player: AudioPlayer

    var body: some View {
        HStack {
            Button {
                player.toggle(url: url)
            } label: {
                Image(systemName: player.isPlaying ? "pause.circle.fill" : "play.circle.fill")
                    .font(.system(size: 44))
                    .foregroundColor(.brand)
            }
            Spacer()
            Image(systemName: "waveform")
                .font(.system(size: 28))
                .foregroundColor(.brand.opacity(0.7))
        }
        .padding(14)
        .background(Color.secondary.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}
