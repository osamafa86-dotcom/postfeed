class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    this.username,
    this.email,
    this.avatarLetter,
    this.bio = '',
    this.theme = 'auto',
    this.role = 'reader',
    this.plan = 'free',
    this.readingStreak = 0,
    this.notifyBreaking = true,
    this.notifyFollowed = true,
    this.notifyDigest = true,
  });

  final int id;
  final String name;
  final String? username;
  final String? email;
  final String? avatarLetter;
  final String bio;
  final String theme;
  final String role;
  final String plan;
  final int readingStreak;
  final bool notifyBreaking;
  final bool notifyFollowed;
  final bool notifyDigest;

  factory AppUser.fromJson(Map<String, dynamic> j) {
    final notify = (j['notify'] as Map?)?.cast<String, dynamic>() ?? {};
    return AppUser(
      id: (j['id'] as num).toInt(),
      name: j['name'] as String,
      username: j['username'] as String?,
      email: j['email'] as String?,
      avatarLetter: j['avatar_letter'] as String?,
      bio: (j['bio'] as String?) ?? '',
      theme: (j['theme'] as String?) ?? 'auto',
      role: (j['role'] as String?) ?? 'reader',
      plan: (j['plan'] as String?) ?? 'free',
      readingStreak: (j['reading_streak'] as num?)?.toInt() ?? 0,
      notifyBreaking: (notify['breaking'] ?? 1) == 1,
      notifyFollowed: (notify['followed'] ?? 1) == 1,
      notifyDigest:   (notify['digest']   ?? 1) == 1,
    );
  }
}
