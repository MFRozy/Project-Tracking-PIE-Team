# PI Project Management (PHP 8 + MySQL) — Hardened Edition

Aplikasi manajemen project dan issue improvement lintas departemen untuk PIE Team, menggunakan backend **PHP 8 + MySQL (PDO)** dan memenuhi standar **IT Application Security Review**.

## Struktur Utama Repository

- [`config.php`](config.php) – Pemuat konfigurasi environment (`.env`), secret management, dan parameter keamanan sesi/upload
- [`.env.example`](.env.example) – Template konfigurasi environment (kredensial database, secret, kebijakan sesi & direktori storage)
- [`db.php`](db.php) – Koneksi PDO (`EMULATE_PREPARES = false`), proteksi CSRF, validasi input/workflow, RBAC kepemilikan project, sinkronisasi tabel relasional, dan audit trail
- [`api.php`](api.php) – Endpoint REST API dengan transaction locking (`FOR UPDATE`), validasi upload ketat, serta utilitas ekspor & impor data proyek
- [`public/index.php`](public/index.php) – Entry point antarmuka web dan front controller dengan security headers
- [`public/index.html`](public/index.html), [`public/shared.js`](public/shared.js), dan [`public/lib/i18n.js`](public/lib/i18n.js) – Antarmuka pengguna (SPA) dan pustaka terjemahan terpisah di dalam direktori `public/`
- [`schema.sql`](schema.sql) – Skema database MySQL beserta panduan akun database *least privilege*
- [`.htaccess`](.htaccess) & [`public/.htaccess`](public/.htaccess) – Konfigurasi routing Apache dan pemblokiran akses langsung ke file sensitif di luar webroot
- [`tests/test-security-php.php`](tests/test-security-php.php) – Automated test suite untuk verifikasi Acceptance Gate P0, P1, dan P2

## Standar Keamanan yang Diterapkan

1. **Proteksi File Upload (Anti-RCE & XSS)**:
   - Validasi *whitelist* ekstensi dan verifikasi MIME type aktual menggunakan `mime_content_type()` di sisi server.
   - Penolakan otomatis terhadap ekstensi script/ekstensi ganda (`.php`, `.phtml`, `.phar`, `.html`, `.svg`, `.js`, `.exe`, `.bat`, dll.) dan pemindaian tag `<?php` pada isi file.
   - Pembatasan ukuran file maksimal (`PT_MAX_UPLOAD_MB`, default 100 MB) baik pada mode streaming maupun multipart.
   - Direktori `storage/`, `storage/files/`, dan `storage/backup/` dilindungi `.htaccess` (`Require all denied` dan `php_flag engine off`) sehingga tidak dapat diakses atau dieksekusi langsung melalui webroot.
2. **Isolasi & Sanitasi Ekspor/Impor Data Proyek**:
   - File ekspor (`POST /api/backup`) **tidak pernah menyertakan `password_hash`**.
   - Fitur ekspor/impor internal merupakan utilitas sinkronisasi/ekspor data proyek (merge), bukan backup sistem menyeluruh. Backup produksi sesungguhnya dikelola secara independen oleh tim IT via database dump dan cadangan fisik storage berkala.
   - Fitur ekspor/impor in-app dinonaktifkan secara default (`ENABLE_APP_BACKUP=false`) untuk mencegah manipulasi data massal tanpa pengawasan IT.
   - Direktori storage dan backup dapat ditempatkan di luar webroot melalui variabel `PT_STORAGE_DIR` dan `PT_BACKUP_DIR` pada file `.env`.
3. **Least-Privilege Database & Secret Management**:
   - Kredensial database dan `PT_SESSION_SECRET` dimuat dari `.env` (tidak di-hardcode di source code).
   - Menggunakan akun MySQL khusus (`pt_app`) dengan hak akses terbatas pada database `project_tracker`.
4. **Session Hardening, PIN Policy & CSRF Protection**:
   - Masa aktif sesi dibatasi secara default menjadi **12 jam** (`PT_SESSION_HOURS=12`).
   - Panjang minimal PIN adalah **10 karakter** (`PT_PIN_MIN_LENGTH=10`); konfigurasi lingkungan tidak dapat menurunkan batas ini.
   - Atribut cookie `HttpOnly=true`, `SameSite=Lax`, dan `Secure` otomatis aktif pada koneksi HTTPS (`COOKIE_SECURE=auto` / `true`).
   - Seluruh endpoint perubahan data (`POST`, `PUT`, `DELETE`) dilindungi validasi header `X-PT: 1`, pemeriksaan `Origin`/`Referer`, dan token **CSRF** berbasis HMAC (`X-CSRF-Token`).
   - Reset PIN oleh Admin maupun pergantian PIN oleh pengguna secara otomatis mencabut sesi lama di perangkat lain (*session revocation*).
5. **RBAC Berbasis Kepemilikan Project & Concurrency Control**:
   - **Admin**: Akses penuh ke seluruh project, pengaturan, pengguna, dan utilitas ekspor/impor data proyek.
   - **User**: Dapat membuat project baru serta mengubah/menghapus project yang menjadi tanggung jawabnya (sebagai PIC atau pembuat project). Tidak dapat mengubah atau menghapus project milik PIC/unit lain.
   - **Contributor**: Hanya dapat membuat dan memperbarui laporan Improvement miliknya sendiri serta mengunggah lampiran Improvement (`IMP-*`).
   - **Viewer**: Akses baca saja (*read-only*).
   - Endpoint `/api/sync` menggunakan transaksi database dengan row-level locking (`SELECT ... FOR UPDATE`) dan *server-side timestamping* untuk mencegah *race condition* atau *lost update*.
   - Seluruh aktivitas pembuatan, perubahan, penghapusan, autentikasi, dan ekspor/impor tercatat di tabel `project_logs` sebagai *audit trail*.
6. **Alur Improvement Request & Terjemahan**:
   - Keputusan PI `To Project` otomatis membuat Project PI dengan PIC Review PI sebagai PIC awal; project dapat dikelola seperti project lain oleh PIC tersebut.
   - Request berstatus `Selesai` atau `Ditolak` diarsipkan dari daftar aktif, sementara riwayat feedback dan audit tetap tersedia melalui filter arsip.
   - PIC wajib diisi saat membuat project. Project lama tanpa PIC dapat diklaim oleh user dengan mengisi namanya sendiri sebagai PIC.
   - Terjemahan antarmuka dipisahkan ke `public/lib/i18n.js` dan digunakan bersama penerjemah yang sudah ada di frontend.

## Persiapan & Deployment

1. Salin `.env.example` menjadi `.env` dan sesuaikan kredensial database serta `PT_SESSION_SECRET`.
2. Buat database `project_tracker`, impor `schema.sql`, dan buat akun database `pt_app` dengan hak akses terbatas sesuai petunjuk di `schema.sql`.
3. **Konfigurasi Apache VirtualHost (DocumentRoot = public/)**:
   Arahkan `DocumentRoot` web server ke folder `public/`, **bukan** root repository. Hal ini mengisolasi seluruh file backend dan source code sensitif (`.env`, `config.php`, `db.php`, `schema.sql`, `storage/`, `tests/`) agar tidak dapat diakses langsung via web:
   ```apache
   <VirtualHost *:80>
       ServerName project-tracker.local
       DocumentRoot "c:/laragon/www/ProjectTracker/public"
       <Directory "c:/laragon/www/ProjectTracker/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
4. Aktifkan **HTTPS (TLS)** pada Apache/Reverse Proxy untuk penggunaan di jaringan kantor/produksi dan atur `COOKIE_SECURE=true` pada `.env`.
5. Jalankan pengujian otomatis sebelum rilis:
   ```powershell
   php tests/regression/run.php
   php tests/test-security-php.php
   ```
