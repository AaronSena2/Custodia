<?php
/**
 * Dependency-free duration extraction for uploaded audio/video, run inline
 * at upload time — same philosophy as includes/text_extract.php (no
 * external library, no Composer, honest about what it can't do). Reads only
 * the small header/atom structures each container format needs, via
 * seek + bounded reads, so even a multi-hundred-MB video doesn't get loaded
 * into memory just to find out how long it runs.
 *
 * Coverage: WAV (RIFF fmt/data chunks — exact) and MP4/M4A/MOV (ISO base
 * media 'moov'/'mvhd' box — exact). MP3 duration is best-effort: uses the
 * Xing/VBRI header when present (accurate for VBR encodes), otherwise
 * estimates from file size and the first frame's bitrate (accurate for CBR,
 * approximate for VBR files with no Xing header). WebM/OGG/AVI/MKV are not
 * parsed — returns null rather than guessing, matching how text_extract.php
 * flags genuinely unsupported formats instead of silently getting it wrong.
 */

/** @return float|null Duration in seconds, or null if not determinable. */
function custodia_extract_media_duration(string $filePath, string $originalName): ?float
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    try {
        return match ($ext) {
            'wav' => custodia_wav_duration($filePath),
            'mp4', 'm4a', 'mov' => custodia_isobmff_duration($filePath),
            'mp3' => custodia_mp3_duration($filePath),
            default => null,
        };
    } catch (Throwable $e) {
        error_log('[custodia] media duration extraction failed for ' . $originalName . ': ' . $e->getMessage());
        return null;
    }
}

function custodia_wav_duration(string $filePath): ?float
{
    $fh = fopen($filePath, 'rb');
    if ($fh === false) {
        return null;
    }
    try {
        if (fread($fh, 4) !== 'RIFF') {
            return null;
        }
        fseek($fh, 4, SEEK_CUR); // overall file size, unused
        if (fread($fh, 4) !== 'WAVE') {
            return null;
        }

        $byteRate = null;
        $dataSize = null;
        while (!feof($fh) && ($byteRate === null || $dataSize === null)) {
            $header = fread($fh, 8);
            if ($header === false || strlen($header) < 8) {
                break;
            }
            $chunkId = substr($header, 0, 4);
            $chunkSize = unpack('V', substr($header, 4, 4))[1];

            if ($chunkId === 'fmt ') {
                $fmt = fread($fh, $chunkSize);
                if (strlen($fmt) >= 16) {
                    $fields = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate', $fmt);
                    $byteRate = $fields['byteRate'];
                }
            } elseif ($chunkId === 'data') {
                $dataSize = $chunkSize;
                break; // duration only needs the size, not the audio data itself
            } else {
                fseek($fh, $chunkSize + ($chunkSize % 2), SEEK_CUR); // chunks are word-aligned
            }
        }

        if ($byteRate === null || $dataSize === null || $byteRate === 0) {
            return null;
        }
        return round($dataSize / $byteRate, 2);
    } finally {
        fclose($fh);
    }
}

/**
 * ISO Base Media (MP4/M4A) and QuickTime (MOV) share the same box structure
 * for this purpose: find the top-level 'moov' box, then its 'mvhd' child,
 * which carries a timescale and a duration expressed in that timescale.
 */
function custodia_isobmff_duration(string $filePath): ?float
{
    $fh = fopen($filePath, 'rb');
    if ($fh === false) {
        return null;
    }
    try {
        $fileSize = filesize($filePath);
        $moovRange = custodia_find_box($fh, 0, $fileSize, 'moov');
        if ($moovRange === null) {
            return null;
        }
        [$moovStart, $moovEnd] = $moovRange;
        $mvhdRange = custodia_find_box($fh, $moovStart, $moovEnd, 'mvhd');
        if ($mvhdRange === null) {
            return null;
        }
        [$mvhdStart, $mvhdEnd] = $mvhdRange;

        fseek($fh, $mvhdStart);
        $versionByte = fread($fh, 1);
        if ($versionByte === false) {
            return null;
        }
        $version = ord($versionByte);
        fseek($fh, 3, SEEK_CUR); // flags

        if ($version === 1) {
            fseek($fh, 16, SEEK_CUR); // creation_time(8) + modification_time(8)
            $timescale = unpack('N', fread($fh, 4))[1];
            $duration = unpack('J', fread($fh, 8))[1]; // 64-bit unsigned BE
        } else {
            fseek($fh, 8, SEEK_CUR); // creation_time(4) + modification_time(4)
            $timescale = unpack('N', fread($fh, 4))[1];
            $duration = unpack('N', fread($fh, 4))[1];
        }

        if (!$timescale) {
            return null;
        }
        return round($duration / $timescale, 2);
    } finally {
        fclose($fh);
    }
}

/**
 * Scans sibling boxes in [$start, $end) for one named $targetType, returning
 * [contentStart, contentEnd] (i.e. after its own 8-or-16-byte header) or null.
 */
function custodia_find_box($fh, int $start, int $end, string $targetType): ?array
{
    $pos = $start;
    while ($pos < $end - 8) {
        fseek($fh, $pos);
        $header = fread($fh, 8);
        if ($header === false || strlen($header) < 8) {
            return null;
        }
        $size = unpack('N', substr($header, 0, 4))[1];
        $type = substr($header, 4, 4);
        $headerLen = 8;

        if ($size === 1) {
            $largeSize = fread($fh, 8);
            if ($largeSize === false || strlen($largeSize) < 8) {
                return null;
            }
            $size = unpack('J', $largeSize)[1];
            $headerLen = 16;
        } elseif ($size === 0) {
            $size = $end - $pos; // box extends to end of parent/file
        }
        if ($size < $headerLen) {
            return null; // malformed — bail rather than loop forever
        }

        if ($type === $targetType) {
            return [$pos + $headerLen, $pos + $size];
        }
        $pos += $size;
    }
    return null;
}

const CUSTODIA_MP3_BITRATES_V1_L3 = [null, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, null];
const CUSTODIA_MP3_BITRATES_V2_L3 = [null, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, null];
const CUSTODIA_MP3_SAMPLERATES = [
    3 => [44100, 48000, 32000], // MPEG version bits: 3 = V1
    2 => [22050, 24000, 16000], // 2 = V2
    0 => [11025, 12000, 8000],  // 0 = V2.5
];

/** Best-effort MP3 duration: exact if a Xing/VBRI header is present, CBR estimate otherwise. */
function custodia_mp3_duration(string $filePath): ?float
{
    $fh = fopen($filePath, 'rb');
    if ($fh === false) {
        return null;
    }
    try {
        $fileSize = filesize($filePath);
        $pos = 0;

        $id3 = fread($fh, 10);
        if ($id3 !== false && strlen($id3) === 10 && substr($id3, 0, 3) === 'ID3') {
            // Syncsafe size: 4 bytes, 7 significant bits each.
            $bytes = array_values(unpack('C4', substr($id3, 6, 4)));
            $tagSize = ($bytes[0] << 21) | ($bytes[1] << 14) | ($bytes[2] << 7) | $bytes[3];
            $pos = 10 + $tagSize;
        }

        // Scan forward (bounded) for the first valid MPEG frame sync.
        $searchLimit = min($fileSize, $pos + 65536);
        fseek($fh, $pos);
        $frameHeader = null;
        $framePos = null;
        while ($pos < $searchLimit - 4) {
            fseek($fh, $pos);
            $bytes = fread($fh, 4);
            if ($bytes === false || strlen($bytes) < 4) {
                break;
            }
            $b = array_values(unpack('C4', $bytes));
            if ($b[0] === 0xFF && ($b[1] & 0xE0) === 0xE0) {
                $frameHeader = $b;
                $framePos = $pos;
                break;
            }
            $pos++;
        }
        if ($frameHeader === null) {
            return null;
        }

        $versionBits = ($frameHeader[1] >> 3) & 0x03;
        $layerBits = ($frameHeader[1] >> 1) & 0x03;
        if ($layerBits !== 0x01) { // only Layer III (standard ".mp3") is handled
            return null;
        }
        $bitrateIndex = ($frameHeader[2] >> 4) & 0x0F;
        $samplerateIndex = ($frameHeader[2] >> 2) & 0x03;
        if ($samplerateIndex === 3 || !isset(CUSTODIA_MP3_SAMPLERATES[$versionBits])) {
            return null;
        }
        $sampleRate = CUSTODIA_MP3_SAMPLERATES[$versionBits][$samplerateIndex];
        $isV1 = $versionBits === 3;
        $bitrateTable = $isV1 ? CUSTODIA_MP3_BITRATES_V1_L3 : CUSTODIA_MP3_BITRATES_V2_L3;
        $bitrateKbps = $bitrateTable[$bitrateIndex] ?? null;
        if ($bitrateKbps === null) {
            return null;
        }
        $padding = ($frameHeader[2] >> 1) & 0x01;
        $samplesPerFrame = $isV1 ? 1152 : 576;
        $slotSize = $isV1 ? 144 : 72;
        $frameSize = (int) floor($slotSize * $bitrateKbps * 1000 / $sampleRate) + $padding;

        // Look inside this first frame for a Xing/Info (or VBRI) tag, which
        // gives an exact frame count for VBR files — worth checking before
        // falling back to a CBR-shaped estimate.
        if ($frameSize > 4 && $frameSize < 8192) {
            fseek($fh, $framePos);
            $frameBytes = fread($fh, $frameSize);
            if ($frameBytes !== false) {
                foreach (['Xing', 'Info'] as $tag) {
                    $tagPos = strpos($frameBytes, $tag);
                    if ($tagPos !== false && $tagPos + 8 <= strlen($frameBytes)) {
                        $flags = unpack('N', substr($frameBytes, $tagPos + 4, 4))[1];
                        if ($flags & 0x01) { // frames-count field present
                            $frameCount = unpack('N', substr($frameBytes, $tagPos + 8, 4))[1];
                            return round($frameCount * $samplesPerFrame / $sampleRate, 2);
                        }
                    }
                }
                $vbriPos = strpos($frameBytes, 'VBRI');
                if ($vbriPos !== false && $vbriPos + 22 <= strlen($frameBytes)) {
                    $frameCount = unpack('N', substr($frameBytes, $vbriPos + 18, 4))[1];
                    return round($frameCount * $samplesPerFrame / $sampleRate, 2);
                }
            }
        }

        // CBR estimate: remaining file size at this bitrate.
        $audioBytes = $fileSize - $framePos;
        return round($audioBytes * 8 / ($bitrateKbps * 1000), 2);
    } finally {
        fclose($fh);
    }
}
