<?php

use Illuminate\Support\Facades\Http;

echo "\n⏳ Mengambil daftar model dari Google Gemini API...\n";

$apiKey = env('GEMINI_API_KEY', '');

if (empty($apiKey)) {
    echo "❌ API Key tidak ditemukan!\n";
    exit(1);
}

$response = Http::get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");

if (!$response->successful()) {
    echo "❌ Gagal mengambil daftar model: " . $response->body() . "\n";
    exit(1);
}

$data = $response->json();
$found = [];

foreach ($data['models'] ?? [] as $model) {
    if (in_array('generateContent', $model['supportedGenerationMethods'] ?? [])) {
        $found[] = str_replace('models/', '', $model['name']);
    }
}

echo "✅ Berhasil. Gunakan salah satu model terbaru di bawah ini untuk .env Anda:\n";
echo "────────────────────────────────────────\n";
foreach ($found as $name) {
    echo "  - {$name}\n";
}
echo "────────────────────────────────────────\n\n";
