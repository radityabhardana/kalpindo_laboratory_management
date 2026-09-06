<?php
/**
 * Halaman Publik Verifikasi Sertifikat (QR Code Scan Destination)
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Styled according to https://radityaproject.vercel.app/
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$certNumber = $_GET['cert'] ?? '';
$cert = null;

if ($certNumber) {
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT 
            c.*,
            i.name as instrument_name,
            i.brand,
            i.model_type,
            i.serial_number,
            o.customer_name,
            o.order_number
        FROM certificates c
        JOIN instruments i ON c.instrument_id = i.id
        JOIN orders o ON i.order_id = o.id
        WHERE c.certificate_number = ?
    ");
    $stmt->execute([$certNumber]);
    $cert = $stmt->fetch();
}

$scopes = getScopeList();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keaslian Sertifikat | PT Kalpindo</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            red: '#C81E26',
                            orange: '#F59D3F',
                            slate: '#0F172A',
                            dark: '#1E293B'
                        }
                    },
                    boxShadow: {
                        'soft': '0 20px 40px -15px rgba(0,0,0,0.05)',
                        'float': '0 30px 60px -20px rgba(0,0,0,0.08)',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="assets/css/custom.css">
</head>
<body class="bg-[#F8FAFC] text-brand-dark min-h-screen flex flex-col justify-between py-10 px-4">

    <div class="max-w-xl mx-auto w-full">
        
        <!-- Brand Header with Official Logo -->
        <div class="text-center mb-8">
            <a href="index.php" class="inline-block mb-3">
                <img src="assets/img/logo.png" alt="Logo Kalpindo" class="h-12 mx-auto object-contain">
            </a>
            <p class="text-xs text-gray-500 font-medium">Sistem Verifikasi Keabsahan Dokumen Sertifikat Resmi (ISO/IEC 17025)</p>
        </div>

        <?php if (!$cert): ?>
            <!-- Dokumen Tidak Ditemukan -->
            <div class="bg-white rounded-3xl p-8 text-center border border-red-200 shadow-float">
                <div class="w-16 h-16 rounded-full bg-red-100 text-brand-red flex items-center justify-center mx-auto mb-4">
                    <i class="ph-bold ph-x text-3xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-brand-dark mb-2">Sertifikat Tidak Ditemukan</h2>
                <p class="text-xs sm:text-sm text-gray-500 max-w-sm mx-auto mb-6">
                    Nomor sertifikat <span class="font-mono text-brand-red font-bold"><?= htmlspecialchars($certNumber ?: '-') ?></span> tidak terdaftar dalam basis data resmi PT Kalpindo Kalibrasi Indonesia.
                </p>
                <a href="index.php" class="btn-kalpindo-secondary text-xs py-2.5 px-6 inline-block">Kembali ke Beranda</a>
            </div>
        <?php else: ?>
            <!-- Dokumen Terverifikasi Sah -->
            <div class="bg-white rounded-3xl p-6 sm:p-8 border border-gray-100 shadow-float">
                
                <div class="text-center mb-6">
                    <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-emerald-500/10">
                        <i class="ph-fill ph-check-circle text-4xl"></i>
                    </div>
                    <span class="inline-block px-3.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200 mb-2">
                        DOKUMEN RESMI TERVERIFIKASI
                    </span>
                    <h2 class="text-2xl font-black text-brand-dark">Sertifikat Kalibrasi Sah</h2>
                    <p class="text-xs text-gray-500 mt-1">Terdaftar resmi di Laboratorium Kalibrasi PT Kalpindo (KAN LK-088-IDN)</p>
                </div>

                <!-- Info Table -->
                <div class="bg-[#F8FAFC] rounded-2xl p-5 border border-gray-100 space-y-3 text-xs mb-6">
                    <div class="flex justify-between items-center border-b border-gray-200/70 pb-2.5">
                        <span class="text-gray-500">Nomor Sertifikat:</span>
                        <span class="font-mono font-black text-sm text-brand-red bg-red-50 px-2.5 py-0.5 rounded border border-red-200"><?= htmlspecialchars($cert['certificate_number']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-gray-200/70 pb-2.5">
                        <span class="text-gray-500">Nama Alat / Instrumen:</span>
                        <span class="font-bold text-brand-dark"><?= htmlspecialchars($cert['instrument_name']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-gray-200/70 pb-2.5">
                        <span class="text-gray-500">Merk & Tipe:</span>
                        <span class="text-gray-700 font-medium"><?= htmlspecialchars($cert['brand']) ?> <?= htmlspecialchars($cert['model_type']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-gray-200/70 pb-2.5">
                        <span class="text-gray-500">Nomor Seri (SN):</span>
                        <span class="font-mono font-bold text-brand-dark"><?= htmlspecialchars($cert['serial_number']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-gray-200/70 pb-2.5">
                        <span class="text-gray-500">Pelanggan / Perusahaan:</span>
                        <span class="font-bold text-brand-dark text-right"><?= htmlspecialchars($cert['customer_name']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-gray-200/70 pb-2.5">
                        <span class="text-gray-500">Ruang Lingkup:</span>
                        <span class="font-semibold text-brand-dark"><?= htmlspecialchars($scopes[$cert['scope_code']]['name'] ?? $cert['scope_code']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-gray-200/70 pb-2.5">
                        <span class="text-gray-500">Tanggal Terbit:</span>
                        <span class="text-gray-700 font-medium"><?= formatIndonesianDate($cert['issue_date']) ?></span>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="text-gray-500">Masa Berlaku Hingga:</span>
                        <span class="font-bold text-emerald-700"><?= formatIndonesianDate($cert['valid_until']) ?></span>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="flex flex-col sm:flex-row items-center gap-3 justify-center">
                    <a href="print_certificate.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" class="w-full sm:w-auto bg-brand-red text-white px-7 py-3 rounded-full hover:bg-red-800 transition-all font-semibold text-xs text-center shadow-lg shadow-brand-red/30 inline-flex items-center justify-center gap-2">
                        <i class="ph-bold ph-printer text-base"></i>
                        <span>Buka Sertifikat Asli (A4)</span>
                    </a>
                    <a href="index.php" class="w-full sm:w-auto btn-kalpindo-secondary text-xs py-3 px-5 text-center">
                        Ke Beranda
                    </a>
                </div>

            </div>
        <?php endif; ?>

        <div class="text-center mt-8 text-xs text-gray-400">
            &copy; 2026 PT. Kalibrasi Pengujian Indonesia • Terakreditasi KAN ISO/IEC 17025:2017
        </div>
    </div>

</body>
</html>
