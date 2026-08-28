<?php

namespace Tests\Feature\Console;

use App\AI\Providers\OllamaAiProvider;
use App\AI\Testing\FakeAiProvider;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RunFactExtractionEvalTest extends TestCase
{
    private string $casesDir;

    private string $outFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->casesDir = storage_path('framework/testing/eval-cases-'.uniqid());
        $this->outFile = storage_path('framework/testing/eval-out-'.uniqid().'.json');
        File::ensureDirectoryExists($this->casesDir);

        File::put($this->casesDir.'/case-a.json', json_encode([
            'name' => 'case-a',
            'url' => 'https://example.test',
            'title' => 'Example',
            'body_text' => 'A page about the business with a name and an email.',
            'expected_keys' => ['business.name', 'contact.email'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->casesDir);
        File::delete($this->outFile);

        parent::tearDown();
    }

    private function bindOllama(FakeAiProvider $fake): void
    {
        $this->app->instance(OllamaAiProvider::class, $fake);
    }

    private function facts(array $pairs): string
    {
        return json_encode([
            'facts' => array_map(fn (array $p): array => [
                'key' => $p[0], 'value' => $p[1], 'data_type' => 'string', 'confidence' => 90,
            ], $pairs),
        ], JSON_THROW_ON_ERROR);
    }

    public function test_passes_and_writes_a_report_when_the_gate_provider_clears_thresholds(): void
    {
        $fake = new FakeAiProvider();
        $fake->queueResponse($this->facts([['business.name', 'X'], ['contact.email', 'a@b.test']]));
        $this->bindOllama($fake);

        $this->artisan('ai:eval:fact-extraction', [
            '--providers' => 'ollama',
            '--gate' => 'ollama',
            '--cases' => $this->casesDir,
            '--out' => $this->outFile,
        ])->assertExitCode(0)->expectsOutputToContain('PASS');

        $this->assertFileExists($this->outFile);
        $report = json_decode(File::get($this->outFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $report['verdict']);
        $this->assertSame('ollama', $report['providers'][0]['provider']);
    }

    public function test_fails_when_the_gate_provider_misses_a_threshold(): void
    {
        $fake = new FakeAiProvider();
        // Only one of two expected keys -> recall 0.5 < 0.80.
        $fake->queueResponse($this->facts([['business.name', 'X']]));
        $this->bindOllama($fake);

        $this->artisan('ai:eval:fact-extraction', [
            '--providers' => 'ollama',
            '--gate' => 'ollama',
            '--cases' => $this->casesDir,
            '--out' => $this->outFile,
        ])->assertExitCode(1)->expectsOutputToContain('FAIL');

        $report = json_decode(File::get($this->outFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $report['verdict']);
    }

    public function test_does_not_gate_when_the_gate_provider_was_not_run(): void
    {
        $fake = new FakeAiProvider();
        $fake->queueResponse($this->facts([['business.name', 'X'], ['contact.email', 'a@b.test']]));
        $this->bindOllama($fake);

        $this->artisan('ai:eval:fact-extraction', [
            '--providers' => 'ollama',
            '--gate' => 'anthropic',
            '--cases' => $this->casesDir,
            '--out' => $this->outFile,
        ])->assertExitCode(0)->expectsOutputToContain('was not run');
    }
}
