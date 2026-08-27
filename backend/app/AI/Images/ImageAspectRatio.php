<?php

namespace App\AI\Images;

/**
 * Supported output shapes for generated imagery. Kept deliberately small —
 * campaign creatives only ever need a square, a portrait, or a landscape —
 * so every provider can map these three cases without guesswork.
 */
enum ImageAspectRatio: string
{
    case Square = '1:1';
    case Portrait = '3:4';
    case Landscape = '4:3';

    public function width(): int
    {
        return match ($this) {
            self::Square => 1024,
            self::Portrait => 1024,
            self::Landscape => 1536,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Square => 1024,
            self::Portrait => 1536,
            self::Landscape => 1024,
        };
    }

    /** Pixel size string in the `WxH` form most HTTP image APIs expect. */
    public function pixelSize(): string
    {
        return $this->width().'x'.$this->height();
    }
}
