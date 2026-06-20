<?php

namespace Azwar\Laraseed\Parser;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * MigrationParser
 *
 * Entry point for Module 2 — Reverse Engineering via AST.
 *
 * Parses one or many Laravel migration files and returns a structured
 * PHP array (JSON-like) describing every table defined via Schema::create().
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ Output shape                                                            │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ [                                                                       │
 * │   'users' => [                                                          │
 * │     'table'   => 'users',                                               │
 * │     'columns' => [                                                      │
 * │       ['column'=>'id',      'type'=>'id',       'foreign_key'=>false, …]│
 * │       ['column'=>'name',    'type'=>'string',   'foreign_key'=>false, …]│
 * │       ['column'=>'user_id', 'type'=>'foreignId','foreign_key'=>true,  …]│
 * │     ],                                                                  │
 * │     'source_file' => '/abs/path/to/0001_create_users_table.php',        │
 * │   ],                                                                    │
 * │   ...                                                                   │
 * │ ]                                                                       │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Two-pass design:
 *   Pass 1 — NameResolver   : turns short names (Schema) → FQCNs.
 *   Pass 2 — MigrationNodeVisitor : extracts table / column data.
 *
 * Example usage:
 *
 *   $parser = new MigrationParser();
 *
 *   // Single file
 *   $schema  = $parser->parseFile('/path/to/2024_01_01_create_users_table.php');
 *
 *   // Multiple files
 *   $schemas = $parser->parseFiles(['/path/to/file1.php', '/path/to/file2.php']);
 *
 *   // Whole directory (sorted by filename, i.e. chronological order)
 *   $schemas = $parser->parseDirectory(database_path('migrations'));
 *
 *   // Pretty JSON for debugging
 *   echo $parser->toJson([...]);
 */
class MigrationParser
{
    private \PhpParser\Parser $phpParser;
    private MigrationNodeVisitor $visitor;

    /** First traversal — resolves class names to FQCNs */
    private NodeTraverser $nameTraverser;

    /** Second traversal — extracts table/column data */
    private NodeTraverser $dataTraverser;

    public function __construct()
    {
        // createForHostVersion() targets the PHP version running right now,
        // which is exactly the PHP version the migrations were written for.
        $this->phpParser = (new ParserFactory())->createForHostVersion();

        $this->visitor = new MigrationNodeVisitor();

        // --- Pass 1: Resolve names -------------------------------------------
        $this->nameTraverser = new NodeTraverser();
        $this->nameTraverser->addVisitor(new NameResolver());

        // --- Pass 2: Extract schema data -------------------------------------
        $this->dataTraverser = new NodeTraverser();
        $this->dataTraverser->addVisitor($this->visitor);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Parse a single migration file.
     *
     * @return array<string, array<string, mixed>>  Keyed by table name.
     * @throws RuntimeException
     */
    public function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Migration file not found: {$filePath}");
        }

        $code = file_get_contents($filePath);

        if ($code === false) {
            throw new RuntimeException("Could not read migration file: {$filePath}");
        }

        // Parse source into an AST
        $ast = $this->phpParser->parse($code);

        if ($ast === null) {
            throw new RuntimeException("Failed to build AST for: {$filePath}");
        }

        // Pass 1 — resolve names (Schema → Illuminate\Support\Facades\Schema, etc.)
        $ast = $this->nameTraverser->traverse($ast);

        // Pass 2 — extract table definitions
        $absolutePath = realpath($filePath) ?: $filePath;
        $this->visitor->setSourceFile($absolutePath);
        $this->dataTraverser->traverse($ast);

        $tables = $this->visitor->getTables();

        // Reset so the visitor is clean for the next file
        $this->visitor->reset();

        return $tables;
    }

    /**
     * Parse multiple migration files and merge all table definitions.
     *
     * If two files define the same table name the last one wins — consistent
     * with how Laravel runs migrations in filename order.
     *
     * @param  string[]                             $filePaths
     * @return array<string, array<string, mixed>>
     * @throws RuntimeException
     */
    public function parseFiles(array $filePaths): array
    {
        $allTables = [];

        foreach ($filePaths as $filePath) {
            $tables    = $this->parseFile($filePath);
            $allTables = array_merge($allTables, $tables);
        }

        return $allTables;
    }

    /**
     * Scan a directory for Laravel migration files and parse all of them.
     *
     * Files are sorted alphabetically (by filename) so they are processed in
     * the same chronological order Laravel applies them (timestamp prefix).
     *
     * @return array<string, array<string, mixed>>
     * @throws RuntimeException
     */
    public function parseDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("Migration directory not found: {$directory}");
        }

        $pattern = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php';
        $files   = glob($pattern);

        if ($files === false || empty($files)) {
            return [];
        }

        // Chronological order is guaranteed by the YYYY_MM_DD_HHMMSS_ prefix
        sort($files);

        return $this->parseFiles($files);
    }

    /**
     * Parse files and return the result as a pretty-printed JSON string.
     *
     * Intended for debugging and inspection only — not for production use.
     *
     * @param  string[] $filePaths
     */
    public function toJson(array $filePaths): string
    {
        $tables = $this->parseFiles($filePaths);

        return (string) json_encode(
            $tables,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Parse a directory and return the result as a pretty-printed JSON string.
     */
    public function directoryToJson(string $directory): string
    {
        $tables = $this->parseDirectory($directory);

        return (string) json_encode(
            $tables,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
