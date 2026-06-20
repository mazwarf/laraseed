<?php

namespace Azwar\Laraseed\Generator;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GeminiCodeGenerator — Modul 4
 *
 * Menggunakan Google Gemini 1.5 Flash untuk menghasilkan isi method
 * definition() Laravel Factory secara otomatis, berdasarkan skema kolom
 * yang dihasilkan oleh MigrationParser (Modul 2).
 *
 * Alur kerja:
 *   1. Terima $tableName + $columns dari MigrationParser.
 *   2. Bangun prompt spesifik → kirim ke Gemini API via Http facade.
 *   3. Sanitasi output → kembalikan PHP code siap pakai.
 *
 * Contoh pemakaian (dalam Artisan command di Laravel):
 *
 *   $generator  = new GeminiCodeGenerator();
 *   $definition = $generator->generate('products', $columns);
 *   // Hasil: string berisi baris 'column' => $this->faker->...(), siap ditempel
 */
class GeminiCodeGenerator
{
    /** Base URL Gemini generateContent API */
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Kolom yang dikelola Laravel secara otomatis — tidak perlu di-generate Faker.
     */
    private const SKIP_COLUMNS = [
        'id', 'created_at', 'updated_at', 'deleted_at', 'remember_token',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate isi definition() untuk BANYAK tabel sekaligus (Batching).
     *
     * @param  array<string, array>  $tablesSchema  Output dari MigrationParser berisikan nama tabel dan kolomnya.
     * @return array<string, string> Key: nama tabel, Value: string PHP code isi definition().
     *
     * @throws RuntimeException  Jika API key kosong, response gagal, atau format JSON tidak valid.
     */
    public function generateBatch(array $tablesSchema): array
    {
        $apiKey = env('GEMINI_API_KEY', '');

        if (empty($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY belum diset. Tambahkan di .env: GEMINI_API_KEY=your-key');
        }

        // Jika array kosong, tidak perlu panggil API
        if (empty($tablesSchema)) {
            return [];
        }

        $prompt = $this->buildPromptBatch($tablesSchema);
        $raw    = $this->callApi($apiKey, $prompt);

        return $this->sanitizeJson($raw);
    }

    /**
     * Susun prompt batching agar Gemini mengembalikan mapping JSON.
     */
    private function buildPromptBatch(array $tablesSchema): string
    {
        $schemaText = "";
        
        foreach ($tablesSchema as $tableName => $tableData) {
            $columns = $tableData['columns'] ?? [];
            $filtered = array_filter(
                $columns,
                fn(array $c) => !in_array($c['column'] ?? '', self::SKIP_COLUMNS, true)
            );

            if (empty($filtered)) continue;

            $schemaText .= "Table: {$tableName}\n";
            foreach ($filtered as $col) {
                $name   = $col['column'] ?? 'unknown';
                $type   = $col['type']   ?? 'string';
                $extras = [];
                if ($col['foreign_key'] ?? false) $extras[] = 'FK';
                $extrasStr = empty($extras) ? '' : ' [' . implode(',', $extras) . ']';
                $schemaText .= " - {$name} ({$type}){$extrasStr}\n";
            }
            $schemaText .= "\n";
        }

        return <<<PROMPT
You are an expert Laravel Factory code generator.

Here are multiple tables and their columns:
{$schemaText}

Your task:
Generate the key => value pairs for the Laravel Factory definition() method for EACH table above.

Strict rules:
1. You MUST output STRICT VALID JSON. Do not include markdown code blocks like ```json.
2. The JSON keys MUST be the exact table names.
3. The JSON values MUST be the pure PHP code string (key => value pairs) for that table's factory.
4. Value strings rules:
   - Use \$this->faker-> (e.g. \$this->faker->name())
   - End each line with a comma.
   - End each line with a comma.
   - For PHP Namespaces, do NOT use backslashes (\) as it breaks JSON. Instead, use double pipes (||).
   - Example Foreign Key: ||App||Models||User::factory()
   - Example class: ||Illuminate||Support||Str::random(60)
   - Do NOT wrap the string in [ ], return statements, or PHP tags.
   - You MUST escape newlines as \\n or put everything on a single line.

Example Output Format:
{
  "users": "'name' => \$this->faker->name(),\\n'email' => \$this->faker->safeEmail(),",
  "posts": "'title' => \$this->faker->sentence(),\\n'user_id' => ||App||Models||User::factory(),"
}

Return ONLY the JSON object now:
PROMPT;
    }

    // -------------------------------------------------------------------------
    // API Call
    // -------------------------------------------------------------------------

    private function callApi(string $apiKey, string $prompt, int $retries = 3): string
    {
        $model = config('laraseed.gemini_model', 'gemini-2.5-flash');
        $endpoint = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        // Atur schema JSON di payload. Kita override max token khusus batching
        // agar tidak terpotong (meskipun di config diset kecil).
        $payload = [
            'contents' => [
                [
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => [
                'temperature'     => (float) config('auto-generator.gemini.temperature', 0.1),
                'maxOutputTokens' => 8192, // Pastikan sangat besar untuk menampung JSON 8+ tabel
            ],
        ];

        for ($i = 0; $i < $retries; $i++) {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                if (($response->status() === 503 || $response->status() === 429) && $i < ($retries - 1)) {
                    sleep(5);
                    continue;
                }
                throw new RuntimeException("Gemini API error [{$response->status()}]: " . $response->body());
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if ($text === null) {
                throw new RuntimeException('Format respons Gemini tidak dikenal.');
            }

            // Validasi Truncation JSON: JSON Object yang valid harus selalu diakhiri dengan '}'
            $text = trim($text);
            $lastChar = substr($text, -1);
            if ($lastChar !== '}' && $i < ($retries - 1)) {
                // Tunggu sebentar lalu coba minta lagi
                sleep(2);
                continue;
            }

            return $text;
        }

        throw new RuntimeException('Gemini API gagal merespons setelah 3 kali mencoba.');
    }

    // -------------------------------------------------------------------------
    // Sanitizer
    // -------------------------------------------------------------------------

    /**
     * Bersihkan output mentah JSON dan parse menjadi Array PHP.
     */
    public function sanitizeJson(string $raw): array
    {
        $cleaned = trim($raw);

        // Hapus markdown jika LLM membangkang
        $cleaned = preg_replace('/^```(?:json)?\s*\n?/im', '', $cleaned);
        $cleaned = preg_replace('/^```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Gagal melakukan parse JSON dari output Gemini: " . json_last_error_msg() . "\n\nRaw Text:\n" . $cleaned);
        }

        // Kembalikan placeholder || menjadi backslash \
        foreach ($decoded as $table => $definition) {
            $decoded[$table] = str_replace('||', '\\', $definition);
        }

        return $decoded;
    }
}
