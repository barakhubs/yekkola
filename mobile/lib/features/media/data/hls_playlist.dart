/// One rendition's media playlist as the API serves it (signed segment URLs, our key URI), turned into what
/// the app keeps on disk: the segment URLs to fetch, and a local template where segments are `seg/<n>.ts`
/// and the key URI is a placeholder filled in only while playing. The template never holds the key.
class OfflinePlaylist {
  const OfflinePlaylist({required this.template, required this.segmentUrls});

  static const keyPlaceholder = '{{YEKKOLA_KEY}}';

  /// Thrown for playlists we can't keep offline (not one AES-128 key, no segments, a nested playlist).
  static FormatException unsupported(String why) => FormatException('Unsupported offline playlist: $why');

  factory OfflinePlaylist.parse(String playlist) {
    final lines = playlist.split(RegExp(r'\r\n|\n|\r'));
    if (lines.isEmpty || lines.first.trim() != '#EXTM3U') throw unsupported('not an HLS playlist');

    final out = <String>[];
    final segments = <String>[];
    var keys = 0;

    for (final raw in lines) {
      final line = raw.trim();
      if (line.isEmpty) continue;

      if (line.startsWith('#EXT-X-STREAM-INF')) throw unsupported('a master playlist');
      if (line.startsWith('#EXT-X-MAP')) throw unsupported('fMP4 init segments');
      if (line.startsWith('#EXT-X-KEY')) {
        if (!line.contains('METHOD=AES-128')) throw unsupported('a key method other than AES-128');
        keys++;
        out.add(line.replaceFirst(RegExp(r'URI="[^"]*"'), 'URI="$keyPlaceholder"'));
        continue;
      }
      if (line.startsWith('#')) {
        out.add(line);
        continue;
      }
      out.add('seg/${segments.length}.ts');
      segments.add(line);
    }

    if (keys != 1) throw unsupported('expected exactly one AES-128 key, found $keys');
    if (segments.isEmpty) throw unsupported('no segments');
    if (!out.contains('#EXT-X-ENDLIST')) out.add('#EXT-X-ENDLIST');

    return OfflinePlaylist(template: '${out.join('\n')}\n', segmentUrls: segments);
  }

  /// Local playlist: segment names relative to the playlist's own URL, the key at [keyUri].
  final String template;
  final List<String> segmentUrls;

  static String render(String template, String keyUri) => template.replaceAll(keyPlaceholder, keyUri);
}
