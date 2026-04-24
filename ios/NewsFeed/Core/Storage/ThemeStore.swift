import SwiftUI

@MainActor
final class ThemeStore: ObservableObject {
    enum Mode: String, CaseIterable, Identifiable {
        case auto, light, dark
        var id: String { rawValue }
        var label: String {
            switch self {
            case .auto:  return "تلقائي"
            case .light: return "فاتح"
            case .dark:  return "داكن"
            }
        }
    }

    @Published var mode: Mode {
        didSet { UserDefaults.standard.set(mode.rawValue, forKey: "nf.theme") }
    }

    init() {
        let saved = UserDefaults.standard.string(forKey: "nf.theme") ?? "auto"
        self.mode = Mode(rawValue: saved) ?? .auto
    }

    var colorScheme: ColorScheme? {
        switch mode {
        case .auto:  return nil
        case .light: return .light
        case .dark:  return .dark
        }
    }
}
