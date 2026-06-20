<?php

namespace Azwar\AutoGenerator\Parser;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * MigrationNodeVisitor
 *
 * Walks the AST of a Laravel migration file and extracts table-schema
 * information from every Schema::create() call it finds.
 *
 * Each Schema::create() produces one entry in $tables with the shape:
 *
 *   [
 *     'table'       => 'users',
 *     'columns'     => [
 *       [
 *         'column'           => 'email',
 *         'type'             => 'string',
 *         'foreign_key'      => false,
 *         'referenced_table' => null,
 *         'nullable'         => false,
 *         'unique'           => true,
 *         'modifiers'        => ['unique'],
 *       ],
 *       ...
 *     ],
 *     'source_file' => '/absolute/path/to/migration.php',
 *   ]
 *
 * Usage (handled internally by MigrationParser):
 *
 *   $visitor = new MigrationNodeVisitor();
 *   $visitor->setSourceFile($filePath);
 *   $traverser->addVisitor($visitor);
 *   $traverser->traverse($ast);
 *   $tables = $visitor->getTables();
 *   $visitor->reset();
 */
class MigrationNodeVisitor extends NodeVisitorAbstract
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /**
     * Column helpers that expand into multiple internal columns (e.g. created_at
     * + updated_at) → we skip them rather than emit a single broken record.
     */
    private const SKIP_TYPES = [
        'timestamps',
        'timestampsTz',
        'nullableTimestamps',
        'nullableTimestampsTz',
    ];

    /**
     * Helpers that accept no column-name argument but imply a well-known name.
     *
     * @var array<string, string>
     */
    private const IMPLICIT_COLUMN_NAMES = [
        'id'            => 'id',
        'rememberToken' => 'remember_token',
        'softDeletes'   => 'deleted_at',
        'softDeletesTz' => 'deleted_at',
    ];

    /**
     * Blueprint method names that mark a column as a foreign key.
     */
    private const FOREIGN_KEY_TYPES = [
        'foreignId',
        'foreignUuid',
        'foreignUlid',
        'foreign',
    ];

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var array<string, array<string, mixed>> Accumulated table definitions */
    private array $tables = [];

    /** Absolute path of the file currently being analysed */
    private ?string $sourceFile = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function setSourceFile(string $path): void
    {
        $this->sourceFile = $path;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /** Reset state between files so the visitor can be reused. */
    public function reset(): void
    {
        $this->tables     = [];
        $this->sourceFile = null;
    }

    // -------------------------------------------------------------------------
    // NodeVisitorAbstract override
    // -------------------------------------------------------------------------

    /**
     * Called once for every node in the AST.
     *
     * When a Schema::create() StaticCall is found we extract all column
     * definitions directly from the closure body, then tell the traverser to
     * skip the children of this node (we already handled them manually).
     */
    public function enterNode(Node $node): null|int|Node
    {
        if (!($node instanceof StaticCall) || !$this->isSchemaCreate($node)) {
            return null;
        }

        $tableName = $this->extractTableName($node);
        $closure   = $this->extractClosure($node);

        if ($tableName !== null && $closure !== null) {
            $blueprintVar = $this->extractBlueprintVarName($closure);
            $columns      = $this->extractColumnsFromClosure($closure, $blueprintVar);

            $this->tables[$tableName] = [
                'table'       => $tableName,
                'columns'     => $columns,
                'source_file' => $this->sourceFile,
            ];
        }

        // We have already processed this subtree manually.
        // Prevent the traverser from visiting child nodes again.
        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }

    // -------------------------------------------------------------------------
    // Private — Schema::create detection
    // -------------------------------------------------------------------------

    private function isSchemaCreate(StaticCall $node): bool
    {
        if (!$node->name instanceof Identifier || $node->name->name !== 'create') {
            return false;
        }

        if (!$node->class instanceof Name) {
            return false;
        }

        // Accept both the short alias and the FQCN (after NameResolver runs)
        return in_array($node->class->toString(), [
            'Schema',
            'Illuminate\\Support\\Facades\\Schema',
        ], true);
    }

    private function extractTableName(StaticCall $node): ?string
    {
        if (isset($node->args[0]) && $node->args[0]->value instanceof String_) {
            return $node->args[0]->value->value;
        }
        return null;
    }

    private function extractClosure(StaticCall $node): ?Closure
    {
        if (isset($node->args[1]) && $node->args[1]->value instanceof Closure) {
            return $node->args[1]->value;
        }
        return null;
    }

    private function extractBlueprintVarName(Closure $closure): string
    {
        // Use the name of the first parameter, e.g. "table" in function (Blueprint $table)
        if (!empty($closure->params)) {
            $param = $closure->params[0];
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                return $param->var->name;
            }
        }
        return 'table'; // safe default
    }

    // -------------------------------------------------------------------------
    // Private — column extraction
    // -------------------------------------------------------------------------

    /**
     * Iterate over every statement in the closure body and try to parse it as
     * a Blueprint column-definition call.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractColumnsFromClosure(Closure $closure, string $blueprintVar): array
    {
        $columns = [];

        foreach ($closure->stmts as $stmt) {
            // We only care about expression statements (no if/foreach etc.)
            if (!$stmt instanceof Expression) {
                continue;
            }

            $column = $this->extractColumnFromExpr($stmt->expr, $blueprintVar);

            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * Unwind a (possibly chained) MethodCall expression and return column metadata.
     *
     * Handles all of these patterns:
     *   $table->string('name')
     *   $table->string('email')->unique()
     *   $table->string('bio')->nullable()->default(null)
     *   $table->foreignId('user_id')->constrained()
     *   $table->foreignId('category_id')->constrained('categories')
     *
     * @return array<string, mixed>|null  null when the expr is not a Blueprint call
     */
    private function extractColumnFromExpr(Node\Expr $expr, string $blueprintVar): ?array
    {
        // --- 1. Unwind the method-call chain ----------------------------------
        //
        // Given:  $table->string('email')->unique()->nullable()
        //
        // The AST is right-recursive:
        //   MethodCall(nullable,
        //     MethodCall(unique,
        //       MethodCall(string, Variable(table), ['email'])
        //     )
        //   )
        //
        // We collect nodes outermost-first, then reverse so index 0 = root call.

        $chain   = [];
        $current = $expr;

        while ($current instanceof MethodCall) {
            $chain[] = $current;
            $current = $current->var;
        }

        // The very root of the chain must be the Blueprint variable
        if (!$current instanceof Variable || $current->name !== $blueprintVar) {
            return null;
        }

        if (empty($chain)) {
            return null;
        }

        // index 0 = direct call on $blueprintVar (column-type method)
        $chain    = array_reverse($chain);
        $rootCall = $chain[0];
        $mods     = array_slice($chain, 1); // modifier calls (.unique(), .nullable() …)

        // --- 2. Extract type (method name) ------------------------------------

        $type = $rootCall->name instanceof Identifier ? $rootCall->name->name : null;

        if ($type === null) {
            return null;
        }

        // Skip pseudo-column helpers that expand to multiple columns
        if (in_array($type, self::SKIP_TYPES, true)) {
            return null;
        }

        // --- 3. Extract column name -------------------------------------------

        $column = null;

        // Most column methods → first argument is the column name
        if (isset($rootCall->args[0]) && $rootCall->args[0]->value instanceof String_) {
            $column = $rootCall->args[0]->value->value;
        }

        // Implicit-name helpers (id(), softDeletes(), rememberToken() …)
        if ($column === null && array_key_exists($type, self::IMPLICIT_COLUMN_NAMES)) {
            $column = self::IMPLICIT_COLUMN_NAMES[$type];
        }

        // --- 4. Collect modifier names ----------------------------------------

        $modifierNames = [];
        foreach ($mods as $modCall) {
            if ($modCall->name instanceof Identifier) {
                $modifierNames[] = $modCall->name->name;
            }
        }

        // --- 5. Foreign-key detection ----------------------------------------

        $isForeignKey = in_array($type, self::FOREIGN_KEY_TYPES, true)
            || in_array('constrained', $modifierNames, true);

        // Check whether constrained() was called with an explicit table name
        $referencedTable = null;
        foreach ($mods as $modCall) {
            if (
                $modCall->name instanceof Identifier
                && $modCall->name->name === 'constrained'
                && isset($modCall->args[0])
                && $modCall->args[0]->value instanceof String_
            ) {
                $referencedTable = $modCall->args[0]->value->value;
                break;
            }
        }

        // --- 6. Return structured column record ------------------------------

        return [
            'column'           => $column,
            'type'             => $type,
            'foreign_key'      => $isForeignKey,
            'referenced_table' => $referencedTable,
            'nullable'         => in_array('nullable', $modifierNames, true),
            'unique'           => in_array('unique', $modifierNames, true),
            'modifiers'        => $modifierNames,
        ];
    }
}
