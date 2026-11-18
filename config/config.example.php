<?php
// Copy to config/config.php (git-ignored) and fill in real values.
return [
    'db' => [
        'driver' => 'mysql',            // 'mysql' | 'sqlite'
        'host' => 'localhost',
        'name' => 'faktura',
        'user' => 'faktura',
        'pass' => 'change-me',
        'sqlite' => __DIR__ . '/../storage/faktura.sqlite',
    ],
    // base64 of 32 random bytes: php -r 'echo base64_encode(random_bytes(32)), "\n";'
    'app_key' => 'BASE64_32_BYTES_PLACEHOLDER',
    'debug' => false,
    'promo_enabled' => false,
    'base_url' => 'https://example.com',
];
