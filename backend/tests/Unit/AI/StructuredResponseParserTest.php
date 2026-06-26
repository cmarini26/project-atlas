<?php

namespace Tests\Unit\AI;

use App\AI\AiResponse;
use App\AI\StructuredResponseParser;
use InvalidArgumentException;
use Tests\TestCase;

class StructuredResponseParserTest extends TestCase
{
    private StructuredResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new StructuredResponseParser();
    }

    public function test_parses_plain_json(): void
    {
        $response = new AiResponse(
            content: '{"facts": [{"key": "business.name", "value": "Acme"}]}',
            model: 'test',
            inputTokens: 0,
            outputTokens: 0,
        );

        $data = $this->parser->parse($response);

        $this->assertArrayHasKey('facts', $data);
        $this->assertCount(1, $data['facts']);
    }

    public function test_strips_markdown_code_fences(): void
    {
        $response = new AiResponse(
            content: "```json\n{\"facts\": []}\n```",
            model: 'test',
            inputTokens: 0,
            outputTokens: 0,
        );

        $data = $this->parser->parse($response);

        $this->assertArrayHasKey('facts', $data);
    }

    public function test_strips_plain_code_fences(): void
    {
        $response = new AiResponse(
            content: "```\n{\"facts\": []}\n```",
            model: 'test',
            inputTokens: 0,
            outputTokens: 0,
        );

        $data = $this->parser->parse($response);

        $this->assertIsArray($data);
    }

    public function test_throws_on_invalid_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $response = new AiResponse(
            content: 'not valid json',
            model: 'test',
            inputTokens: 0,
            outputTokens: 0,
        );

        $this->parser->parse($response);
    }
}
