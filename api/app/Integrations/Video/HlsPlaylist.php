<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/**
 * Builds the playlists we serve. FFmpeg writes media playlists with relative segment names and a placeholder
 * key URI; at playback we swap in signed segment URLs and our key endpoint.
 */
final class HlsPlaylist
{
    /** Written by FFmpeg as the key URI (key info file); replaced on every request. */
    public const KEY_PLACEHOLDER = 'yekkola-key';

    /**
     * Segment lines → `$signSegment(name)`. Tags and blank lines are kept.
     *
     * @param  callable(string): string  $signSegment
     */
    public static function signSegments(string $playlist, callable $signSegment): string
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($playlist)) ?: [];

        return implode("\n", array_map(
            fn (string $line): string => trim($line) === '' || str_starts_with($line, '#') ? $line : $signSegment(trim($line)),
            $lines,
        ))."\n";
    }

    /** The placeholder key URI → our key endpoint. */
    public static function withKey(string $playlist, string $keyUri): string
    {
        return str_replace('URI="'.self::KEY_PLACEHOLDER.'"', 'URI="'.str_replace('"', '%22', $keyUri).'"', $playlist);
    }

    /**
     * @param  list<Rendition>  $renditions
     * @param  callable(Rendition): string  $playlistUrl
     */
    public static function master(array $renditions, callable $playlistUrl): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-INDEPENDENT-SEGMENTS'];
        foreach ($renditions as $rendition) {
            $attributes = 'BANDWIDTH='.$rendition->bandwidth;
            if ($rendition->width !== null && $rendition->height !== null) {
                $attributes .= ",RESOLUTION={$rendition->width}x{$rendition->height}";
            }
            $lines[] = "#EXT-X-STREAM-INF:{$attributes},NAME=\"{$rendition->name}\"";
            $lines[] = $playlistUrl($rendition);
        }

        return implode("\n", $lines)."\n";
    }
}
