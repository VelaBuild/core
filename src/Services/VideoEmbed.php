<?php

namespace VelaBuild\Core\Services;

/**
 * A pasted video link, and the player address its settings ask for.
 *
 * The page editor's preview does the same in JavaScript — it has to, it runs
 * as the settings are changed — and the two used to be separate regexes that
 * each knew a little: neither read the time in a link somebody copied at 1:30,
 * and neither recognised a Shorts or a live link at all. They are now held to
 * one table of cases, tests/fixtures/video-embeds.json, which both the PHP and
 * the JavaScript tests run. A case added there binds both.
 */
class VideoEmbed
{
    /** Width over height, as the padding that holds that shape. */
    public const RATIOS = [
        '16:9' => 56.25,
        '4:3'  => 75.0,
        '1:1'  => 100.0,
        '9:16' => 177.7778,
    ];

    /**
     * How wide a shape may run. A portrait video filling a 1200px column is
     * over two thousand pixels tall; it is held to roughly a phone's width, and
     * a square to something a screen can show without scrolling.
     */
    public const MAX_WIDTH = [
        '1:1'  => '720px',
        '9:16' => '400px',
    ];

    /**
     * Settings that read as ON when they were never set. Privacy is on for the
     * embeds a site already had: youtube-nocookie.com sets no tracking cookie
     * until the visitor presses play, which is the answer a site owner would
     * give if they were asked. Controls are on because a player without them
     * is a choice, not a default.
     */
    public const DEFAULTS = [
        'aspect_ratio' => '16:9',
        'start'        => null,
        'end'          => null,
        'autoplay'     => false,
        'mute'         => false,
        'loop'         => false,
        'controls'     => true,
        'privacy'      => true,
    ];

    /**
     * Which service, which video, and the time the link itself started at.
     *
     * @return array{provider: string, id: string, start: int|null}|null
     */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return ['provider' => 'youtube', 'id' => $m[1], 'start' => self::timeInLink($url)];
        }

        if (preg_match('~vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d+)~', $url, $m)) {
            return ['provider' => 'vimeo', 'id' => $m[1], 'start' => self::timeInLink($url)];
        }

        return null;
    }

    /**
     * A time as somebody would write it, in seconds — or null if it cannot be
     * read as one.
     *
     * Reported as "Start at and End at do not work": the block saved with its
     * title and with both times empty, because the times as typed were not in
     * the one or two shapes this read, and anything else was dropped without a
     * word. So it reads what people type — 1:30, 1.30, 1,30, 90, 1m 30s,
     * 1 นาที 30 วินาที, Thai digits — and refuses what is not a time rather
     * than guessing: 1:75 is not a minute and a quarter, and 1:5 could be 1:05
     * or 1:50. The form says which it understood, and says so when it did not.
     */
    public static function seconds(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        $value = mb_strtolower(trim((string) $value));
        $value = strtr($value, ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4',
            '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9']);
        // Longest first: "วินาที" would otherwise lose its "นาที" to minutes.
        $value = preg_replace(
            ['/ชั่วโมง|ชม\.?/u', '/วินาที|วิ\.?/u', '/นาที|น\./u',
             '/\b(?:hours?|hrs?)\b/', '/\b(?:minutes?|mins?)\b/', '/\b(?:seconds?|secs?)\b/'],
            ['h', 's', 'm', 'h', 'm', 's'],
            $value
        );
        // "1 30" is a person leaving out the colon, not the number 130.
        $value = preg_replace('/(\d)\s+(?=\d)/u', '$1:', $value);
        $value = preg_replace('/\s+/u', '', $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value)) {
            return (int) $value > 0 ? (int) $value : null;
        }

        // 1:30, 1.30, 1,30 — and 1:02:03 with hours
        if (preg_match('/^(\d+)[:.,](\d{2})(?:[:.,](\d{2}))?$/', $value, $m)) {
            if (isset($m[3])) {
                if ((int) $m[2] >= 60 || (int) $m[3] >= 60) {
                    return null;
                }
                $total = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
            } else {
                if ((int) $m[2] >= 60) {
                    return null;
                }
                $total = (int) $m[1] * 60 + (int) $m[2];
            }

            return $total > 0 ? $total : null;
        }

        // 1h2m3s, 1m30s, 1m30, 90s, 2m
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $value, $m)) {
            $h = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : null;
            $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
            $sec = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

            // A smaller unit beside a larger one is a remainder, not a total.
            if (($h !== null && $min !== null && $min >= 60) || (($h !== null || $min !== null) && $sec !== null && $sec >= 60)) {
                return null;
            }

            $total = ($h ?? 0) * 3600 + ($min ?? 0) * 60 + ($sec ?? 0);

            return $total > 0 ? $total : null;
        }

        return null;
    }

    /** The settings with every gap filled from DEFAULTS. */
    public static function settings(?array $settings): array
    {
        // A null is "never set", not "off": a block saved before these settings
        // existed has none of them, and must get the defaults, not a player
        // with its controls taken away.
        $given = array_filter($settings ?? [], fn ($v) => $v !== null && $v !== '');
        $settings = array_merge(self::DEFAULTS, array_intersect_key($given, self::DEFAULTS));

        if (!isset(self::RATIOS[$settings['aspect_ratio']])) {
            $settings['aspect_ratio'] = self::DEFAULTS['aspect_ratio'];
        }

        foreach (['autoplay', 'mute', 'loop', 'controls', 'privacy'] as $flag) {
            $settings[$flag] = filter_var($settings[$flag], FILTER_VALIDATE_BOOLEAN);
        }

        return $settings;
    }

    /**
     * The address of the player, with every setting that applies to it.
     */
    public static function url(?string $link, ?array $settings = []): ?string
    {
        $video = self::parse($link);
        if (!$video) {
            return null;
        }

        $s = self::settings($settings);

        // A time set in the form wins; a time in the link is used when the form
        // has none, so a link copied at 1:30 starts at 1:30 without anybody
        // having to notice the field.
        $start = self::seconds($s['start']) ?? $video['start'];
        $end = self::seconds($s['end']);

        // Browsers refuse to start a video with sound on its own. Autoplay
        // without mute is a setting that silently does nothing, so it mutes.
        $mute = $s['mute'] || $s['autoplay'];

        if ($video['provider'] === 'youtube') {
            $query = array_filter([
                'start'       => $start,
                'end'         => ($end && (!$start || $end > $start)) ? $end : null,
                'autoplay'    => $s['autoplay'] ? 1 : null,
                'mute'        => $mute ? 1 : null,
                // Without it iOS takes an autoplaying video full screen, or does
                // not play it at all.
                'playsinline' => $s['autoplay'] ? 1 : null,
                'loop'        => $s['loop'] ? 1 : null,
                // YouTube loops a single video only when it is also its own
                // playlist. loop=1 on its own is accepted and ignored.
                'playlist'    => $s['loop'] ? $video['id'] : null,
                'controls'    => $s['controls'] ? null : 0,
            ], fn ($v) => $v !== null);

            $host = $s['privacy'] ? 'https://www.youtube-nocookie.com' : 'https://www.youtube.com';

            return $host . '/embed/' . $video['id'] . ($query ? '?' . http_build_query($query) : '');
        }

        // Vimeo has no end time, and takes its start as a fragment.
        $query = array_filter([
            'autoplay' => $s['autoplay'] ? 1 : null,
            'muted'    => $mute ? 1 : null,
            'loop'     => $s['loop'] ? 1 : null,
            // Honoured only on Vimeo's paid plans; harmless elsewhere.
            'controls' => $s['controls'] ? null : 0,
            'dnt'      => $s['privacy'] ? 1 : null,
        ], fn ($v) => $v !== null);

        return 'https://player.vimeo.com/video/' . $video['id']
            . ($query ? '?' . http_build_query($query) : '')
            . ($start ? '#t=' . $start . 's' : '');
    }

    /**
     * The padding that holds the player at its shape.
     *
     * A share of the WIDTH, so a portrait or square frame held to a narrower
     * box by maxWidth() is also a shorter one.
     */
    public static function padding(?array $settings): string
    {
        return self::RATIOS[self::settings($settings)['aspect_ratio']] . '%';
    }

    /** The width a shape is held to, or null for as wide as the column. */
    public static function maxWidth(?array $settings): ?string
    {
        return self::MAX_WIDTH[self::settings($settings)['aspect_ratio']] ?? null;
    }

    private static function timeInLink(string $url): ?int
    {
        // ?t=90, &t=1m30s, ?start=90, and Vimeo's #t=90s
        if (preg_match('~[?&#](?:t|start)=([0-9hms:]+)~i', $url, $m)) {
            return self::seconds($m[1]);
        }

        return null;
    }
}
