<?php

namespace App\AI\Eval;

use RuntimeException;

class EvalCaseRepository
{
    public function defaultDirectory(): string
    {
        return base_path('eval/fact-extraction/cases');
    }

    /**
     * @return list<EvalCase>
     */
    public function load(?string $directory = null): array
    {
        $directory = $directory ?? $this->defaultDirectory();

        if (! is_dir($directory)) {
            throw new RuntimeException("Eval case directory not found: {$directory}");
        }

        $files = glob(rtrim($directory, '/').'/*.json') ?: [];
        sort($files);

        $cases = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (! is_array($decoded)) {
                throw new RuntimeException("Eval case file is not valid JSON: {$file}");
            }

            $cases[] = EvalCase::fromArray($decoded);
        }

        if ($cases === []) {
            throw new RuntimeException("No eval cases found in {$directory}");
        }

        return $cases;
    }
}
