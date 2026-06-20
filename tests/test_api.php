<?php

use Azwar\AutoGenerator\Generator\GeminiCodeGenerator;
use Illuminate\Support\Facades\Http;

$generator = new GeminiCodeGenerator();
$columns = [
    ['column' => 'id', 'type' => 'id'],
    ['column' => 'name', 'type' => 'string'],
    ['column' => 'total_jobs', 'type' => 'integer'],
    ['column' => 'pending_jobs', 'type' => 'integer'],
];

echo "Generating raw from Gemini...\n";

try {
    $reflection = new \ReflectionClass($generator);
    $method = $reflection->getMethod('buildPrompt');
    $method->setAccessible(true);
    $prompt = $method->invokeArgs($generator, ['job_batches', $columns]);
    
    $callApi = $reflection->getMethod('callApi');
    $callApi->setAccessible(true);
    
    $apiKey = env('GEMINI_API_KEY');
    $raw = $callApi->invokeArgs($generator, [$apiKey, $prompt]);
    
    echo "=== RAW ===\n";
    echo $raw . "\n";
    echo "===========\n";

    echo "=== SANITIZED ===\n";
    echo $generator->sanitize($raw) . "\n";
    echo "===========\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
