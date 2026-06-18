# Prompt: Buat Proyek Laravel + VueJS Hello World dengan Integrasi ION SSO

## Tujuan

Buat proyek PHP Laravel baru yang sederhana dengan satu halaman frontend VueJS berbasis **Hello World**. Proyek ini harus terintegrasi dengan package `ptpn/ion-client` dari repository lokal `/home/yusren/Documents/PHP Projects/ion-client` menggunakan path Composer.

Frontend VueJS menampilkan status autentikasi user (login/logout) dan data user dari ION SSO. Backend Laravel menangani SSO callback menggunakan method `callback()` yang disediakan package.

---

## Tech Stack

- **Backend**: PHP 8.x + Laravel 11 atau 12
- **Frontend**: VueJS 3 (Composition API) + Vite
- **SSO Client**: `ptpn/ion-client` (install dari path lokal)
- **Session**: Laravel session default (file/redis terserah, file cukup untuk dev)

---

## Struktur Proyek yang Diharapkan

```
dummy-ion-app/
├── app/
│   └── Http/
│       └── Controllers/
│           └── AuthController.php      # (opsional) wrapper callback/logout
├── config/
│   └── ion-client.php                  # hasil publish config package
├── resources/
│   ├── js/
│   │   ├── App.vue                     # Komponen utama VueJS
│   │   ├── main.js                     # Entry point VueJS
│   │   └── components/
│   │       └── HelloWorld.vue          # Tampilkan hello + status auth
│   └── views/
│       └── app.blade.php               # Blade view yang mount VueJS
├── routes/
│   ├── web.php                         # Route callback + SPA fallback
│   └── api.php                         # API untuk cek session/user
├── composer.json
├── package.json
├── vite.config.js
└── .env.example
```

---

## Step 1: Inisialisasi Laravel

1. Buat proyek Laravel baru di `/home/yusren/Documents/PHP Projects/dummy-ion-app`.
2. Install dependencies default Laravel.
3. Pastikan `php artisan serve` bisa berjalan.

---

## Step 2: Install Package `ptpn/ion-client`

Install package dari path lokal repository ini:

```bash
composer config repositories.ion-client path /home/yusren/Documents/PHP Projects/ion-client
composer require ptpn/ion-client
```

Setelah install, publish config:

```bash
php artisan vendor:publish --tag=ion-client-config
```

---

## Step 3: Konfigurasi Environment

Tambahkan ke `.env` (dan `.env.example`):

```env
# ION SSO API
ION_BASE_URL=https://ion.palmco.id/api/v2
ION_CLIENT_ID=your-client-id
ION_CLIENT_SECRET=your-client-secret
ION_TIMEOUT=30
ION_VERIFY_SSL=true

# Callback / Frontend
ION_FRONTEND_URL=http://localhost:5173

# Cookie Session
ION_COOKIE_NAME=ion_session
ION_COOKIE_LIFETIME=1440
ION_COOKIE_DOMAIN=localhost
ION_COOKIE_SECURE=false
ION_COOKIE_HTTP_ONLY=true
ION_COOKIE_SAMESITE=Lax
```

> Sesuaikan `ION_FRONTEND_URL` dengan URL Vite dev server VueJS (biasanya `http://localhost:5173`).

---

## Step 4: Route SSO Callback

Di `routes/web.php`, buat route callback:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ptpn\IonClient\IonClient;

Route::get('/auth/callback', function (Request $request) {
    return app(IonClient::class)->callback($request);
});
```

> **Catatan keamanan dari package:** method `callback()` sudah menangani validasi `return_url`, validasi format session ID, dan urutan aman (verify → full-info → buat session). Jangan ubah urutan ini.

---

## Step 5: API Endpoint untuk Frontend

Di `routes/api.php`, buat endpoint:

1. `GET /api/me`
   - Cek cookie `ion_session`.
   - Jika session aktif, kembalikan data user dari session (`user_data`).
   - Jika tidak, kembalikan `authenticated: false`.

2. `POST /api/logout`
   - Ambil `session_id` dari session.
   - Panggil `IonClient::logout($sessionId)`.
   - Hapus session lokal.
   - Hapus cookie `ion_session`.
   - Kembalikan JSON sukses.

---

## Step 6: Frontend VueJS Hello World

### `resources/views/app.blade.php`

Buat Blade view sederhana yang load Vite assets:

```html
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dummy ION App</title>
    @vite(['resources/js/main.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
```

### `resources/js/main.js`

Entry point VueJS:

```js
import { createApp } from 'vue';
import App from './App.vue';

createApp(App).mount('#app');
```

### `resources/js/App.vue`

Komponen utama yang mount `HelloWorld`:

```vue
<template>
  <HelloWorld />
</template>

<script setup>
import HelloWorld from './components/HelloWorld.vue';
</script>
```

### `resources/js/components/HelloWorld.vue`

Komponen Hello World yang menampilkan:
- Judul "Hello World from Dummy ION App".
- Status login: `authenticated` atau `guest`.
- Nama user dari SSO jika login.
- Tombol "Logout" jika login, atau pesan "Silakan login melalui ION SSO" jika guest.

Gunakan `fetch('/api/me')` saat mounted untuk cek status.

---

## Step 7: Route SPA Fallback

Di `routes/web.php`, tambahkan fallback route agar semua path selain `/auth/callback` dan `/api/*` mengembalikan view `app`:

```php
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|auth/callback).*$');
```

---

## Step 8: Konfigurasi Vite

Pastikan `vite.config.js` mengarah ke entry point `resources/js/main.js` dan mengaktifkan CORS/proxy jika perlu untuk dev.

Contoh minimal:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/main.js'],
            refresh: true,
        }),
        vue(),
    ],
    server: {
        port: 5173,
        origin: 'http://localhost:5173',
    },
});
```

Install plugin VueJS:

```bash
npm install vue @vitejs/plugin-vue
```

---

## Step 9: Jalankan Proyek

1. Jalankan backend:
   ```bash
   php artisan serve --port=8000
   ```

2. Jalankan frontend:
   ```bash
   npm run dev
   ```

3. Akses `http://localhost:5173`.

4. Untuk mensimulasikan login SSO, buka URL login ION yang mengarahkan ke:
   ```
   http://localhost:8000/auth/callback?code=AUTH_CODE_DARI_SSO
   ```

---

## Catatan Tambahan

- Jangan buat method `login()` di proyek ini. Login dilakukan di ION SSO, bukan di client.
- Pastikan cookie `ion_session` bisa dibaca oleh frontend (sesuaikan `ION_COOKIE_DOMAIN` dan `ION_COOKIE_SAMESITE`).
- Untuk production, gunakan Redis sebagai session store agar webhook logout dari SSO bisa menghapus session berdasarkan ID.
- Data user dari SSO (`user_data`) berisi ID integer asli. Jika ingin mengirim ke frontend, sembunyikan ID integer dan gunakan versi hash-nya (sesuai dokumentasi package).

---

## Deliverable yang Diharapkan

Setelah selesai, proyek harus bisa:
- [ ] Menjalankan Laravel backend tanpa error.
- [ ] Menjalankan VueJS frontend dengan tampilan Hello World.
- [ ] Memanggil `IonClient::callback()` saat `/auth/callback?code=xxx` diakses.
- [ ] Menyimpan session lokal dengan ID sama dengan SSO session ID.
- [ ] Endpoint `/api/me` mengembalikan status autentikasi.
- [ ] Tombol logout menghapus session lokal dan memanggil `IonClient::logout()`.
