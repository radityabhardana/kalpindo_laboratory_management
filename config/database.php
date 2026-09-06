<?php
/**
 * Database Configuration & Connection Helper
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 */

declare(strict_types=1);

function getDbConnection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dbType = getenv('DB_TYPE') ?: 'sqlite';
    $sqliteFile = __DIR__ . '/../data/kalpindo.sqlite';

    try {
        if ($dbType === 'sqlite') {
            $dataDir = dirname($sqliteFile);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0777, true);
            }

            $isNewDatabase = !file_exists($sqliteFile) || filesize($sqliteFile) === 0;

            $pdo = new PDO('sqlite:' . $sqliteFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON;');

            if ($isNewDatabase) {
                require_once __DIR__ . '/setup_db.php';
                initializeDatabase($pdo);
            }
        } else {
            // Konfigurasi MySQL jika ingin dihubungkan ke phpMyAdmin Laragon
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $dbName = getenv('DB_NAME') ?: 'kalpindo_inventory';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        // Jalankan migrasi skema & data secara aman (idempotent)
        runMigrations($pdo);

        return $pdo;
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif;padding:20px;background:#fee2e2;color:#991b1b;border-radius:8px;margin:20px;">' .
            '<h3>Koneksi Database Gagal</h3>' .
            '<p>' . htmlspecialchars($e->getMessage()) . '</p>' .
            '<p><small>Pastikan folder data/ writable atau konfigurasi database sudah benar.</small></p>' .
            '</div>');
    }
}

/**
 * Migrasi Skema & Sinkronisasi Ruang Lingkup (P, M, T, D, E) serta KAN / Non-KAN
 */
function runMigrations(PDO $pdo): void {
    static $hasRun = false;
    if ($hasRun) return;
    $hasRun = true;

    // 1. Kolom is_kan pada tabel instruments dan certificates (1 = KAN, 0 = Non-KAN)
    try {
        $pdo->exec("ALTER TABLE instruments ADD COLUMN is_kan INTEGER DEFAULT 1");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE certificates ADD COLUMN is_kan INTEGER DEFAULT 1");
    } catch (Exception $e) {}

    // 2. Ruang Lingkup Resmi: P (Pressure), M (Massa), T (Suhu), D (Dimensi), E (Electric)
    try {
        $pdo->exec("
            INSERT OR REPLACE INTO scopes (code, name, description, unit_samples, color)
            VALUES 
                ('P', 'Tekanan (Pressure)', 'Pressure gauge, transmitter tekanan, manometer, vacuum gauge', 'bar, psi, kPa, MPa', 'emerald'),
                ('M', 'Massa (Mass)', 'Timbangan analitik presisi, timbangan elektronik, anak timbangan standar', 'g, kg, mg', 'indigo'),
                ('T', 'Suhu (Temperature)', 'Thermometer digital & gelas, thermocouple, thermohygrometer, oven, bath', '°C, %RH, K', 'amber'),
                ('D', 'Dimensi (Dimension)', 'Vernier caliper, digital micrometer, dial gauge, gauge block', 'mm, inch, µm', 'blue'),
                ('E', 'Kelistrikan (Electric)', 'Digital multimeter, clamp meter, voltage calibrator, insulation tester', 'V, A, Ohm, Hz', 'violet')
        ");

        // Migrasi kode 'S' lama ke 'T' (Suhu)
        $pdo->exec("UPDATE instruments SET scope_code = 'T' WHERE scope_code = 'S'");
        $pdo->exec("UPDATE certificates SET scope_code = 'T' WHERE scope_code = 'S'");
        $pdo->exec("DELETE FROM scopes WHERE code IN ('V', 'F', 'S')");

        // Sinkronisasi status is_kan dari awalan nomor sertifikat (N = Non-KAN)
        $pdo->exec("UPDATE certificates SET is_kan = 0 WHERE certificate_number LIKE 'N%'");
        $pdo->exec("UPDATE certificates SET is_kan = 1 WHERE certificate_number NOT LIKE 'N%'");
    } catch (Exception $e) {}
}

