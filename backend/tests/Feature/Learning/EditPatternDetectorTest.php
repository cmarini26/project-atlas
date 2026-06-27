<?php

namespace Tests\Feature\Learning;

use App\Services\Learning\EditPatternDetector;
use Tests\TestCase;

class EditPatternDetectorTest extends TestCase
{
    private EditPatternDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new EditPatternDetector();
    }

    public function test_detects_shortened_content(): void
    {
        $original = ['body' => str_repeat('word ', 50)];
        $edited = ['body' => str_repeat('word ', 20)];

        $patterns = $this->detector->detect($original, $edited);

        $this->assertSame('shorter', $patterns['length_preference']);
    }

    public function test_detects_lengthened_content(): void
    {
        $original = ['body' => str_repeat('word ', 20)];
        $edited = ['body' => str_repeat('word ', 50)];

        $patterns = $this->detector->detect($original, $edited);

        $this->assertSame('longer', $patterns['length_preference']);
    }

    public function test_detects_hashtag_added(): void
    {
        $original = ['body' => 'No hashtags here'];
        $edited = ['body' => 'Now with #hashtag'];

        $patterns = $this->detector->detect($original, $edited);

        $this->assertSame('added', $patterns['hashtag_preference']);
    }

    public function test_detects_hashtag_removed(): void
    {
        $original = ['body' => 'Has #hashtag and #another'];
        $edited = ['body' => 'No hashtags now'];

        $patterns = $this->detector->detect($original, $edited);

        $this->assertSame('removed', $patterns['hashtag_preference']);
    }

    public function test_detects_price_added(): void
    {
        $original = ['body' => 'Buy now'];
        $edited = ['body' => 'Buy now for $99.99'];

        $patterns = $this->detector->detect($original, $edited);

        $this->assertSame('added', $patterns['price_inclusion']);
    }

    public function test_detects_price_removed(): void
    {
        $original = ['body' => 'Only $199.99'];
        $edited = ['body' => 'Amazing deal'];

        $patterns = $this->detector->detect($original, $edited);

        $this->assertSame('removed', $patterns['price_inclusion']);
    }

    public function test_no_patterns_when_content_unchanged(): void
    {
        $text = 'Same text without changes';
        $original = ['body' => $text];
        $edited = ['body' => $text];

        $patterns = $this->detector->detect($original, $edited);

        $this->assertEmpty($patterns);
    }

    public function test_extracts_text_from_multiple_fields(): void
    {
        $original = ['subject' => 'Subject line', 'body' => str_repeat('word ', 50)];
        $edited = ['subject' => 'Subject', 'body' => str_repeat('word ', 15)];

        $patterns = $this->detector->detect($original, $edited);

        $this->assertArrayHasKey('length_preference', $patterns);
    }
}
