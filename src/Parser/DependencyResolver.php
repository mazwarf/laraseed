<?php

namespace Azwar\Laraseed\Parser;

use Illuminate\Support\Str;

/**
 * DependencyResolver — Modul 3
 *
 * Menerima output dari MigrationParser (array skema tabel) dan:
 *
 *  1. Membangun dependency graph antar tabel berdasarkan foreign key.
 *  2. Jika referenced_table bernilai null tetapi foreign_key bernilai true,
 *     menebak nama tabel tujuan dari nama kolom (misal: user_id → users).
 *  3. Mengurutkan tabel dengan Kahn's Topological Sort sehingga tabel parent
 *     selalu muncul sebelum tabel child → urutan yang aman untuk seeding.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ Contoh Output resolve()                                                 │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ ['roles', 'users', 'categories', 'posts', 'comments']                   │
 * │  ↑ parent dulu             ↑ child belakangan                           │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Contoh pemakaian:
 *
 *   $schema   = (new MigrationParser())->parseDirectory(database_path('migrations'));
 *   $resolver = new DependencyResolver();
 *
 *   $order   = $resolver->resolve($schema);          // ['roles','users','posts', …]
 *   $details = $resolver->resolveWithDetails($schema); // + metadata guessing & cycle
 */
class DependencyResolver
{
    // -------------------------------------------------------------------------
    // State yang diisi saat resolve() / buildDependencyGraph() dipanggil
    // -------------------------------------------------------------------------

    /**
     * Daftar dependensi yang berhasil diselesaikan, keyed by table name.
     *
     * @var array<string, string[]>
     */
    private array $resolvedDependencies = [];

    /**
     * Daftar kolom yang nama tabel tujuannya harus di-guess.
     *
     * @var array<int, array<string, string>>
     */
    private array $guessLog = [];

    /**
     * Tabel yang membentuk siklus (circular dependency) — tidak bisa di-sort.
     *
     * @var string[]
     */
    private array $cyclicTables = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Resolve dan kembalikan urutan tabel yang aman untuk seeding.
     *
     * @param  array<string, array<string, mixed>> $schema  Output MigrationParser
     * @return string[]  Nama tabel diurutkan parent-first
     */
    public function resolve(array $schema): array
    {
        $this->reset();

        $graph = $this->buildDependencyGraph($schema);

        return $this->topologicalSort($graph);
    }

    /**
     * Sama dengan resolve() tetapi juga mengembalikan metadata lengkap.
     *
     * @param  array<string, array<string, mixed>> $schema
     * @return array{
     *   order: string[],
     *   dependency_graph: array<string, string[]>,
     *   guessed_dependencies: array<int, array<string, string>>,
     *   cyclic_tables: string[],
     * }
     */
    public function resolveWithDetails(array $schema): array
    {
        $this->reset();

        $graph = $this->buildDependencyGraph($schema);
        $order = $this->topologicalSort($graph);

        return [
            'order'                => $order,
            'dependency_graph'     => $graph,
            'guessed_dependencies' => $this->guessLog,
            'cyclic_tables'        => $this->cyclicTables,
        ];
    }

    /**
     * Bangun dependency graph dari skema yang sudah di-parse.
     *
     * Setiap entry: tableName => [tableItDependsOn, …]
     *
     * @param  array<string, array<string, mixed>> $schema
     * @return array<string, string[]>
     */
    public function buildDependencyGraph(array $schema): array
    {
        $knownTables = array_keys($schema);
        $graph       = [];

        foreach ($schema as $tableName => $tableData) {
            $deps = [];

            foreach ($tableData['columns'] as $column) {
                // Hanya kolom yang merupakan foreign key
                if (empty($column['foreign_key'])) {
                    continue;
                }

                $referenced = $column['referenced_table'];

                // ── Guessing Logic ───────────────────────────────────────────
                // Jika referenced_table null, coba tebak dari nama kolom.
                if ($referenced === null) {
                    $guessed = $this->guessTableName($column['column'] ?? '');

                    if ($guessed !== null) {
                        // Hanya masukkan sebagai dependensi jika tabel tersebut
                        // memang ada dalam skema (hindari phantom dependency).
                        if (in_array($guessed, $knownTables, true)) {
                            $referenced = $guessed;
                        }

                        // Catat hasil guessing untuk transparansi
                        $this->guessLog[] = [
                            'table'          => $tableName,
                            'column'         => $column['column'] ?? '?',
                            'guessed_table'  => $guessed,
                            'found_in_schema'=> $referenced === $guessed ? 'yes' : 'no',
                        ];
                    }
                }

                // Tambahkan ke dependency hanya jika valid dan bukan self-reference
                if ($referenced !== null && $referenced !== $tableName) {
                    $deps[] = $referenced;

                    $this->resolvedDependencies[$tableName][] = $referenced;
                }
            }

            $graph[$tableName] = array_values(array_unique($deps));
        }

        return $graph;
    }

    /**
     * Tebak nama tabel dari nama kolom foreign key.
     *
     * Algoritma:
     *   1. Kolom harus diakhiri dengan _id  (contoh: user_id, category_id).
     *   2. Ambil kata sebelum _id sebagai kata benda singular.
     *   3. Pluralkan menggunakan Illuminate\Support\Str::plural().
     *
     * Contoh:
     *   user_id         → user  → users
     *   category_id     → category → categories
     *   role_id         → role  → roles
     *   product_type_id → product_type → product_types
     *
     * @return string|null  null jika kolom tidak berakhiran _id
     */
    public function guessTableName(string $columnName): ?string
    {
        if (!str_ends_with($columnName, '_id')) {
            return null;
        }

        // Hapus suffix '_id' (3 karakter)
        $singular = substr($columnName, 0, -3);

        if ($singular === '') {
            return null;
        }

        // Gunakan pluralizer bawaan Laravel untuk akurasi tinggi
        return Str::plural($singular);
    }

    // -------------------------------------------------------------------------
    // Getters (untuk inspeksi setelah resolve dipanggil)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string[]>
     */
    public function getResolvedDependencies(): array
    {
        return $this->resolvedDependencies;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getGuessLog(): array
    {
        return $this->guessLog;
    }

    /**
     * @return string[]
     */
    public function getCyclicTables(): array
    {
        return $this->cyclicTables;
    }

    // -------------------------------------------------------------------------
    // Topological Sort — Kahn's Algorithm
    // -------------------------------------------------------------------------

    /**
     * Kahn's Algorithm (BFS-based topological sort).
     *
     * Terminologi:
     *   - "inDegree[T]"  = jumlah tabel lain yang harus di-seed sebelum T.
     *   - "adj[T]"       = daftar tabel yang bergantung pada T.
     *
     * Langkah:
     *   1. Hitung inDegree tiap tabel.
     *   2. Masukkan semua tabel ber-inDegree 0 ke dalam antrian (queue).
     *   3. Ambil tabel dari antrian, tambahkan ke hasil.
     *   4. Kurangi inDegree semua tabel yang bergantung padanya;
     *      jika mencapai 0, masukkan ke antrian.
     *   5. Ulangi hingga antrian kosong.
     *   6. Jika ada tabel tersisa → siklus terdeteksi.
     *
     * Tabel dalam level yang sama diurutkan alfabetis agar output deterministik.
     *
     * @param  array<string, string[]> $graph  tableName => [dependsOn, …]
     * @return string[]
     */
    private function topologicalSort(array $graph): array
    {
        // Tambahkan tabel yang hanya muncul sebagai dependensi (bukan sebagai key)
        // agar semua node terwakili dalam graph.
        foreach ($graph as $deps) {
            foreach ($deps as $dep) {
                if (!array_key_exists($dep, $graph)) {
                    $graph[$dep] = [];
                }
            }
        }

        // ── Bangun inDegree dan adjacency list (reversed direction) ──────────
        $inDegree = array_fill_keys(array_keys($graph), 0);
        $adj      = array_fill_keys(array_keys($graph), []);

        foreach ($graph as $table => $deps) {
            foreach ($deps as $dep) {
                // dep harus selesai sebelum table → dep memberi "beban" ke table
                $inDegree[$table]++;
                $adj[$dep][] = $table;
            }
        }

        // ── Inisialisasi antrian dengan tabel tanpa dependensi ───────────────
        $queue = [];
        foreach ($inDegree as $table => $degree) {
            if ($degree === 0) {
                $queue[] = $table;
            }
        }

        // Urutkan alfabetis untuk output deterministik
        sort($queue);

        $result = [];

        // ── BFS ──────────────────────────────────────────────────────────────
        while (!empty($queue)) {
            // Ambil elemen pertama (FIFO)
            $current  = array_shift($queue);
            $result[] = $current;

            // Kumpulkan tetangga yang inDegree-nya menjadi 0 setelah kita proses
            $newlyFree = [];
            foreach ($adj[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $newlyFree[] = $dependent;
                }
            }

            // Urutkan alfabetis untuk determinisme di setiap level
            sort($newlyFree);
            array_push($queue, ...$newlyFree);
        }

        // ── Deteksi siklus ───────────────────────────────────────────────────
        // Jika count(result) < count(graph) artinya ada cycle.
        if (count($result) < count($graph)) {
            $cyclic = array_values(array_diff(array_keys($graph), $result));
            sort($cyclic);

            $this->cyclicTables = $cyclic;

            // Best-effort: tambahkan tetap di akhir agar seeder bisa generate
            array_push($result, ...$cyclic);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function reset(): void
    {
        $this->resolvedDependencies = [];
        $this->guessLog             = [];
        $this->cyclicTables         = [];
    }
}
