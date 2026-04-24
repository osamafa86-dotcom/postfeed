import SwiftUI

struct CategoryFollowView: View {
    @State private var categories: [CategoryItem] = []
    @State private var sources: [SourceItem] = []
    @State private var isLoading = false
    @State private var mode: Mode = .categories

    enum Mode: String, CaseIterable, Identifiable {
        case categories = "الفئات", sources = "المصادر"
        var id: String { rawValue }
    }

    var body: some View {
        VStack(spacing: 0) {
            Picker("", selection: $mode) {
                ForEach(Mode.allCases) { m in Text(m.rawValue).tag(m) }
            }
            .pickerStyle(.segmented)
            .padding()

            if isLoading {
                ProgressView().padding(.top, 40)
                Spacer()
            } else {
                List {
                    if mode == .categories {
                        ForEach(Array(categories.enumerated()), id: \.element.id) { idx, c in
                            Toggle(isOn: Binding(
                                get: { categories[idx].following },
                                set: { new in
                                    categories[idx].following = new
                                    Task { await toggleCategory(id: c.id, to: new) }
                                }
                            )) {
                                HStack {
                                    Text(c.icon ?? "📰")
                                    Text(c.name)
                                }
                            }
                        }
                    } else {
                        ForEach(Array(sources.enumerated()), id: \.element.id) { idx, s in
                            Toggle(isOn: Binding(
                                get: { sources[idx].following },
                                set: { new in
                                    sources[idx].following = new
                                    Task { await toggleSource(id: s.id, to: new) }
                                }
                            )) {
                                HStack(spacing: 8) {
                                    ZStack {
                                        Circle().fill(Color(hex: s.logoBg) ?? Color.brand.opacity(0.15))
                                            .frame(width: 26, height: 26)
                                        Text(s.logoLetter ?? "ن")
                                            .font(.system(size: 13, weight: .bold))
                                            .foregroundColor(Color(hex: s.logoColor) ?? .brand)
                                    }
                                    Text(s.name)
                                }
                            }
                        }
                    }
                }
                .listStyle(.plain)
            }
        }
        .navigationTitle("متابعاتي")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
    }

    private func load() async {
        isLoading = true; defer { isLoading = false }
        async let c = Endpoints.categories()
        async let s = Endpoints.sources()
        do {
            let (cats, srcs) = try await (c, s)
            categories = cats.items
            sources = srcs.items
        } catch { }
    }

    private func toggleCategory(id: Int, to follow: Bool) async {
        _ = try? await Endpoints.follow(kind: .category, id: id,
                                        action: follow ? .follow : .unfollow)
    }

    private func toggleSource(id: Int, to follow: Bool) async {
        _ = try? await Endpoints.follow(kind: .source, id: id,
                                        action: follow ? .follow : .unfollow)
    }
}
