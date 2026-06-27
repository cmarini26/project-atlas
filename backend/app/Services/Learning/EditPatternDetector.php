<?php

namespace App\Services\Learning;

class EditPatternDetector
{
    /**
     * Detect patterns from edits applied during an edited_and_approved action.
     * Returns a structured descriptor of detected patterns for the Learning value payload.
     *
     * @param  array<string, mixed>  $originalContent
     * @param  array<string, mixed>  $editedContent
     * @return array<string, mixed>
     */
    public function detect(array $originalContent, array $editedContent): array
    {
        $patterns = [];

        $originalText = $this->extractText($originalContent);
        $editedText = $this->extractText($editedContent);

        if ($originalText !== '' && $editedText !== '') {
            $lengthPattern = $this->detectLengthPattern($originalText, $editedText);
            if ($lengthPattern !== null) {
                $patterns['length_preference'] = $lengthPattern;
            }

            $hashtagPattern = $this->detectHashtagPattern($originalText, $editedText);
            if ($hashtagPattern !== null) {
                $patterns['hashtag_preference'] = $hashtagPattern;
            }

            $pricePattern = $this->detectPricePattern($originalText, $editedText);
            if ($pricePattern !== null) {
                $patterns['price_inclusion'] = $pricePattern;
            }
        }

        return $patterns;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function extractText(array $content): string
    {
        $parts = [];

        foreach (['body', 'caption', 'text', 'content', 'subject'] as $key) {
            if (isset($content[$key]) && is_string($content[$key])) {
                $parts[] = $content[$key];
            }
        }

        return implode(' ', $parts);
    }

    private function detectLengthPattern(string $original, string $edited): ?string
    {
        $originalLen = mb_strlen($original);
        $editedLen = mb_strlen($edited);

        if ($originalLen === 0) {
            return null;
        }

        $ratio = $editedLen / $originalLen;

        if ($ratio < 0.7) {
            return 'shorter';
        }

        if ($ratio > 1.3) {
            return 'longer';
        }

        return null;
    }

    private function detectHashtagPattern(string $original, string $edited): ?string
    {
        $originalHashtags = preg_match_all('/#\w+/', $original, $m1) !== false ? $m1[0] : [];
        $editedHashtags = preg_match_all('/#\w+/', $edited, $m2) !== false ? $m2[0] : [];

        $originalCount = count($originalHashtags);
        $editedCount = count($editedHashtags);

        if ($editedCount > $originalCount && $originalCount === 0) {
            return 'added';
        }

        if ($editedCount === 0 && $originalCount > 0) {
            return 'removed';
        }

        if ($editedCount > $originalCount + 1) {
            return 'increased';
        }

        if ($originalCount > $editedCount + 1) {
            return 'decreased';
        }

        return null;
    }

    private function detectPricePattern(string $original, string $edited): ?string
    {
        $pricePattern = '/\$[\d,]+(\.\d{2})?/';

        $originalHasPrice = (bool) preg_match($pricePattern, $original);
        $editedHasPrice = (bool) preg_match($pricePattern, $edited);

        if (! $originalHasPrice && $editedHasPrice) {
            return 'added';
        }

        if ($originalHasPrice && ! $editedHasPrice) {
            return 'removed';
        }

        return null;
    }
}
