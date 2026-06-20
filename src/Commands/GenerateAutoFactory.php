<?php

namespace Azwar\Laraseed\Commands;

use Illuminate\Console\Command;

/**
 * GenerateAutoFactory Command
 *
 * Artisan command: make:auto-factory
 *
 * Parses an Eloquent Model using static analysis (nikic/php-parser)
 * and automatically generates a type-safe Model Factory based on
 * the detected column definitions and casts.
 *
 * Usage:
 *   php artisan make:auto-factory {ModelName}
 *
 * Example:
 *   php artisan make:auto-factory User
 *   php artisan make:auto-factory "App\Models\Post"
 */
class GenerateAutoFactory extends Command
{
    /**
     * The name and signature of the Artisan console command.
     *
     * @var string
     */
    protected $signature = 'make:laraseed
                            {table? : Nama tabel spesifik (opsional)}
                            {--F|force : Overwrite the factory if it already exists}
                            {--C|count= : Jumlah data dummy untuk setiap tabel}
                            {--factory-only : Hanya buat Factory saja}
                            {--seeder-only : Hanya buat Seeder saja}';

    /**
     * The console command description shown in `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Automatically generate Model Factories and Seeders by parsing migrations and using Gemini API';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $apiKey = config('laraseed.gemini_api_key');

        if (empty($apiKey)) {
            $this->error('Silakan isi GEMINI_API_KEY di .env terlebih dahulu!');
            return Command::FAILURE;
        }

        $this->info("🔍 Analisa skema database dengan MigrationParser...");

        // 1. Ambil metadata tabel dari file migrasi
        $parser = new \Azwar\Laraseed\Parser\MigrationParser();
        $schema = $parser->parseDirectory(database_path('migrations'));

        if (empty($schema)) {
            $this->warn('⚠️  Tidak ada skema tabel yang ditemukan di database/migrations.');
            return Command::SUCCESS;
        }

        $this->info("🔗 Menyusun urutan tabel berdasarkan dependensi...");

        // 2. Tentukan urutan tabel menggunakan Topological Sort
        $resolver = new \Azwar\Laraseed\Parser\DependencyResolver();
        $details = $resolver->resolveWithDetails($schema);
        $orderedTables = $details['order'];

        if (!empty($details['cyclic_tables'])) {
            $this->warn('⚠️  Terdeteksi circular dependency pada tabel: ' . implode(', ', $details['cyclic_tables']));
        }

        $force = $this->option('force');
        $count = $this->option('count');
        $factoryOnly = $this->option('factory-only');
        $seederOnly  = $this->option('seeder-only');
        $specificTable = $this->argument('table');

        if ($factoryOnly && $seederOnly) {
            $this->error('⚠️  Tidak bisa menggunakan --factory-only dan --seeder-only secara bersamaan!');
            return Command::FAILURE;
        }

        if (!$factoryOnly && !$count) {
            $count = $this->ask('Berapa banyak data dummy yang ingin Anda buat untuk setiap tabel?', 10);
        } elseif ($factoryOnly) {
            $count = 10;
        }

        $gemini = new \Azwar\Laraseed\Generator\GeminiCodeGenerator();
        $fileWriter = new \Azwar\Laraseed\Generator\FileGenerator();
        $seederGenerator = new \Azwar\Laraseed\Generator\SeederGenerator();

        $this->info("🚀 Memilah tabel target...");

        // 3. Filter tabel yang belum ada factory-nya dan pastikan Model-nya eksis
        $targetTables = [];
        $modelNamespace = config('laraseed.model_namespace', 'App\\Models');

        foreach ($orderedTables as $tableName) {
            if ($specificTable && $tableName !== $specificTable) {
                continue;
            }

            $modelName = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($tableName));
            
            // Verifikasi apakah Model PHP-nya benar-benar ada di aplikasi
            $modelClass = $modelNamespace . '\\' . $modelName;
            if (!class_exists($modelClass)) {
                $this->info("  ⏭️  Melewati {$tableName}: Class Model [{$modelClass}] tidak ditemukan (Tabel Sistem).");
                continue;
            }

            $filePath = base_path(config('laraseed.output.factory', 'database/factories')) . '/' . $modelName . 'Factory.php';
            
            if (\Illuminate\Support\Facades\File::exists($filePath) && !$force) {
                $this->info("  ⏭️  Melewati {$tableName}: Factory sudah ada. (Gunakan --force)");
                continue;
            }
            
            // Masukkan ke array target batching
            if (isset($schema[$tableName])) {
                $targetTables[$tableName] = $schema[$tableName];
            }
        }

        if (empty($targetTables)) {
            $this->info("✅ Semua target sudah ada atau tidak ada tabel yang cocok.");
            return Command::SUCCESS;
        }

        $successCount = 0;
        $seederClasses = [];

        try {
            if ($seederOnly) {
                $this->info("🤖 Mode Seeder-Only Aktif: Bypass pemanggilan AI Gemini untuk efisiensi...");
                $this->output->progressStart(count($targetTables));
                
                foreach ($targetTables as $tableName => $tableData) {
                    $modelName = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($tableName));
                    $seederClasses[] = $seederGenerator->generateSeeder($modelName, (int)$count, $force);
                    $successCount++;
                    $this->output->progressAdvance();
                }
                $this->output->progressFinish();
            } else {
                $this->info("🤖 Menghubungi Gemini API untuk " . count($targetTables) . " tabel sekaligus (Batching Mode)...");
                // 4. Lakukan 1x Request API untuk SEMUA tabel target
                $batchResults = $gemini->generateBatch($targetTables);
                
                // 5. Tulis ke file hasil dari JSON Array tersebut
                $this->output->progressStart(count($targetTables));
                foreach ($targetTables as $tableName => $tableData) {
                    $modelName = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($tableName));
                    
                    if (!isset($batchResults[$tableName])) {
                        $this->warn("\n⚠️  AI mengabaikan tabel: {$tableName}");
                        $this->output->progressAdvance();
                        continue;
                    }

                    $definition = $batchResults[$tableName];
                    
                    if (empty(trim($definition))) {
                        $this->warn("\n⚠️  Definisi kosong untuk tabel: {$tableName}");
                    } else {
                        $fileWriter->generateFactory($modelName, $definition, $force);
                        if (!$factoryOnly) {
                            $seederClasses[] = $seederGenerator->generateSeeder($modelName, (int)$count, $force);
                        }
                        $successCount++;
                    }
                    
                    $this->output->progressAdvance();
                }
                $this->output->progressFinish();
            }
            
            // 6. Registrasi Master Seeder Auto-Injector
            if (!empty($seederClasses) && !$factoryOnly) {
                $this->injectToDatabaseSeeder($seederClasses);
            }
            
        } catch (\Exception $e) {
            $this->error("\n❌ Gagal saat menghubungi API atau Parsing JSON: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        $typeMessage = "Factory dan Seeder";
        if ($factoryOnly) $typeMessage = "Factory";
        if ($seederOnly) $typeMessage = "Seeder";

        $this->info("\n✅ Berhasil membuat {$typeMessage} untuk {$successCount} tabel. Silakan jalankan 'php artisan db:seed' untuk mengisi database!");

        return Command::SUCCESS;
    }

    /**
     * Injeksi array seeder ke dalam DatabaseSeeder.php
     */
    protected function injectToDatabaseSeeder(array $seederClasses): void
    {
        $databaseSeederPath = base_path('database/seeders/DatabaseSeeder.php');
        
        if (!\Illuminate\Support\Facades\File::exists($databaseSeederPath)) {
            $this->info("\n🏗️  DatabaseSeeder.php tidak ditemukan. Membuat ulang secara otomatis...");
            
            $seederDir = dirname($databaseSeederPath);
            if (!\Illuminate\Support\Facades\File::isDirectory($seederDir)) {
                \Illuminate\Support\Facades\File::makeDirectory($seederDir, 0755, true);
            }

            $defaultContent = "<?php\n\nnamespace Database\Seeders;\n\nuse Illuminate\Database\Seeder;\n\nclass DatabaseSeeder extends Seeder\n{\n    /**\n     * Seed the application's database.\n     */\n    public function run(): void\n    {\n        //\n    }\n}\n";
            \Illuminate\Support\Facades\File::put($databaseSeederPath, $defaultContent);
        }

        $content = \Illuminate\Support\Facades\File::get($databaseSeederPath);
        
        // Format isi $this->call([])
        $calls = array_map(fn($s) => "            {$s}::class,", $seederClasses);
        $callString = "        \$this->call([\n" . implode("\n", $calls) . "\n        ]);";

        // Regex untuk me-replace isi dari method run() dengan pemanggilan $this->call
        $newContent = preg_replace(
            '/(public\s+function\s+run\s*\([^)]*\)\s*:\s*void\s*\{)(.*?)(\n\s*\})/s',
            "$1\n{$callString}$3",
            $content
        );

        if ($newContent === null) {
            $this->warn("\n⚠️  Gagal melakukan injeksi ke DatabaseSeeder.php. Pastikan format method run(): void standar.");
        } elseif ($newContent === $content) {
            $this->info("\n✅ Injeksi ke DatabaseSeeder.php dilewati karena struktur sudah up-to-date (tidak ada perubahan).");
        } else {
            \Illuminate\Support\Facades\File::put($databaseSeederPath, $newContent);
            $this->info("\n💉 Injeksi " . count($seederClasses) . " Seeder ke DatabaseSeeder.php berhasil!");
        }
    }
}
