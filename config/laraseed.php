<?php

/**
 * Configuration for azwar/laraseed
 *
 * Publish this file to your application with:
 *   php artisan vendor:publish --tag=laraseed-config
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Model Namespace
    |--------------------------------------------------------------------------
    |
    | The base namespace where your Eloquent models live. The generator will
    | look for models relative to this namespace when a short name is given.
    |
    */
    'model_namespace' => 'App\\Models',

    /*
    |--------------------------------------------------------------------------
    | Output Paths
    |--------------------------------------------------------------------------
    |
    | Where the generated files should be written, relative to the project root.
    |
    */
    'output' => [
        'factory'  => 'database/factories',
        'seeder'   => 'database/seeders',
        'policy'   => 'app/Policies',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stub Overrides
    |--------------------------------------------------------------------------
    |
    | Point these to a local directory if you want to customise the generated
    | output without forking the package. Run:
    |   php artisan vendor:publish --tag=laraseed-stubs
    | to get a copy of the built-in stubs you can edit.
    |
    */
    'stubs_path' => null, // e.g. base_path('stubs/laraseed')

    /*
    |--------------------------------------------------------------------------
    | Gemini API Configuration
    |--------------------------------------------------------------------------
    |
    | API Key untuk Google Gemini yang digunakan oleh GeminiCodeGenerator
    | sebagai engine pembuat kode Factory secara otomatis.
    |
    | Tambahkan di .env laravel-test-app:
    |   GEMINI_API_KEY=your-api-key-here
    |   GEMINI_MODEL=gemini-2.5-flash
    |
    */
    'gemini_api_key' => env('GEMINI_API_KEY'),

    'gemini_model'   => env('GEMINI_MODEL', 'gemini-2.5-flash'),

    'gemini' => [
        'temperature'       => 0.1,
        'max_output_tokens' => 1024,
    ],

];
