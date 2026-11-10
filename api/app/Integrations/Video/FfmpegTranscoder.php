<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Domain\Authoring\Enums\MediaKind;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Turns a source file into AES-128 encrypted HLS with FFmpeg (arch §1.3 C):
 * - video: one pass per rendition of the ladder (720p cap, never upscaled; the lowest is always made), H.264 +
 *   AAC, the logo and "Yekkola · professor" burned in, plus a poster frame. The text is drawn by PHP (GD) into a
 *   transparent PNG and laid over with `overlay`: static FFmpeg 7 builds often lack `drawtext` (it needs
 *   HarfBuzz), and `overlay` is always there;
 * - audio: one mono AAC rendition.
 * Every rendition uses the same key; the playlist's key URI is a placeholder we replace when serving it.
 * Output: `{out}/{rendition}/index.m3u8` + `seg_*.ts`, `{out}/poster.jpg`.
 */
final class FfmpegTranscoder
{
    /**
     * @param  array<string, mixed>  $config  config('yekkola.media')
     */
    public function __construct(private readonly array $config) {}

    private float $deadline = 0.0;

    /**
     * @return array{duration: int, renditions: list<Rendition>, poster: bool}
     *
     * @throws MediaProcessingFailed
     */
    public function transcode(string $source, string $out, MediaKind $kind, string $key, string $watermarkText): array
    {
        $this->deadline = microtime(true) + (int) $this->config['timeout_seconds'];
        $probe = $this->probe($source);
        if ($probe['duration'] <= 0 || ($kind === MediaKind::Video && $probe['height'] === null) || ($kind === MediaKind::Audio && ! $probe['audio'])) {
            throw new MediaProcessingFailed('Not a playable '.$kind->value.' file.');
        }

        $keyFile = $out.'/enc.key';
        $keyInfo = $out.'/enc.keyinfo';
        file_put_contents($keyFile, $key);
        file_put_contents($keyInfo, HlsPlaylist::KEY_PLACEHOLDER."\n".$keyFile."\n");

        try {
            $renditions = [];
            if ($kind === MediaKind::Audio) {
                $renditions[] = $this->audio($source, $out, $keyInfo, $probe['duration']);
            } else {
                foreach ($this->ladder((int) $probe['height']) as $step) {
                    $renditions[] = $this->video($source, $out, $keyInfo, $watermarkText, $step, $probe);
                }
            }

            $poster = $kind === MediaKind::Video && $this->poster($source, $out.'/poster.jpg', $probe['duration']);
        } finally {
            @unlink($keyFile); // never uploaded next to the segments
            @unlink($keyInfo);
            array_map('unlink', glob($out.'/watermark-*.png') ?: []); // not part of the output
        }

        return ['duration' => (int) round($probe['duration']), 'renditions' => $renditions, 'poster' => $poster];
    }

    /**
     * Steps of the ladder at or below the source height; at least the lowest one.
     *
     * @return list<array{name: string, height: int, video_kbps: int, audio_kbps: int}>
     */
    public function ladder(int $sourceHeight): array
    {
        /** @var list<array{name: string, height: int, video_kbps: int, audio_kbps: int}> $all */
        $all = $this->config['renditions'];
        $fitting = array_values(array_filter($all, fn (array $step): bool => $step['height'] <= $sourceHeight));

        return $fitting !== [] ? $fitting : [end($all)];
    }

    /**
     * @return array{duration: float, width: int|null, height: int|null, audio: bool}
     */
    private function probe(string $source): array
    {
        $json = $this->run([
            (string) $this->config['ffprobe'], '-v', 'error',
            '-show_entries', 'format=duration:stream=codec_type,width,height:stream_tags=rotate:stream_side_data=rotation',
            '-of', 'json', $source,
        ], 120);

        /** @var array{format?: array{duration?: string}, streams?: list<array{codec_type?: string, width?: int, height?: int, tags?: array{rotate?: string}, side_data_list?: list<array{rotation?: int|string}>}>} $data */
        $data = json_decode($json, true) ?: [];
        $video = null;
        $audio = false;
        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) === 'video' && $video === null && isset($stream['height'])) {
                $video = $stream;
            }
            $audio = $audio || ($stream['codec_type'] ?? null) === 'audio';
        }

        // Phone videos are often stored landscape with a rotation flag; FFmpeg auto-rotates when decoding, so
        // the ladder and RESOLUTION must use the displayed size.
        $rotation = (int) ($video['tags']['rotate'] ?? 0);
        foreach ($video['side_data_list'] ?? [] as $side) {
            $rotation = isset($side['rotation']) ? (int) $side['rotation'] : $rotation;
        }
        [$width, $height] = abs($rotation) % 180 === 90
            ? [$video['height'] ?? null, $video['width'] ?? null]
            : [$video['width'] ?? null, $video['height'] ?? null];

        return [
            'duration' => (float) ($data['format']['duration'] ?? 0),
            'width' => $width,
            'height' => $height,
            'audio' => $audio,
        ];
    }

    /**
     * @param  array{name: string, height: int, video_kbps: int, audio_kbps: int}  $step
     * @param  array{duration: float, width: int|null, height: int|null, audio: bool}  $probe
     */
    private function video(string $source, string $out, string $keyInfo, string $watermarkText, array $step, array $probe): Rendition
    {
        $dir = $out.'/'.$step['name'];
        @mkdir($dir, 0700, true);
        $height = $step['height'];
        $segment = (int) $this->config['segment_seconds'];
        $logo = is_string($this->config['watermark_logo'] ?? null) && is_file($this->config['watermark_logo']) ? $this->config['watermark_logo'] : null;
        $budget = $this->remaining();
        $margin = max(12, intdiv($height, 26));

        // Inputs: 0 the source, then the text image and the logo when there are any; overlays chained in order.
        $args = [(string) $this->config['ffmpeg'], '-y', '-v', 'error', '-i', $source];
        $graph = "[0:v]scale=-2:{$height}[v0]";
        $last = 'v0';
        $input = 1;
        $text = "{$out}/watermark-{$height}.png";
        if ($this->watermarkImage($watermarkText, $height, $text)) {
            $args = [...$args, '-i', $text];
            $graph .= ";[{$last}][{$input}:v]overlay=W-w-{$margin}:H-h-{$margin}[vt]";
            $last = 'vt';
            $input++;
        }
        if ($logo !== null) {
            $args = [...$args, '-i', $logo];
            $graph .= ";[{$input}:v]scale=-2:".max(16, intdiv($height, 14)).",format=rgba,colorchannelmixer=aa=0.6[logo];[{$last}][logo]overlay={$margin}:{$margin}[vl]";
            $last = 'vl';
        }
        $graph .= ";[{$last}]format=yuv420p[v]";

        $args = [
            ...$args,
            '-filter_complex', $graph, '-map', '[v]', '-map', '0:a:0?',
            '-c:v', 'libx264', '-preset', 'veryfast', '-profile:v', 'main', '-crf', '23',
            '-maxrate', $step['video_kbps'].'k', '-bufsize', ($step['video_kbps'] * 2).'k',
            '-force_key_frames', "expr:gte(t,n_forced*{$segment})",
            '-c:a', 'aac', '-b:a', $step['audio_kbps'].'k', '-ac', '2',
            ...$this->hlsArgs($dir, $keyInfo, $segment),
        ];
        $this->run($args, $budget, $out);

        $width = $probe['width'] !== null && $probe['height'] ? (int) (round($probe['width'] * $height / $probe['height'] / 2) * 2) : null;

        return $this->measure($step['name'], $dir, $probe['duration'], $width, $height);
    }

    /**
     * Draws the burned-in text (e.g. "Yekkola · professor name") with the watermark font, white at 55 % with a soft
     * shadow, into a transparent PNG sized for a video of `$videoHeight`. False when GD/FreeType or the font is
     * missing: the video is then made without the text (logged) rather than not at all.
     */
    public function watermarkImage(string $text, int $videoHeight, string $path): bool
    {
        $font = $this->config['watermark_font'] ?? null;
        if ($text === '' || ! is_string($font) || ! is_file($font) || ! function_exists('imagettftext')) {
            Log::warning('Video watermark text skipped: GD with FreeType or the watermark font is missing.', ['font' => $font]);

            return false;
        }

        $size = max(9, intdiv($videoHeight, 34)); // points
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return false;
        }
        $pad = 3;
        $width = $box[2] - $box[0] + $pad * 2 + 1;
        $height = $box[1] - $box[7] + $pad * 2 + 1;

        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($image === false) {
            return false;
        }
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        $x = $pad - $box[0];
        $y = $pad - $box[7];
        imagettftext($image, $size, 0, $x + 1, $y + 1, (int) imagecolorallocatealpha($image, 0, 0, 0, 76), $font, $text); // shadow, 40 %
        imagettftext($image, $size, 0, $x, $y, (int) imagecolorallocatealpha($image, 255, 255, 255, 57), $font, $text); // 55 %

        $saved = imagepng($image, $path);
        imagedestroy($image);

        return $saved && is_file($path);
    }

    private function audio(string $source, string $out, string $keyInfo, float $duration): Rendition
    {
        $dir = $out.'/audio';
        @mkdir($dir, 0700, true);
        $segment = (int) $this->config['segment_seconds'];

        $this->run([
            (string) $this->config['ffmpeg'], '-y', '-v', 'error', '-i', $source, '-vn',
            '-c:a', 'aac', '-b:a', (int) $this->config['audio_kbps'].'k', '-ac', '1',
            ...$this->hlsArgs($dir, $keyInfo, $segment),
        ], $this->remaining());

        return $this->measure('audio', $dir, $duration, null, null);
    }

    /**
     * @return list<string>
     */
    private function hlsArgs(string $dir, string $keyInfo, int $segment): array
    {
        return [
            '-f', 'hls', '-hls_time', (string) $segment, '-hls_playlist_type', 'vod',
            '-hls_key_info_file', $keyInfo, '-hls_segment_filename', $dir.'/seg_%05d.ts', $dir.'/index.m3u8',
        ];
    }

    private function poster(string $source, string $path, float $duration): bool
    {
        try {
            $this->run([
                (string) $this->config['ffmpeg'], '-y', '-v', 'error', '-ss', (string) min(3.0, $duration / 2), '-i', $source,
                '-frames:v', '1', '-vf', 'scale=-2:360', '-q:v', '4', $path,
            ], 120);
        } catch (MediaProcessingFailed) {
            return false; // no poster is not worth failing the asset
        }

        return is_file($path);
    }

    private function measure(string $name, string $dir, float $duration, ?int $width, ?int $height): Rendition
    {
        $size = 0;
        foreach (glob($dir.'/seg_*.ts') ?: [] as $file) {
            $size += (int) filesize($file);
        }
        if ($size === 0) {
            throw new MediaProcessingFailed("FFmpeg produced no segments for {$name}.");
        }

        // Peak ≈ 1.5 × average: enough headroom for hls.js to pick a rendition the connection can sustain.
        $bandwidth = (int) ceil($size * 8 / max(1.0, $duration) * 1.5);

        return new Rendition($name, $bandwidth, $size, $width, $height);
    }

    /** Seconds left of the FFmpeg budget for this asset (set at the start of transcode()). */
    private function remaining(): int
    {
        $left = (int) ($this->deadline - microtime(true));
        if ($left <= 0) {
            throw new MediaProcessingFailed('Transcoding took longer than the time allowed.');
        }

        return $left;
    }

    /**
     * @param  list<string>  $command
     *
     * @throws MediaProcessingFailed
     */
    private function run(array $command, int $timeout, ?string $cwd = null): string
    {
        $process = new Process($command, cwd: $cwd, timeout: $timeout);

        try {
            $process->mustRun();
        } catch (ProcessFailedException|ProcessTimedOutException $e) {
            throw new MediaProcessingFailed(mb_substr(trim($process->getErrorOutput()) ?: $e->getMessage(), 0, 500), previous: $e);
        }

        return $process->getOutput();
    }
}
