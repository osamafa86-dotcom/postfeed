import SwiftUI

/// Full side menu mirroring the website's hamburger. Shown as a sheet on iOS.
struct DrawerMenuView: View {
    let onSelect: (HeaderSection) -> Void

    @EnvironmentObject private var session: SessionStore
    @EnvironmentObject private var theme: ThemeStore
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    if let user = session.user {
                        HStack(spacing: 12) {
                            ZStack {
                                Circle().fill(Color.brand).frame(width: 44, height: 44)
                                Text(user.avatarLetter ?? "ن")
                                    .foregroundColor(.white).font(.system(size: 18, weight: .bold))
                            }
                            VStack(alignment: .leading) {
                                Text(user.name).font(.system(size: 16, weight: .bold))
                                Text(user.email).font(.caption).foregroundColor(.secondary)
                            }
                            Spacer()
                            NavigationLink {
                                ProfileView()
                            } label: {
                                Image(systemName: "chevron.backward")
                                    .foregroundColor(.secondary)
                            }
                        }
                    } else {
                        NavigationLink(destination: LoginView()) {
                            HStack(spacing: 12) {
                                Image(systemName: "person.crop.circle.fill")
                                    .font(.system(size: 40))
                                    .foregroundColor(.brand)
                                VStack(alignment: .leading) {
                                    Text("تسجيل الدخول").font(.system(size: 16, weight: .bold))
                                    Text("لمتابعة فئاتك والأخبار المفضّلة")
                                        .font(.caption).foregroundColor(.secondary)
                                }
                            }
                        }
                    }
                }

                Section("التصفح") {
                    drawerRow(.home)
                    drawerRow(.breaking)
                    drawerRow(.latest)
                    drawerRow(.palestine)
                }

                Section("الأقسام") {
                    drawerRow(.political)
                    drawerRow(.economy)
                    drawerRow(.sports)
                    drawerRow(.arts)
                    drawerRow(.media)
                    drawerRow(.reports)
                }

                Section("قصص ومحتوى خاص") {
                    drawerRow(.evolving)
                    drawerRow(.timelines)
                    drawerRow(.weekly)
                    drawerRow(.podcast)
                    drawerRow(.reels)
                    drawerRow(.telegram)
                    drawerRow(.map)
                }

                if session.isLoggedIn {
                    Section("حسابي") {
                        NavigationLink { BookmarksView() } label: {
                            Label("المحفوظات", systemImage: "bookmark.fill")
                        }
                        NavigationLink { NotificationsView() } label: {
                            Label("الإشعارات", systemImage: "bell.fill")
                        }
                        NavigationLink { CategoryFollowView() } label: {
                            Label("متابعاتي", systemImage: "heart.fill")
                        }
                    }
                }

                Section("الإعدادات") {
                    Picker("المظهر", selection: $theme.mode) {
                        ForEach(ThemeStore.Mode.allCases) { m in
                            Text(m.label).tag(m)
                        }
                    }
                    Link(destination: URL(string: "\(APIConfig.webBaseURL)/about.php")!) {
                        Label("عن نيوز فيد", systemImage: "info.circle")
                    }
                    Link(destination: URL(string: "\(APIConfig.webBaseURL)/privacy.php")!) {
                        Label("سياسة الخصوصية", systemImage: "hand.raised")
                    }
                    Link(destination: URL(string: "\(APIConfig.webBaseURL)/contact.php")!) {
                        Label("الدعم", systemImage: "envelope")
                    }
                }

                if session.isLoggedIn {
                    Section {
                        Button {
                            Task {
                                await session.signOut()
                                dismiss()
                            }
                        } label: {
                            Label("تسجيل الخروج", systemImage: "arrow.backward.square")
                                .foregroundColor(.red)
                        }
                    }
                }
            }
            .listStyle(.insetGrouped)
            .navigationTitle("القائمة")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("إغلاق") { dismiss() }
                }
            }
        }
    }

    private func drawerRow(_ section: HeaderSection) -> some View {
        Button { onSelect(section) } label: {
            HStack {
                Label(section.title, systemImage: section.icon)
                    .foregroundColor(.primary)
                Spacer()
                Image(systemName: "chevron.backward")
                    .foregroundColor(.secondary)
                    .font(.caption)
            }
        }
    }
}
