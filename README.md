# Kalpindo CalibFlow - Sistem Manajemen Laboratorium Kalibrasi & Sertifikasi

Sistem informasi operasional terintegrasi PT Kalpindo Kalibrasi untuk tata kelola seluruh alur kerja laboratorium kalibrasi sesuai standar akreditasi internasional **ISO/IEC 17025:2017** dan regulasi Komite Akreditasi Nasional (**KAN LK-088-IDN**).

Aplikasi ini menghubungkan seluruh siklus operasional: penerimaan order oleh divisi Sales, pencatatan data mentah dan kalkulasi ketidakpastian oleh Teknisi Lab, penelaahan data dan penerbitan sertifikat oleh Pengurus Sertifikat, hingga pencetakan dokumen fisik berstandar A4 serta verifikasi publik berbasis kode QR dinamis.

---

## Ringkasan Fitur Utama

- **Otomatisasi Penomoran Sertifikat KAN & Kalpindo:** Format penomoran cerdas sesuai regulasi: Tahun (2 digit) + Bulan (2 digit) + Kode Ruang Lingkup (P/T/M/D/E/A) + Nomor Urut (4 digit) + Revisi (2 digit), contoh: `2610D0001-00`.
- **Fitur Simpan Draf Teknisi:** Teknisi dapat mencicil pengisian titik ukur, data pengulangan (Run 1, Run 2, Run 3), dan kondisi lingkungan ke dalam status `DRAFT` tanpa langsung memublikasikannya ke pengurus sertifikat.
- **Audit Data Lembar Kerja oleh Pengurus Sertifikat:** Bagian sertifikat dapat meninjau rincian lengkap lembar kerja teknisi (standar acuan, suhu/kelembapan, metode uji, tabel nilai rata-rata, koreksi, dan ketidakpastian) sebelum pengesahan dan penerbitan nomor resmi.
- **Desain Modern & Adaptif (Light / Dark Mode):** Dilengkapi *theme switcher* instan dengan palet warna terkurasi, kontras tinggi, dan tampilan profesional berstandar enterprise.
- **100% Mandiri & Siap Offline:** Seluruh aset ikon (Phosphor Icons), tipografi, stylesheet lokal ([custom.css](file:///c:/laragon/www/kalpindo%20inventory/assets/css/custom.css)), dan pustaka berjalan secara lokal tanpa memerlukan koneksi internet / compiler eksternal.
- **Pencetakan Dokumen Resmi Presisi A4:** Tata letak siap cetak ([print_certificate.php](file:///c:/laragon/www/kalpindo%20inventory/print_certificate.php)) dengan kop resmi, logo KAN, tabel kalibrasi rapi, tanda tangan otorisasi, stempel kalibrasi, dan QR Code verifikasi.
- **Portal Verifikasi Publik Mandiri:** Validasi keaslian dokumen sertifikat melalui pemindaian kode QR atau input nomor sertifikat di portal [verify.php](file:///c:/laragon/www/kalpindo%20inventory/verify.php).

---

## Arsitektur & Teknologi

| Komponen | Spesifikasi |
|---|---|
| **Bahasa Pemrograman** | PHP 8.1+ (Native, Strict Typing, PDO) |
| **Basis Data** | SQLite (default: `data/kalpindo.sqlite`) & Kompatibel MySQL (`database/kalpindo_kalibrasi.sql`) |
| **Gaya & Desain Antarmuka** | Custom Enterprise CSS System ([custom.css](file:///c:/laragon/www/kalpindo%20inventory/assets/css/custom.css)), Phosphor Icons lokal |
| **Keamanan** | Session Token, Sanitasi Entitas HTML, Role-Based Access Control (RBAC), CSRF Form tokens |
| **Web Server** | Apache (Laragon) atau PHP Built-in CLI Server |

---

## Alur Operasional Laboratorium (Workflow)

```
[SALES]               [TEKNISI LAB]                   [PENGURUS SERTIFIKAT]            [PELANGGAN / AUDITOR]
Registrasi SPK  --->  Pengisian Lembar Kerja    --->  Review Data & Pengesahan  --->  Cetak A4 & Scan QR
(Status: RECEIVED)    (Simpan Draf / Selesai)         (Terbitkan Sertifikat)          (Verifikasi Publik)
```

1. **Registrasi Order & SPK (Divisi Sales)**
   - Halaman: [orders.php](file:///c:/laragon/www/kalpindo%20inventory/orders.php)
   - Petugas Sales mencatat pelanggan, instrumen (merk, model, no. seri, rentang ukur), jenis kalibrasi (In-Lab / On-Site), dan menugaskan teknisi pelaksana.
   - Nomor SPK otomatis: `ORD-YYMM-XXX`.

2. **Pengujian & Pengisian Lembar Kerja (Teknisi Lab)**
   - Halaman: [worksheet.php](file:///c:/laragon/www/kalpindo%20inventory/worksheet.php)
   - Teknisi mencatat kondisi lingkungan (suhu, kelembapan, ketidakpastian lingkungan), standar acuan tertelusur, dan metode acuan (misal: JIS B 7507, ISO 3611).
   - Input titik ukur dengan multi-run (Run 1, Run 2, Run 3) dengan kalkulasi otomatis nilai rata-rata, nilai koreksi, dan estimasi ketidakpastian bentangan (*expanded uncertainty*).
   - **Fitur Simpan Draf:** Tombol *"Simpan ke Draf"* untuk menyimpan progres pengerjaan bertahap. Tersedia tab filter **"Draf"** di tabel antrean teknisi.
   - **Serahkan ke Sertifikat:** Setelah pengujian final, lembar kerja dikirim ke bagian sertifikat (`status: COMPLETED`).

3. **Verifikasi & Penerbitan Sertifikat (Pengurus Sertifikat)**
   - Halaman: [certificates.php](file:///c:/laragon/www/kalpindo%20inventory/certificates.php)
   - Pengurus sertifikat memeriksa data masukan teknisi dan kelengkapan SPK melalui modal detail verifikasi.
   - Menetapkan nomor sertifikat resmi atau menggunakan nomor rekomendasi sistem otomatis.
   - Arsip sertifikat resmi dilengkapi fitur pencarian cepat, filter ruang lingkup kalibrasi, pengelompokan KAN / Non-KAN, dan tombol cetak langsung.

4. **Pencetakan Dokumen Resmi & Validasi Publik**
   - Halaman Cetak: [print_certificate.php](file:///c:/laragon/www/kalpindo%20inventory/print_certificate.php)
   - Portal Verifikasi: [verify.php](file:///c:/laragon/www/kalpindo%20inventory/verify.php)
   - Tata letak sertifikat dikalibrasi presisi untuk ukuran kertas A4, lengkap dengan kode QR autentikasi yang dapat dipindai oleh ponsel cerdas untuk membuktikan validitas dokumen di hadapan auditor.

---

## Hak Akses & Matriks Peran (RBAC)

Aplikasi menerapkan pembatasan hak akses berbasis peran pada tingkat backend (PHP Session) dan antarmuka pengguna:

| Peran | Akun Default | Modul Akses | Batasan Hak Akses |
|---|---|---|---|
| **Master Admin** (`SUPER_ADMIN`) | `admin` / `admin` | Seluruh modul sistem, termasuk [users.php](file:///c:/laragon/www/kalpindo%20inventory/users.php) | Akun root sistem; akun ini tidak dapat dihapus atau diturunkan levelnya. |
| **Divisi Sales** (`SALES`) | `april` / `april` | [index.php](file:///c:/laragon/www/kalpindo%20inventory/index.php), [orders.php](file:///c:/laragon/www/kalpindo%20inventory/orders.php) | Tidak dapat mengakses pengisian lembar kerja maupun penerbitan sertifikat. |
| **Teknisi Lab** (`TECHNICIAN`) | `radit` / `radit` | [index.php](file:///c:/laragon/www/kalpindo%20inventory/index.php), [worksheet.php](file:///c:/laragon/www/kalpindo%20inventory/worksheet.php) | Hanya dapat mengisi lembar kerja instrumen; tidak dapat mengesahkan nomor sertifikat resmi. |
| **Pengurus Sertifikat** (`CERT_ADMIN`) | `adi` / `adiadi` | [index.php](file:///c:/laragon/www/kalpindo%20inventory/index.php), [certificates.php](file:///c:/laragon/www/kalpindo%20inventory/certificates.php) | Mengesahkan dan menerbitkan nomor sertifikat resmi, meninjau data teknisi, dan mengelola revisi. |

---

## Struktur Direktori Proyek

```
kalpindo inventory/
├── assets/
│   ├── css/
│   │   ├── custom.css          # Desain sistem enterprise, token warna, tombol & utilitas responsif
│   │   └── print.css           # Konfigurasi cetak dokumen resmi A4 ISO/IEC 17025
│   ├── js/                     # Skrip utilitas interaktif antarmuka
│   └── images/                 # Logo Kalpindo, logo KAN LK-088-IDN, & stempel kalibrasi resmi
├── config/
│   ├── database.php            # Koneksi PDO SQLite (default) / MySQL
│   └── setup_db.php            # Skrip CLI inisialisasi awal & seeding data demo lengkap
├── data/
│   └── kalpindo.sqlite         # Berkas database SQLite lokal aktif
├── database/
│   └── kalpindo_kalibrasi.sql  # Skema tabel relasional untuk opsi MySQL/MariaDB
├── includes/
│   ├── header.php              # Header terpadu, sidebar navigasi, theme toggle & status KAN
│   ├── footer.php              # Penutup layout dokumen & injeksi script
│   └── helpers.php             # Library pembantu otentikasi, enkripsi, dan format sertifikat
├── index.php                   # Dashboard operasional, matriks KPI, & widget antrean kerja
├── login.php                   # Portal otentikasi login pengguna
├── logout.php                  # Pembersihan sesi kerja aman
├── orders.php                  # Manajemen Surat Perintah Kerja (SPK) & penugasan instrumen
├── worksheet.php               # Lembar kerja teknisi, input kondisi lingkungan, & simpan draf
├── certificates.php            # Penerbitan sertifikat resmi, review data uji, & tabel arsip
├── print_certificate.php       # Tata letak cetak dokumen fisik resmi sertifikat kalibrasi A4
├── verify.php                  # Portal publik mandiri untuk verifikasi keabsahan sertifikat via QR
├── users.php                   # Manajemen personil, hak akses, dan pengaturan sandi master
└── README.md                   # Dokumentasi teknis dan panduan operasional sistem
```

---

## Petunjuk Menjalankan Aplikasi

### 1. Menggunakan Laragon (Sangat Disarankan di Windows)
1. Letakkan folder proyek pada direktori web Laragon:
   ```
   C:\laragon\www\kalpindo inventory
   ```
2. Pastikan PHP versi 8.1 atau yang lebih baru aktif di Laragon.
3. Buka browser dan kunjungi:
   ```
   http://localhost/kalpindo%20inventory
   ```

### 2. Menggunakan PHP Built-in Server (CLI)
1. Buka Command Prompt / PowerShell di dalam folder proyek:
   ```powershell
   cd "C:\laragon\www\kalpindo inventory"
   php -S localhost:8000
   ```
2. Buka browser dan akses alamat `http://localhost:8000`.

### 3. Inisialisasi / Reset Database Demo (Opsional)
Untuk mengembalikan atau me-reset database SQLite ke kondisi awal lengkap dengan contoh data uji coba:
```powershell
php config/setup_db.php
```

---

## Kode Ruang Lingkup Kalibrasi Resmi

Sesuai akreditasi laboratorium, penomoran sertifikat menggunakan singkatan kode ruang lingkup berikut:

| Kode | Ruang Lingkup Kalibrasi | Contoh Instrumen |
|:---:|---|---|
| **D** | **Dimensi & Panjang** (*Dimension*) | Caliper / Jangka Sorong, Micrometer, Dial Indicator, Height Gauge |
| **P** | **Tekanan** (*Pressure*) | Pressure Gauge, Differential Pressure Transmitter, Manometer |
| **T** | **Suhu & Kelembapan** (*Temperature*) | Thermo-Hygrometer, Digital Thermometer, RTD Sensor, Oven Lab |
| **M** | **Massa & Timbangan** (*Mass*) | Timbangan Analitik (*Electronic Balance*), Anak Timbang (*Standard Weights*) |
| **E** | **Kelistrikan** (*Electrical*) | Digital Multimeter, Clamp Meter, Insulation Tester, Calibrator |
| **A** | **Akustik & Getaran** (*Acoustic*) | Sound Level Meter, Vibration Meter |

---

&copy; 2026 PT Kalpindo Kalibrasi — Sesuai Standar Mutu Laboratorium Terakreditasi ISO/IEC 17025:2017 & KAN LK-088-IDN.
