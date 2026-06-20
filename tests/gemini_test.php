<?php

/**
 * Test untuk GeminiCodeGenerator (Modul 4).
 *
 * Script ini dijalankan VIA ARTISAN TINKER dari folder laravel-test-app:
 *
 *   php artisan tinker --execute="require base_path('packages/azwar/laravel-auto-generator/tests/gemini_test.php');"
 *
 * Atau langsung dari root laravel-test-app:
 *
 *   php packages/azwar/laravel-auto-generator/tests/gemini_test.php
 *
 * Karena script ini berjalan di dalam konteks Laravel:
 *   - env('GEMINI_API_KEY') otomatis terbaca dari .env
 *   - Illuminate\Support\Facades\Http tersedia via service container
 */

use Azwar\AutoGenerator\Generator\GeminiCodeGenerator;

// ─── Data dummy: tabel 'products' ────────────────────────────────────────────

$tableName = 'products';

$columns = [
    ['column' => 'id',          'type' => 'id',        'foreign_key' => false, 'nullable' => false, 'unique' => false],
    ['column' => 'name',        'type' => 'string',    'foreign_key' => false, 'nullable' => false, 'unique' => false],
    ['column' => 'slug',        'type' => 'string',    'foreign_key' => false, 'nullable' => false, 'unique' => true],
    ['column' => 'description', 'type' => 'text',      'foreign_key' => false, 'nullable' => true,  'unique' => false],
    ['column' => 'price',       'type' => 'decimal',   'foreign_key' => false, 'nullable' => false, 'unique' => false],
    ['column' => 'stock',       'type' => 'integer',   'foreign_key' => false, 'nullable' => false, 'unique' => false],
    ['column' => 'is_active',   'type' => 'boolean',   'foreign_key' => false, 'nullable' => false, 'unique' => false],
    ['column' => 'image',       'type' => 'string',    'foreign_key' => false, 'nullable' => true,  'unique' => false],
    ['column' => 'user_id',     'type' => 'foreignId', 'foreign_key' => true,  'nullable' => false, 'unique' => false],
    ['column' => 'category_id', 'type' => 'foreignId', 'foreign_key' => true,  'nullable' => true,  'unique' => false],
    ['column' => 'created_at',  'type' => 'timestamp', 'foreign_key' => false, 'nullable' => true,  'unique' => false],
    ['column' => 'updated_at',  'type' => 'timestamp', 'foreign_key' => false, 'nullable' => true,  'unique' => false],
];

// ─── Jalankan Generator ───────────────────────────────────────────────────────

$generator = new GeminiCodeGenerator();

echo "\n=== Test GeminiCodeGenerator — Modul 4 ===\n";
echo "Tabel  : {$tableName}\n";
echo "Kolom  : " . implode(', ', array_column($columns, 'column')) . "\n";
echo "API Key: " . (empty(env('GEMINI_API_KEY')) ? '❌ TIDAK ADA' : '✅ ' . substr(env('GEMINI_API_KEY'), 0, 8) . '...') . "\n";
echo str_repeat('─', 60) . "\n\n";

try {
    echo "⏳ Menghubungi Gemini API...\n\n";

    $result = $generator->generate($tableName, $columns);

    echo "=== OUTPUT UNTUK definition() Factory ===\n\n";
    echo $result . "\n\n";

    // ─── Validasi ────────────────────────────────────────────────────────────
    echo str_repeat('─', 60) . "\n";
    echo "Validasi:\n";

    $checks = [
        ['desc' => 'Tidak ada markdown ```',    'pass' => !str_contains($result, '```')],
        ['desc' => 'Tidak ada tag <?php',        'pass' => !str_contains($result, '<?php')],
        ['desc' => 'Ada key => value pairs',     'pass' => str_contains($result, '=>')],
        ['desc' => "Kolom 'id' di-skip",         'pass' => !preg_match("/^\s*'id'\s*=>/m", $result)],
        ['desc' => "Kolom timestamps di-skip",   'pass' => !preg_match("/'created_at'\s*=>/", $result)],
        ['desc' => 'Foreign key pakai ::factory()','pass' => str_contains($result, '::factory()')],
    ];

    $allPassed = true;
    foreach ($checks as $check) {
        $icon = $check['pass'] ? '✅' : '❌';
        echo "  {$icon} {$check['desc']}\n";
        if (!$check['pass']) {
            $allPassed = false;
        }
    }

    echo "\n" . ($allPassed ? '✅ Semua validasi passed!' : '⚠️  Ada validasi yang gagal.') . "\n\n";

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n\n";
}
