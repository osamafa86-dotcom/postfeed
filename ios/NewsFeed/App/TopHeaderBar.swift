import SwiftUI

/// Sticky top bar with brand + actions on the first row and a horizontally
/// scrollable section picker on the second row, mirroring the website header.
struct TopHeaderBar: View {
    @Binding var selected: HeaderSection
    let onMenu: () -> Void
    let onNotifications: () -> Void
    let onSearch: () -> Void

    private let orderedSections: [HeaderSection] = [
        .breaking, .home, .latest, .palestine, .weekly, .map, .podcast,
        .evolving, .timelines, .political, .economy, .sports, .arts,
        .media, .reports, .telegram, .reels,
    ]

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 12) {
                Button(action: onMenu) {
                    Image(systemName: "line.3.horizontal")
                        .font(.system(size: 20, weight: .semibold))
                        .frame(width: 36, height: 36)
                        .foregroundColor(.primary)
                }

                HStack(spacing: 6) {
                    Image(systemName: "newspaper.fill")
                        .foregroundColor(.brand)
                    VStack(alignment: .leading, spacing: 0) {
                        Text("نيوز فيد")
                            .font(.system(size: 18, weight: .heavy))
                        Text("مجمع المصادر الإخبارية")
                            .font(.system(size: 9))
                            .foregroundColor(.secondary)
                    }
                }

                Spacer(minLength: 0)

                Button(action: onSearch) {
                    Image(systemName: "magnifyingglass")
                        .font(.system(size: 17, weight: .semibold))
                        .frame(width: 36, height: 36)
                        .foregroundColor(.primary)
                }
                Button(action: onNotifications) {
                    Image(systemName: "bell.fill")
                        .font(.system(size: 17, weight: .semibold))
                        .frame(width: 36, height: 36)
                        .foregroundColor(.primary)
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(Color(.systemBackground))

            ScrollViewReader { proxy in
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 6) {
                        ForEach(orderedSections, id: \.self) { section in
                            chip(section)
                                .id(section)
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                }
                .onChange(of: selected) { newValue in
                    withAnimation { proxy.scrollTo(newValue, anchor: .center) }
                }
            }
            .background(
                Color(.systemBackground)
                    .shadow(color: .black.opacity(0.05), radius: 3, y: 2)
            )
        }
    }

    @ViewBuilder
    private func chip(_ section: HeaderSection) -> some View {
        let isSelected = section == selected
        Button {
            selected = section
        } label: {
            HStack(spacing: 4) {
                if section == .breaking {
                    Circle().fill(Color.breakingRed).frame(width: 6, height: 6)
                }
                Text(section.title)
                    .font(.system(size: 13, weight: isSelected ? .bold : .semibold))
            }
            .foregroundColor(isSelected ? .white : .primary)
            .padding(.horizontal, 12)
            .padding(.vertical, 7)
            .background(
                RoundedRectangle(cornerRadius: 16)
                    .fill(isSelected ? (section == .breaking ? Color.breakingRed : Color.brand)
                                     : Color.secondary.opacity(0.1))
            )
        }
    }
}
