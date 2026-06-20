<?php

/**
 * Test untuk DependencyResolver (Modul 3).
 *
 * Jalankan:
 *   php tests/dependency_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Azwar\AutoGenerator\Parser\MigrationParser;
use Azwar\AutoGenerator\Parser\DependencyResolver;

// ─── 1. Buat file migrasi dummy ──────────────────────────────────────────────

$dummyMigrations = [

    // Migrasi 1: roles (tidak punya FK → independent)
    'roles' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }
};
PHP,

    // Migrasi 2: users (depends on roles via role_id — referenced_table EXPLICIT)
    'users' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->foreignId('role_id')->constrained('roles');
            $table->timestamps();
        });
    }
};
PHP,

    // Migrasi 3: categories (tidak punya FK → independent)
    'categories' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
};
PHP,

    // Migrasi 4: posts (depends on users via user_id — referenced_table NULL, perlu guess)
    //                   (depends on categories via category_id — EXPLICIT)
    'posts' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->foreignId('user_id')->constrained();         // referenced_table = null → guess "users"
            $table->foreignId('category_id')->constrained('categories'); // referenced_table = "categories"
            $table->timestamps();
        });
    }
};
PHP,

    // Migrasi 5: comments (depends on users & posts — referenced_table NULL, perlu guess)
    'comments' => <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->foreignId('user_id')->constrained();         // guess "users"
            $table->foreignId('post_id')->constrained();         // guess "posts"
            $table->timestamps();
        });
    }
};
PHP,
];

// ─── 2. Tulis ke file temp & parse ───────────────────────────────────────────

$tmpDir = sys_get_temp_dir() . '/dep_test_' . uniqid();
mkdir($tmpDir);

$i = 1;
foreach ($dummyMigrations as $name => $code) {
    $filename = sprintf('%04d_create_%s_table.php', $i++, $name);
    file_put_contents($tmpDir . '/' . $filename, $code);
}

$parser = new MigrationParser();
$schema = $parser->parseDirectory($tmpDir);

echo "=== [1] Schema hasil MigrationParser ===\n";
foreach ($schema as $table => $info) {
    $fkCols = array_filter($info['columns'], fn($c) => $c['foreign_key']);
    $fkList = implode(', ', array_column($fkCols, 'column'));
    echo "  - {$table}" . ($fkList ? " (FK: {$fkList})" : " (no FK)") . "\n";
}

// ─── 3. Resolve dependencies ─────────────────────────────────────────────────

$resolver = new DependencyResolver();
$details  = $resolver->resolveWithDetails($schema);

echo "\n=== [2] Dependency Graph ===\n";
foreach ($details['dependency_graph'] as $table => $deps) {
    $depsStr = empty($deps) ? '—' : implode(', ', $deps);
    echo "  {$table}  →  [{$depsStr}]\n";
}

echo "\n=== [3] Guessed Dependencies ===\n";
if (empty($details['guessed_dependencies'])) {
    echo "  (tidak ada)\n";
} else {
    foreach ($details['guessed_dependencies'] as $g) {
        $status = $g['found_in_schema'] === 'yes' ? '✅ ditemukan' : '⚠️  tidak ada di skema';
        echo "  {$g['table']}.{$g['column']} → \"{$g['guessed_table']}\" ({$status})\n";
    }
}

echo "\n=== [4] Cyclic Tables ===\n";
echo empty($details['cyclic_tables'])
    ? "  ✅ Tidak ada circular dependency\n"
    : "  ⚠️  Siklus terdeteksi: " . implode(', ', $details['cyclic_tables']) . "\n";

echo "\n=== [5] Urutan Seeding (aman) ===\n";
foreach ($details['order'] as $i => $table) {
    echo "  " . ($i + 1) . ". {$table}\n";
}

// ─── 6. Assertions ───────────────────────────────────────────────────────────

$order = $details['order'];

$pos = array_flip($order);

assert(isset($pos['roles']),      "FAIL: roles harus ada di urutan");
assert(isset($pos['users']),      "FAIL: users harus ada di urutan");
assert(isset($pos['posts']),      "FAIL: posts harus ada di urutan");
assert(isset($pos['categories']), "FAIL: categories harus ada di urutan");
assert(isset($pos['comments']),   "FAIL: comments harus ada di urutan");

// roles harus sebelum users (users bergantung pada roles)
assert($pos['roles'] < $pos['users'],
    "FAIL: roles harus sebelum users");

// users harus sebelum posts (posts bergantung pada users)
assert($pos['users'] < $pos['posts'],
    "FAIL: users harus sebelum posts");

// categories harus sebelum posts
assert($pos['categories'] < $pos['posts'],
    "FAIL: categories harus sebelum posts");

// posts harus sebelum comments (comments bergantung pada posts)
assert($pos['posts'] < $pos['comments'],
    "FAIL: posts harus sebelum comments");

// users harus sebelum comments
assert($pos['users'] < $pos['comments'],
    "FAIL: users harus sebelum comments");

// Pastikan guessing bekerja
$guessedTables = array_column($details['guessed_dependencies'], 'guessed_table');
assert(in_array('users', $guessedTables), "FAIL: harus ada guessing ke 'users'");
assert(in_array('posts', $guessedTables), "FAIL: harus ada guessing ke 'posts'");

assert(empty($details['cyclic_tables']), "FAIL: tidak boleh ada cyclic dependency");

echo "\n✅ Semua assertions passed!\n";

// ─── Cleanup ─────────────────────────────────────────────────────────────────
array_map('unlink', glob($tmpDir . '/*.php'));
rmdir($tmpDir);
