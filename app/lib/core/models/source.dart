class Source {
  const Source({
    required this.id,
    required this.name,
    required this.slug,
    this.logoLetter,
    this.logoColor,
    this.logoBg,
    this.url,
    this.articlesToday = 0,
  });

  final int id;
  final String name;
  final String slug;
  final String? logoLetter;
  final String? logoColor;
  final String? logoBg;
  final String? url;
  final int articlesToday;

  factory Source.fromJson(Map<String, dynamic> j) => Source(
        id: (j['id'] as num).toInt(),
        name: j['name'] as String,
        slug: j['slug'] as String,
        logoLetter: j['logo_letter'] as String?,
        logoColor: j['logo_color'] as String?,
        logoBg: j['logo_bg'] as String?,
        url: j['url'] as String?,
        articlesToday: (j['articles_today'] as num?)?.toInt() ?? 0,
      );
}
