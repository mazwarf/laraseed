<?php

namespace Azwar\Laraseed\Generator;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * FileGenerator — Modul 5
 *
 * Mengambil isi stub, me-replace placeholder, lalu menyimpan hasil
 * akhirnya ke dalam file `.php` yang nyata.
 */
class FileGenerator
{
    /** @var string Base path untuk folder factories */
    protected string $factoryPath;
    
    /** @var string Namespace factory */
    protected string $factoryNamespace;
    
    /** @var string Namespace model utama aplikasi */
    protected string $modelNamespace;

    public function __construct()
    {
        // Ambil konfigurasi dari `laraseed.php` (dengan fallback default Laravel)
        $this->modelNamespace   = config('laraseed.model_namespace', 'App\\Models');
        $this->factoryPath      = base_path(config('laraseed.output.factory', 'database/factories'));
        
        // Asumsi standar Laravel: Database\Factories
        $this->factoryNamespace = 'Database\\Factories';
    }

    /**
     * Tulis file Factory ke direktori tujuan.
     *
     * @param string $modelName Nama Model (misal: "User", "Product")
     * @param string $definition Isi array definition() dari Gemini
     * @param bool   $force      Apakah menimpa file jika sudah ada
     * @return string Path absolut ke file yang ditulis
     * 
     * @throws RuntimeException Jika gagal membuat direktori / file.
     */
    public function generateFactory(string $modelName, string $definition, bool $force = false): string
    {
        $stubPath = $this->getStubPath('factory.stub');
        
        if (!File::exists($stubPath)) {
            throw new RuntimeException("Stub file not found at: {$stubPath}");
        }

        $stub = File::get($stubPath);

        // Replace placeholders
        $content = str_replace(
            ['{{ factoryNamespace }}', '{{ modelNamespace }}', '{{ modelName }}', '{{ definition }}'],
            [$this->factoryNamespace, $this->modelNamespace, $modelName, $definition],
            $stub
        );

        // Pastikan folder tujuan ada
        if (!File::isDirectory($this->factoryPath)) {
            File::makeDirectory($this->factoryPath, 0755, true);
        }

        $filePath = $this->factoryPath . '/' . $modelName . 'Factory.php';

        if (File::exists($filePath) && !$force) {
            throw new RuntimeException("Factory already exists: {$filePath}. Gunakan opsi --force untuk menimpa.");
        }

        if (File::put($filePath, $content) === false) {
            throw new RuntimeException("Gagal menulis file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Dapatkan path ke file stub. Utamakan versi publish dari aplikasi utama,
     * lalu fallback ke stub bawaan di dalam package ini.
     */
    protected function getStubPath(string $stubName): string
    {
        $publishedPath = config('laraseed.stubs_path');

        if ($publishedPath && File::exists($publishedPath . '/' . $stubName)) {
            return $publishedPath . '/' . $stubName;
        }

        return __DIR__ . '/../Stubs/' . $stubName;
    }
}
