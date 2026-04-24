import SwiftUI

extension Color {
    /// Primary brand teal matching the web theme_color (#1a5c5c).
    static let brand = Color(red: 26/255, green: 92/255, blue: 92/255)
    static let brandDark = Color(red: 15/255, green: 23/255, blue: 42/255)

    static let breakingRed = Color(red: 0.85, green: 0.2, blue: 0.2)

    /// Category color mapping (mirrors /assets/css category classes).
    static func category(_ slug: String?) -> Color {
        switch slug {
        case "breaking":  return .breakingRed
        case "political": return Color(red: 90/255, green: 133/255, blue: 176/255)
        case "economy":   return Color(red: 133/255, green: 193/255, blue: 163/255)
        case "sports":    return Color(red: 107/255, green: 159/255, blue: 212/255)
        case "arts":      return Color(red: 201/255, green: 171/255, blue: 110/255)
        case "media":     return Color(red: 160/255, green: 140/255, blue: 200/255)
        case "reports":   return Color(red: 85/255, green: 150/255, blue: 120/255)
        case "tech":      return Color(red: 90/255, green: 133/255, blue: 176/255)
        case "health":    return Color(red: 143/255, green: 64/255, blue: 64/255)
        default:          return .brand
        }
    }

    /// Parse `#RRGGBB` / `#RGB` strings returned by the API.
    init?(hex: String?) {
        guard let raw = hex?.trimmingCharacters(in: .whitespacesAndNewlines) else { return nil }
        var s = raw
        if s.hasPrefix("#") { s.removeFirst() }
        if s.count == 3 { s = s.map { "\($0)\($0)" }.joined() }
        guard s.count == 6, let v = UInt32(s, radix: 16) else { return nil }
        self = Color(
            red:   Double((v >> 16) & 0xFF) / 255.0,
            green: Double((v >> 8)  & 0xFF) / 255.0,
            blue:  Double( v        & 0xFF) / 255.0
        )
    }
}
