# Kalpindo CalibFlow - Sistem Alur Kerja Kalibrasi & Sertifikasi

Sistem informasi operasional internal PT Kalpindo Kalibrasi untuk tata kelola alur kerja laboratorium kalibrasi sesuai standar akreditasi ISO/IEC 17025:2017 dan Komite Akreditasi Nasional (KAN LK-088-IDN).

Sistem ini menghubungkan seluruh siklus operasional mulai dari penerimaan alat oleh divisi sales, pencatatan data mentah pengujian oleh teknisi, penerbitan nomor sertifikat resmi, hingga pencetakan dokumen fisik dan verifikasi publik berbasis kode QR.

---

## Arsitektur & Teknologi

- Bahasa Pemrograman: PHP 8.1+ (Native, Strict Types)
- Basis Data: SQLite (default: data/kalpindo.sqlite) dan kompatibel MySQL (database/kalpindo_kalibrasi.sql)
- Antarmuka: Tailwind CSS, Phosphor Icons
- Server Web: Apache (Laragon) atau PHP Development Server bawaan

---

## Alur Operasional Laboratorium

Sistem mengadopsi alur kerja 4 tahap berurutan:

1. <b>Registrasi Order & SPK (Divisi Sales)</b>
   - File: orders.php
   - Petugas sales menerima alat dari pelanggan, memilih jenis layanan (In-Lab atau On-Site), mencatat identitas instrumen (merk, tipe, nomor seri, rentang ukur), dan menugaskan teknisi pelaksana.
   - Nomor SPK dibuat otomatis dengan pola ORD-YYMM-XXX.

2. <b>Pengujian & Lembar Kerja (Teknisi Laboratorium)</b>
   - File: worksheet.php
   - Teknisi mencatat kondisi ruang kalibrasi (suhu dan kelembapan beserta estimasi ketidakpastian), standar acuan yang digunakan, metode kalibrasi, serta hasil inspeksi visual.
   - Tabel titik ukur dinamis mendukung pengulangan Run 1, Run 2, dan Run 3 dengan kalkulasi otomatis untuk nilai rata-rata, nilai koreksi, dan ketidakpastian.
   - Setelah selesai, teknisi menyerahkan lembar kerja ke Bagian Sertifikat.

3. <b>Penomoran & Penerbitan Sertifikat (Bagian Sertifikat)</b>
   - File: certificates.php
   - Bagian sertifikat memverifikasi data mentah teknisi.
   - Penomoran sertifikat dibuat otomatis sesuai format regulasi Kalpindo: Tahun (2 digit) + Bulan (2 digit) + Kode Lingkup (P/T/M/D/E/A) + Nomor Urut (4 digit) + Kode Revisi (2 digit), contoh: 2609D0001-00.
   - Setelah diterbitkan, berkas masuk ke tabel Arsip Sertifikat Resmi yang dilengkapi fitur pencarian, filter ruang lingkup, dan opsi sortir data sebelum dicetak.

4. <b>Cetak Dokumen Resmi & Verifikasi Publik</b>
   - File Cetak: print_certificate.php
   - File Verifikasi: verify.php
   - Lembar sertifikat diformat presisi untuk kertas standar A4, memuat logo resmi Kalpindo, tanda akreditasi KAN LK-088-IDN, rincian teknis, tabel hasil kalibrasi, stempel kalibrasi, dan tanda tangan Manajer Teknis.
   - Setiap dokumen dilengkapi kode QR dinamis yang mengarah ke halaman verifikasi publik untuk validasi keaslian sertifikat secara mandiri oleh pelanggan atau auditor.

---

## Hak Akses & Peran Pengguna (RBAC)

Sistem menerapkan pembatasan hak akses berbasis peran pada tingkat server dan antarmuka navigasi:

1. <b>Master Setup Administrator (SUPER_ADMIN)</b>
   - Akun: admin / admin
   - Hak Akses: Pengaturan penuh seluruh modul dan satu-satunya akun yang dapat mengakses manajemen karyawan (users.php).
   - Catatan: Akun ini dipisahkan khusus untuk setup sistem dan tidak tercatat sebagai data karyawan laboratorium.

2. <b>Divisi Sales & Front Office (SALES)</b>
   - Akun Uji: april / april
   - Menu: Dashboard dan Sales Order (SPK).
   - Pembatasan: Tidak memiliki akses ke worksheet teknisi maupun modul administrasi sertifikat.

3. <b>Teknisi Kalibrasi (TECHNICIAN)</b>
   - Akun Uji: radit / radit
   - Menu: Dashboard dan Worksheet Lembar Kerja.
   - Pembatasan: Tidak dapat menerbitkan atau mengubah nomor sertifikat.

4. <b>Bagian Pengurus Sertifikat (CERT_ADMIN)</b>
   - Akun Uji: adi / adiadi
   - Menu: Dashboard dan Penerbitan Sertifikat.
   - Wewenang: Menetapkan nomor sertifikat resmi, mengesahkan penerbitan, dan mengajukan revisi sertifikat.

---

## Struktur Direktori Proyek

- <b>assets/</b>: Berkas visual logo, gaya kustom, dan konfigurasi cetak dokumen
- <b>config/</b>: Konfigurasi koneksi basis data (database.php) dan skrip inisialisasi awal (setup_db.php)
- <b>data/</b>: Berkas penyimpanan database SQLite lokal (kalpindo.sqlite)
- <b>database/</b>: Skrip skema database MySQL (kalpindo_kalibrasi.sql)
- <b>includes/</b>: Komponen navigasi sidebar (header.php) dan fungsi pembantu otentikasi serta format data (helpers.php)
- <b>scripts/</b>: Skrip pembantu pemeliharaan sistem
- <b>index.php</b>: Halaman dashboard ringkasan statistik dan antrean pengerjaan
- <b>login.php</b>: Halaman masuk dengan antarmuka terpusat
- <b>logout.php</b>: Pembersihan sesi kerja dan pengalihan ke halaman login
- <b>orders.php</b>: Registrasi dan pemantauan surat perintah kerja
- <b>worksheet.php</b>: Pengisian data mentah teknisi dan kalkulasi kalibrasi
- <b>certificates.php</b>: Modul penomoran, sortir arsip, dan penerbitan sertifikat
- <b>print_certificate.php</b>: Tata letak cetak sertifikat resmi ukuran A4
- <b>verify.php</b>: Portal publik validasi keabsahan nomor sertifikat
- <b>users.php</b>: Modul master admin untuk pengelolaan akun karyawan dan penetapan peran

---

## Petunjuk Menjalankan Aplikasi

1. <b>Menggunakan Laragon (Rekomendasi)</b>
   - Letakkan folder proyek pada direktori: C:\laragon\www\kalpindo inventory
   - Pastikan PHP 8.1 atau lebih baru aktif di Laragon.
   - Buka peramban dan akses: http://localhost/kalpindo%20inventory atau via virtual host.

2. <b>Menggunakan Server PHP Bawaan (CLI)</b>
   - Buka terminal pada folder proyek.
   - Jalankan perintah: php -S localhost:8000
   - Buka peramban dan akses: http://localhost:8000

3. <b>Inisialisasi Ulang Database (Jika Diperlukan)</b>
   - Jika ingin me-reset database SQLite ke kondisi awal dengan data demo lengkap, buka terminal dan jalankan:
     php config/setup_db.php

---

## Ruang Lingkup Kalibrasi yang Didukung

Kode ruang lingkup standar yang digunakan dalam penomoran sertifikat:

- Kode P: Tekanan (Pressure)
- Kode T: Suhu dan Kelembapan (Temperature)
- Kode M: Massa dan Timbangan (Mass)
- Kode D: Dimensi dan Panjang (Dimension)
- Kode E: Kelistrikan dan Instrumen Elektronik (Electrical)
- Kode A: Akustik dan Getaran (Acoustic)

---

PT Kalpindo Kalibrasi - Standar Mutu ISO/IEC 17025:2017
