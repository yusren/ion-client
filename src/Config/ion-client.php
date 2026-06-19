<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable ION SSO Client
    |--------------------------------------------------------------------------
    |
    | Aktifkan atau nonaktifkan integrasi ION SSO. Jika diset false, package
    | tidak akan memproses callback SSO dan sistem dapat menggunakan auth
    | Laravel/default lain yang dikonfigurasi oleh user.
    |
    */
    'enabled' => filter_var(env('ION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | ION SSO Base URL
    |--------------------------------------------------------------------------
    |
    | Base URL untuk ION SSO API v2. Default mengarah ke server ION internal
    | PTPN. Ubah sesuai environment (staging/production).
    |
    */
    'base_url' => env('ION_BASE_URL', 'https://ion.palmco.id/api/v2'),

    /*
    |--------------------------------------------------------------------------
    | Client Credentials
    |--------------------------------------------------------------------------
    |
    | Application key dan secret yang diberikan oleh administrator ION untuk
    | setiap client app.
    |
    | - client_key        : digunakan di URL redirect login (Step 1) dan di
    |                       back-channel verify request (Step 3).
    | - client_identifier : digunakan HANYA di back-channel verify request
    |                       (Step 3). TIDAK BOLEH muncul di URL/browser.
    |
    | Fallback ke ION_CLIENT_ID / ION_CLIENT_SECRET disediakan untuk backward
    | compatibility dengan konfigurasi lama.
    |
    */
    'client_key' => env('ION_CLIENT_KEY', env('ION_CLIENT_ID', '')),
    'client_identifier' => env('ION_CLIENT_IDENTIFIER', env('ION_CLIENT_SECRET', '')),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Batas waktu (dalam detik) untuk setiap request ke ION.
    |
    */
    'timeout' => (int) env('ION_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | SSL Verification
    |--------------------------------------------------------------------------
    |
    | Aktifkan/nonaktifkan verifikasi SSL saat request. Untuk production
    | sebaiknya tetap true.
    |
    */
    'verify_ssl' => filter_var(env('ION_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | URL frontend yang akan menjadi tujuan redirect setelah proses callback
    | SSO selesai. Biasanya URL aplikasi frontend yang terintegrasi dengan
    | ION SSO.
    |
    */
    'frontend_url' => env('ION_FRONTEND_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | SSO Callback Cookie
    |--------------------------------------------------------------------------
    |
    | Pengaturan cookie session yang akan diset ke browser setelah callback
    | SSO berhasil. Cookie ini menyimpan SSO session ID agar frontend dapat
    | mengenali session pengguna.
    |
    */
    'cookie' => [
        'name' => env('ION_COOKIE_NAME', 'ion_session'),
        'lifetime' => (int) env('ION_COOKIE_LIFETIME', 1440),
        'domain' => env('ION_COOKIE_DOMAIN', null),
        'secure' => filter_var(env('ION_COOKIE_SECURE', false), FILTER_VALIDATE_BOOLEAN),
        'http_only' => filter_var(env('ION_COOKIE_HTTP_ONLY', true), FILTER_VALIDATE_BOOLEAN),
        'same_site' => env('ION_COOKIE_SAMESITE', 'Lax'),
    ],
];
