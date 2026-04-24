import SwiftUI

struct ProfileView: View {
    @EnvironmentObject private var session: SessionStore
    @EnvironmentObject private var theme: ThemeStore
    @State private var showDelete = false
    @State private var deletePassword = ""
    @State private var isDeleting = false

    var body: some View {
        Group {
            if session.isLoggedIn, let user = session.user {
                loggedInContent(user: user)
            } else {
                loggedOutContent
            }
        }
        .navigationTitle("حسابي")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await session.refreshProfile() }
    }

    private var loggedOutContent: some View {
        VStack(spacing: 20) {
            Image(systemName: "person.crop.circle")
                .font(.system(size: 72)).foregroundColor(.secondary)
            Text("مرحباً بك في نيوز فيد")
                .font(.title2.bold())
            Text("سجّل الدخول لحفظ المقالات، ومتابعة فئاتك، واستلام الإشعارات.")
                .multilineTextAlignment(.center)
                .foregroundColor(.secondary)
                .padding(.horizontal, 32)
            NavigationLink(destination: LoginView()) {
                Text("تسجيل الدخول")
                    .font(.system(size: 16, weight: .semibold))
                    .frame(maxWidth: .infinity).padding(.vertical, 14)
                    .background(Color.brand).foregroundColor(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            NavigationLink(destination: RegisterView()) {
                Text("إنشاء حساب جديد")
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundColor(.brand)
            }
            Spacer()
            links
        }
        .padding(24)
    }

    @ViewBuilder
    private func loggedInContent(user: User) -> some View {
        List {
            Section {
                HStack(spacing: 14) {
                    ZStack {
                        Circle().fill(Color.brand).frame(width: 64, height: 64)
                        Text(user.avatarLetter ?? "ن")
                            .font(.system(size: 26, weight: .bold))
                            .foregroundColor(.white)
                    }
                    VStack(alignment: .leading, spacing: 4) {
                        Text(user.name).font(.system(size: 18, weight: .bold))
                        Text(user.email).font(.subheadline).foregroundColor(.secondary)
                        if user.readingStreak > 0 {
                            Label("\(user.readingStreak) يوم متتالي", systemImage: "flame.fill")
                                .font(.caption).foregroundColor(.orange)
                        }
                    }
                }
                .padding(.vertical, 6)
            }

            if let stats = session.stats {
                Section("نشاطي") {
                    statRow("المحفوظات", value: "\(stats.bookmarks)", icon: "bookmark.fill", color: .brand)
                    statRow("فئات أتابعها", value: "\(stats.followedCategories)", icon: "tag.fill", color: .blue)
                    statRow("مصادر أتابعها", value: "\(stats.followedSources)", icon: "newspaper.fill", color: .purple)
                    if stats.unreadNotifications > 0 {
                        statRow("إشعارات غير مقروءة", value: "\(stats.unreadNotifications)",
                                icon: "bell.fill", color: .red)
                    }
                }
            }

            Section("التصفّح") {
                NavigationLink { BookmarksView() } label: {
                    Label("المحفوظات", systemImage: "bookmark.fill")
                }
                NavigationLink { NotificationsView() } label: {
                    Label("الإشعارات", systemImage: "bell.fill")
                }
                NavigationLink { CategoryFollowView() } label: {
                    Label("فئات ومصادر", systemImage: "line.3.horizontal.decrease.circle.fill")
                }
            }

            Section("الإعدادات") {
                Picker("المظهر", selection: $theme.mode) {
                    ForEach(ThemeStore.Mode.allCases) { m in
                        Text(m.label).tag(m)
                    }
                }
                NavigationLink { NotificationSettingsView() } label: {
                    Label("إعدادات الإشعارات", systemImage: "bell.badge")
                }
            }

            Section("عن التطبيق") {
                Link(destination: URL(string: "\(APIConfig.webBaseURL)/about.php")!) {
                    Label("عن نيوز فيد", systemImage: "info.circle")
                }
                Link(destination: URL(string: "\(APIConfig.webBaseURL)/privacy.php")!) {
                    Label("سياسة الخصوصية", systemImage: "hand.raised")
                }
                Link(destination: URL(string: "\(APIConfig.webBaseURL)/contact.php")!) {
                    Label("الدعم والتواصل", systemImage: "envelope")
                }
                HStack {
                    Text("الإصدار")
                    Spacer()
                    Text(APIConfig.appVersion).foregroundColor(.secondary)
                }
            }

            Section {
                Button {
                    Task { await session.signOut() }
                } label: {
                    Label("تسجيل الخروج", systemImage: "arrow.backward.square")
                        .foregroundColor(.red)
                }
                Button(role: .destructive) {
                    showDelete = true
                } label: {
                    Label("حذف الحساب نهائياً", systemImage: "trash")
                }
            }
        }
        .listStyle(.insetGrouped)
        .alert("حذف الحساب", isPresented: $showDelete) {
            SecureField("كلمة المرور", text: $deletePassword)
            Button("إلغاء", role: .cancel) { deletePassword = "" }
            Button("حذف نهائي", role: .destructive) {
                Task { await confirmDelete() }
            }
        } message: {
            Text("سيتم حذف حسابك وجميع بياناتك بشكل نهائي ولا يمكن التراجع. أدخل كلمة المرور للتأكيد.")
        }
    }

    private func statRow(_ title: String, value: String, icon: String, color: Color) -> some View {
        HStack {
            Image(systemName: icon).foregroundColor(color).frame(width: 24)
            Text(title)
            Spacer()
            Text(value).font(.system(size: 15, weight: .semibold)).foregroundColor(.secondary)
        }
    }

    private var links: some View {
        HStack(spacing: 16) {
            Link("الشروط",
                 destination: URL(string: "\(APIConfig.webBaseURL)/about.php")!)
            Link("الخصوصية",
                 destination: URL(string: "\(APIConfig.webBaseURL)/privacy.php")!)
            Link("الدعم",
                 destination: URL(string: "\(APIConfig.webBaseURL)/contact.php")!)
        }
        .font(.caption)
        .foregroundColor(.secondary)
    }

    private func confirmDelete() async {
        isDeleting = true
        defer { isDeleting = false; deletePassword = "" }
        do {
            try await session.deleteAccount(password: deletePassword)
        } catch {
            // Surface failure via a toast-style alert in a future iteration;
            // for now keep the session intact on error.
            #if DEBUG
            print("delete failed:", error)
            #endif
        }
    }
}
