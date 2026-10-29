# PI Project Management (PHP 8 + MySQL) — Hardened Edition

Aplikasi manajemen project dan issue improvement lintas departemen untuk PIE Team, menggunakan backend **PHP 8 + MySQL (PDO)** dan memenuhi standar **IT Application Security Review**.

## Struktur Utama Repository

- [`config.php`](config.php) – Pemuat konfigurasi environment (`.env`), secret management, dan parameter keamanan sesi/upload
- [`.env.example`](.env.example) – Template konfigurasi environment (kredensial database, secret, kebijakan sesi & direktori storage)
- [`db.php`](db.php) – Koneksi PDO (`EMULATE_PREPARES = false`), proteksi CSRF, validasi input/workflow, RBAC kepemilikan project, sinkronisasi tabel relasional, dan audit trail
- [`api.php`](api.php) – Endpoint REST API dengan transaction locking (`FOR UPDATE`), validasi upload ketat, serta fitur backup & restore penuh
- [`index.php`](index.php) – Entry point antarmuka web dengan security headers
- [`public/index.html`](public/index.html) & [`public/shared.js`](public/shared.js) – Antarmuka pengguna (SPA) lengkap di dalam repository
- [`schema.sql`](schema.sql) – Skema database MySQL beserta panduan akun database *least privilege*
- [`.htaccess`](.htaccess) – Konfigurasi routing Apache dan pemblokiran akses langsung ke folder `storage/`, `tests/`, serta file konfigurasi/sensitif
- [`tests/test-security-php.php`](tests/test-security-php.php) – Automated test suite untuk verifikasi Acceptance Gate P0, P1, dan P2

## Standar Keamanan yang Diterapkan

1. **Proteksi File Upload (Anti-RCE & XSS)**:
   - Validasi *whitelist* ekstensi dan verifikasi MIME type aktual menggunakan `mime_content_type()` di sisi server.
   - Penolakan otomatis terhadap ekstensi script/ekstensi ganda (`.php`, `.phtml`, `.phar`, `.html`, `.svg`, `.js`, `.exe`, `.bat`, dll.) dan pemindaian tag `<?php` pada isi file.
   - Pembatasan ukuran file maksimal (`PT_MAX_UPLOAD_MB`, default 100 MB) baik pada mode streaming maupun multipart.
   - Direktori `storage/`, `storage/files/`, dan `storage/backup/` dilindungi `.htaccess` (`Require all denied` dan `php_flag engine off`) sehingga tidak dapat diakses atau dieksekusi langsung melalui webroot.
2. **Isolasi & Sanitasi Backup**:
   - File backup (`POST /api/backup`) **tidak pernah menyertakan `password_hash`**.
   - Backup mencakup seluruh tabel relasional (`projects`, `project_stages`, `project_milestones`, `project_quotations`, `project_files`, `project_logs`, `settings`) dan mendukung pemulihan transaksional (`POST /api/restore`).
   - Direktori storage dan backup dapat ditempatkan di luar webroot melalui variabel `PT_STORAGE_DIR` dan `PT_BACKUP_DIR` pada file `.env`.
3. **Least-Privilege Database & Secret Management**:
   - Kredensial database dan `PT_SESSION_SECRET` dimuat dari `.env` (tidak di-hardcode di source code).
   - Menggunakan akun MySQL khusus (`pt_app`) dengan hak akses terbatas pada database `project_tracker`.
4. **Session Hardening, PIN Policy & CSRF Protection**:
   - Masa aktif sesi dibatasi secara default menjadi **12 jam** (`PT_SESSION_HOURS=12`).
   - Panjang minimal PIN ditingkatkan menjadi **6 karakter** (`PT_PIN_MIN_LENGTH=6`).
   - Atribut cookie `HttpOnly=true`, `SameSite=Lax`, dan `Secure` otomatis aktif pada koneksi HTTPS (`COOKIE_SECURE=auto` / `true`).
   - Seluruh endpoint perubahan data (`POST`, `PUT`, `DELETE`) dilindungi validasi header `X-PT: 1`, pemeriksaan `Origin`/`Referer`, dan token **CSRF** berbasis HMAC (`X-CSRF-Token`).
   - Reset PIN oleh Admin maupun pergantian PIN oleh pengguna secara otomatis mencabut sesi lama di perangkat lain (*session revocation*).
5. **RBAC Berbasis Kepemilikan Project & Concurrency Control**:
   - **Admin**: Akses penuh ke seluruh project, pengaturan, pengguna, dan backup/restore.
   - **User**: Dapat membuat project baru serta mengubah/menghapus project yang menjadi tanggung jawabnya (sebagai PIC atau pembuat project). Tidak dapat mengubah atau menghapus project milik PIC/unit lain.
   - **Contributor**: Hanya dapat membuat dan memperbarui laporan Improvement miliknya sendiri serta mengunggah lampiran Improvement (`IMP-*`).
   - **Viewer**: Akses baca saja (*read-only*).
   - Endpoint `/api/sync` menggunakan transaksi database dengan row-level locking (`SELECT ... FOR UPDATE`) dan *server-side timestamping* untuk mencegah *race condition* atau *lost update*.
   - Seluruh aktivitas pembuatan, perubahan, penghapusan, autentikasi, dan backup tercatat di tabel `project_logs` sebagai *audit trail*.

## Persiapan & Deployment

1. Salin `.env.example` menjadi `.env` dan sesuaikan kredensial database serta `PT_SESSION_SECRET`.
2. Buat database `project_tracker`, impor `schema.sql`, dan buat akun database `pt_app` dengan hak akses terbatas sesuai petunjuk di `schema.sql`.
3. Aktifkan **HTTPS (TLS)** pada Apache/Reverse Proxy untuk penggunaan di jaringan kantor/produksi dan atur `COOKIE_SECURE=true` pada `.env`.
4. Jalankan pengujian otomatis sebelum rilis:
   ```powershell
   php tests/test-security-php.php
   ```
