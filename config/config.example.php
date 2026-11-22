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
    'debug' => false,               // never true in production: it shows exception details on the page
    'promo_enabled' => false,
    // 'brand_name' => 'Pan Behapowiec',   // optional: the name in the "by ..." line under the logo (default shown)
    'base_url' => 'https://example.com', // https => the app also sends Strict-Transport-Security
    // Optional: writable directory with pdf-cache/, tmp/ and logos/ (default: <app>/storage).
    // 'storage_path' => '/home/user/faktura-storage',
    // SMTP targets must be public hosts on ports 25, 465, 587 or 2525 (SSRF guard). Set to true only
    // if you run your own relay in a private network; the port list still applies.
    'mail_allow_private_hosts' => false,
];
