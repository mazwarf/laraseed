<?php

namespace Azwar\Laraseed\Generator;

use Illuminate\Support\Facades\File;
use RuntimeException;

class SeederGenerator
{
    protected string $seederPath;
    protected string $modelNamespace;

    public function __construct()
    {
        $this->modelNamespace = config('laraseed.model_namespace', 'App\\Models');
        $this->seederPath = base_path('database/seeders');
    }

    /**
     * Membaca stub Seeder, me-replace placeholder, lalu menulis ke file fisik.
     */
    public function generateSeeder(string $modelName, int $count, bool $force = false): string
    {
        $stubPath = $this->getStubPath('seeder.stub');

        if (!File::exists($stubPath)) {
            throw new RuntimeException("Stub file not found at: {$stubPath}");
        }

        $stub = File::get($stubPath);

        // Replace placeholders
        $content = str_replace(
            ['{{ modelNamespace }}', '{{ modelName }}', '{{ count }}'],
            [$this->modelNamespace, $modelName, (string)$count],
            $stub
        );

        if (!File::isDirectory($this->seederPath)) {
            File::makeDirectory($this->seederPath, 0755, true);
        }

        $filePath = $this->seederPath . '/' . $modelName . 'Seeder.php';

        if (File::exists($filePath) && !$force) {
            throw new RuntimeException("Seeder already exists: {$filePath}");
        }

        if (File::put($filePath, $content) === false) {
            throw new RuntimeException("Gagal menulis file: {$filePath}");
        }

        return $modelName . 'Seeder';
    }

    protected function getStubPath(string $stubName): string
    {
        $publishedPath = config('laraseed.stubs_path');

        if ($publishedPath && File::exists($publishedPath . '/' . $stubName)) {
            return $publishedPath . '/' . $stubName;
        }

        return __DIR__ . '/../Stubs/' . $stubName;
    }
}
