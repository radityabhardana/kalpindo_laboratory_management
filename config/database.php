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

        return $pdo;
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif;padding:20px;background:#fee2e2;color:#991b1b;border-radius:8px;margin:20px;">' .
            '<h3>Koneksi Database Gagal</h3>' .
            '<p>' . htmlspecialchars($e->getMessage()) . '</p>' .
            '<p><small>Pastikan folder data/ writable atau konfigurasi database sudah benar.</small></p>' .
            '</div>');
    }
}
