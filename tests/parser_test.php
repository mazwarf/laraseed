<?php

/**
 * Dummy migration file untuk menguji MigrationParser.
 *
 * Jalankan:
 *   php tests/parser_test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Azwar\AutoGenerator\Parser\MigrationParser;

// ─── 1. Buat file migrasi dummy di temp dir ──────────────────────────────────

$dummyMigration = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('bio')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('is_published')->default(false);
            $table->foreignId('user_id')->constrained();
            $table->foreignId('category_id')->constrained('categories');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('users');
    }
};
PHP;

$tmpFile = sys_get_temp_dir() . '/test_migration.php';
file_put_contents($tmpFile, $dummyMigration);

// ─── 2. Parse ────────────────────────────────────────────────────────────────

$parser = new MigrationParser();
$result = $parser->parseFile($tmpFile);

// ─── 3. Output ───────────────────────────────────────────────────────────────

echo "=== MigrationParser — Test Output ===\n\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// ─── 4. Basic assertions ─────────────────────────────────────────────────────

assert(isset($result['users']),  "FAIL: 'users' table not found");
assert(isset($result['posts']),  "FAIL: 'posts' table not found");

$usersColumns  = array_column($result['users']['columns'], null, 'column');
$postsColumns  = array_column($result['posts']['columns'], null, 'column');

assert($usersColumns['email']['unique'] === true,            "FAIL: email → unique");
assert($usersColumns['bio']['nullable'] === true,            "FAIL: bio → nullable");
assert($postsColumns['user_id']['foreign_key'] === true,     "FAIL: user_id → foreign_key");
assert($postsColumns['category_id']['referenced_table'] === 'categories',
                                                             "FAIL: category_id → referenced_table");
assert($postsColumns['deleted_at']['type'] === 'softDeletes',"FAIL: softDeletes" );

echo "\n✅ All assertions passed!\n";

// Cleanup
unlink($tmpFile);
