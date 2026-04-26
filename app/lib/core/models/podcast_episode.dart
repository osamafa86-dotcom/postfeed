class PodcastChapter {
  const PodcastChapter({required this.title, required this.startSec, this.imageUrl});
  final String title;
  final int startSec;
  final String? imageUrl;

  factory PodcastChapter.fromJson(Map<String, dynamic> j) => PodcastChapter(
        title: j['title'] as String? ?? '',
        startSec: (j['start_sec'] as num?)?.toInt() ?? (j['start'] as num?)?.toInt() ?? 0,
        imageUrl: j['image_url'] as String?,
      );
}

class PodcastEpisode {
  const PodcastEpisode({
    required this.id,
    required this.date,
    required this.title,
    this.subtitle = '',
    this.intro = '',
    this.scriptText = '',
    this.chapters = const [],
    this.audioUrl,
    this.audioBytes = 0,
    this.durationSeconds = 0,
    this.ttsProvider = '',
    this.ttsVoice = '',
    this.playCount = 0,
    this.publishedAt,
  });

  final int id;
  final String date;
  final String title;
  final String subtitle;
  final String intro;
  final String scriptText;
  final List<PodcastChapter> chapters;
  final String? audioUrl;
  final int audioBytes;
  final int durationSeconds;
  final String ttsProvider;
  final String ttsVoice;
  final int playCount;
  final DateTime? publishedAt;

  factory PodcastEpisode.fromJson(Map<String, dynamic> j) => PodcastEpisode(
        id: (j['id'] as num).toInt(),
        date: j['date']?.toString() ?? '',
        title: j['title']?.toString() ?? '',
        subtitle: j['subtitle']?.toString() ?? '',
        intro: j['intro']?.toString() ?? '',
        scriptText: j['script_text']?.toString() ?? '',
        chapters: (j['chapters'] as List? ?? [])
            .whereType<Map>()
            .map((c) => PodcastChapter.fromJson(c.cast()))
            .toList(),
        audioUrl: j['audio_url'] as String?,
        audioBytes: (j['audio_bytes'] as num?)?.toInt() ?? 0,
        durationSeconds: (j['duration_seconds'] as num?)?.toInt() ?? 0,
        ttsProvider: j['tts_provider']?.toString() ?? '',
        ttsVoice: j['tts_voice']?.toString() ?? '',
        playCount: (j['play_count'] as num?)?.toInt() ?? 0,
        publishedAt: j['published_at'] != null
            ? DateTime.tryParse(j['published_at'].toString().replaceFirst(' ', 'T'))
            : null,
      );
}
