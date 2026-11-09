<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Mapper;

/** Medienobjekte im Format des Vertrags (Media): echte URLs oder sichtbare Platzhalter ohne URL. */
final class MediaMapper
{
    private const MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'avif' => 'image/avif',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'm3u8' => 'application/vnd.apple.mpegurl',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'pdf' => 'application/pdf',
    ];

    public static function mime(string $url): ?string
    {
        $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return self::MIME[$ext] ?? null;
    }

    /** @param array{url:string,alt:string,caption:?string} $image @return array<string,mixed> */
    public static function image(array $image): array
    {
        return [
            'kind' => 'image',
            'available' => true,
            'label' => (string) $image['alt'],
            'alt' => (string) $image['alt'],
            'caption' => $image['caption'] !== null && $image['caption'] !== '' ? (string) $image['caption'] : null,
            'url' => (string) $image['url'],
            'mime_type' => self::mime((string) $image['url']),
            'width' => null,
            'height' => null,
            'duration_seconds' => null,
            'placeholder_reason' => null,
        ];
    }

    /** @return array<string,mixed> */
    public static function video(?string $url, bool $placeholder, ?int $duration, string $title): array
    {
        if ($placeholder || $url === null || $url === '') {
            return self::placeholder('video', __('Video folgt', 'bootspruefung-learning'), $duration);
        }

        return [
            'kind' => 'video',
            'available' => true,
            'label' => $title,
            'alt' => null,
            'caption' => null,
            'url' => $url,
            'mime_type' => self::mime($url),
            'width' => null,
            'height' => null,
            'duration_seconds' => $duration,
            'placeholder_reason' => null,
        ];
    }

    /** @return array<string,mixed> */
    public static function placeholder(string $mediaKind, string $label, ?int $duration = null): array
    {
        return [
            'kind' => 'placeholder',
            'placeholder_for' => $mediaKind,
            'available' => false,
            'label' => $label,
            'alt' => null,
            'caption' => null,
            'url' => null,
            'mime_type' => null,
            'width' => null,
            'height' => null,
            'duration_seconds' => $duration,
            'placeholder_reason' => 'awaiting_asset',
        ];
    }
}
