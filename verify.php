<?php
/**
 * Halaman Publik Verifikasi Sertifikat (QR Code Scan Destination)
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Clean Modern Enterprise Accreditation Verification
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$certNumber = trim($_GET['cert'] ?? '');
$cert = null;
$wasRevised = false;
$originalQueried = $certNumber;

if ($certNumber !== '') {
    $db = getDbConnection();
    
    // 1. Coba pencarian persis dengan nomor sertifikat
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

    // 2. Jika tidak ditemukan dan memiliki format akhiran -00 / -XX, periksa revisi terbaru
    if (!$cert && strlen($certNumber) > 3) {
        $baseCertNumber = substr($certNumber, 0, -3);
        $stmtBase = $db->prepare("
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
            WHERE c.certificate_number LIKE ?
            ORDER BY c.revision_number DESC
            LIMIT 1
        ");
        $stmtBase->execute(["{$baseCertNumber}-%"]);
        $cert = $stmtBase->fetch();
        if ($cert) {
            $wasRevised = true;
        }
    }
}

$scopes = getScopeList();
$isKan = true;
if ($cert) {
    if (isset($cert['is_kan'])) {
        $isKan = ((int)$cert['is_kan'] === 1 && substr($cert['certificate_number'], 0, 1) !== 'N');
    } else {
        $isKan = (substr($cert['certificate_number'], 0, 1) !== 'N');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keaslian Sertifikat | PT Kalpindo Kalibrasi</title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <!-- Anti-flicker Theme Script -->
    <script>
        (function() {
            try {
                const savedTheme = localStorage.getItem('theme');
                if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="assets/css/custom.css">
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col justify-between py-12 px-4 antialiased">

    <!-- Theme Toggle Button -->
    <div class="fixed top-4 right-4 z-50">
        <button type="button" onclick="toggleTheme()" class="theme-toggle-btn p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white shadow-subtle hover:shadow transition-all" title="Ganti Mode Tema (Terang / Gelap)">
            <i class="ph-bold ph-moon text-base theme-icon-moon"></i>
            <i class="ph-bold ph-sun text-base theme-icon-sun"></i>
        </button>
    </div>

    <div class="max-w-xl mx-auto w-full">
        
        <!-- Brand Header -->
        <div class="text-center mb-8">
            <a href="index.php" class="inline-block mb-3">
                <img src="assets/img/logo.png" alt="Logo Kalpindo" class="h-10 mx-auto object-contain">
            </a>
            <h2 class="text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Verifikasi Keabsahan Sertifikat Kalibrasi</h2>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Basis Data Resmi Laboratorium PT. Kalibrasi Pengujian Indonesia</p>
        </div>

        <?php if (!$cert): ?>
            <!-- Sertifikat Tidak Ditemukan -->
            <div class="ent-card p-8 text-center">
                <div class="w-12 h-12 rounded-full bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto mb-4 border border-rose-200/80 dark:border-rose-900/50">
                    <i class="ph-bold ph-warning-circle text-2xl"></i>
                </div>
                <h3 class="text-base font-bold text-slate-900 dark:text-white mb-1">Sertifikat Tidak Ditemukan</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 max-w-sm mx-auto mb-6">
                    Nomor sertifikat <span class="font-mono text-slate-900 dark:text-white font-semibold"><?= htmlspecialchars($certNumber ?: '-') ?></span> tidak terdaftar dalam basis data resmi laboratorium PT Kalpindo.
                </p>
                <a href="index.php" class="btn-brand-primary text-xs inline-flex items-center gap-1.5">
                    <i class="ph-bold ph-arrow-left"></i>
                    <span>Kembali ke Beranda</span>
                </a>
            </div>
        <?php else: ?>
            <!-- Sertifikat Terverifikasi Sah -->
            <div class="ent-card overflow-hidden">
                
                <!-- Status Top Bar -->
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 text-center bg-slate-50/50 dark:bg-slate-900/50">
                    <div class="w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-3 border border-emerald-200/80 dark:border-emerald-800">
                        <i class="ph-bold ph-check-circle text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white tracking-tight">Dokumen Sertifikat Terverifikasi</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        <?= $isKan ? 'Laboratorium Terakreditasi KAN No. LK-088-IDN (ISO/IEC 17025)' : 'Laboratorium Kalibrasi Tertelusur Satuan Internasional (SI)' ?>
                    </p>
                </div>

                <!-- Revision Notice if Applicable -->
                <?php if ($wasRevised || $cert['revision_number'] !== '00'): ?>
                    <div class="bg-amber-50/70 dark:bg-amber-950/30 px-5 py-3.5 border-b border-amber-200/60 dark:border-amber-900/40 flex items-start gap-2.5 text-xs text-amber-900 dark:text-amber-200">
                        <i class="ph-bold ph-info text-base text-amber-600 dark:text-amber-400 shrink-0 mt-0.5"></i>
                        <div>
                            <p class="font-bold text-amber-900 dark:text-amber-200">Catatan Amandemen / Revisi Resmi</p>
                            <p class="text-[11px] text-amber-800/90 dark:text-amber-300 mt-0.5">
                                Sertifikat ini telah diperbarui menjadi Nomor: <span class="font-mono font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($cert['certificate_number']) ?></span>.
                                <?php if (!empty($cert['revision_notes'])): ?>
                                    <br><span class="font-semibold">Alasan: <?= htmlspecialchars($cert['revision_notes']) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Details Grid -->
                <div class="p-6 space-y-3.5 text-xs">
                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2.5">
                        <span class="text-slate-500 dark:text-slate-400">Nomor Sertifikat</span>
                        <span class="font-mono font-bold text-slate-900 dark:text-white tracking-tight text-xs"><?= htmlspecialchars($cert['certificate_number']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2.5">
                        <span class="text-slate-500 dark:text-slate-400">Status Akreditasi</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">
                            <?= $isKan ? 'Akreditasi KAN (ISO/IEC 17025)' : 'Non-KAN (Tertelusur Satuan SI)' ?>
                        </span>
                    </div>

                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2.5">
                        <span class="text-slate-500 dark:text-slate-400">Nama Instrumen / Alat</span>
                        <span class="font-bold text-slate-900 dark:text-white text-right"><?= htmlspecialchars($cert['instrument_name']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2.5">
                        <span class="text-slate-500 dark:text-slate-400">Merk & Tipe</span>
                        <span class="font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($cert['brand'] ?: '-') ?> <?= htmlspecialchars($cert['model_type'] ?: '') ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2.5">
                        <span class="text-slate-500 dark:text-slate-400">Nomor Seri (SN)</span>
                        <span class="font-mono font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($cert['serial_number']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2.5">
                        <span class="text-slate-500 dark:text-slate-400">Pelanggan / Pemilik</span>
                        <span class="font-semibold text-slate-900 dark:text-white text-right"><?= htmlspecialchars($cert['customer_name']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2.5">
                        <span class="text-slate-500 dark:text-slate-400">Ruang Lingkup</span>
                        <span class="font-medium text-slate-800 dark:text-slate-200">[<?= htmlspecialchars($cert['scope_code']) ?>] <?= htmlspecialchars($scopes[$cert['scope_code']]['name'] ?? $cert['scope_code']) ?></span>
                    </div>

                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-2.5">
                        <span class="text-slate-500 dark:text-slate-400">Tanggal Penerbitan</span>
                        <span class="font-medium text-slate-800 dark:text-slate-200"><?= formatIndonesianDate($cert['issue_date']) ?></span>
                    </div>

                    <div class="flex justify-between items-center">
                        <span class="text-slate-500 dark:text-slate-400">Masa Kalibrasi Ulang</span>
                        <span class="font-bold text-slate-900 dark:text-white"><?= formatIndonesianDate($cert['valid_until']) ?></span>
                    </div>
                </div>

                <!-- Action Footer -->
                <div class="px-6 py-4 bg-slate-50/50 dark:bg-slate-900/50 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <a href="print_certificate.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" class="btn-brand-primary text-xs w-full sm:w-auto inline-flex items-center justify-center gap-1.5">
                        <i class="ph-bold ph-printer text-sm"></i>
                        <span>Buka Lembar Sertifikat Resmi (A4)</span>
                    </a>

                    <a href="index.php" class="text-xs text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white font-medium transition-colors">
                        Masuk Sistem &rarr;
                    </a>
                </div>

            </div>
        <?php endif; ?>

        <div class="text-center mt-8 text-[11px] text-slate-400 dark:text-slate-500">
            &copy; 2026 PT. Kalibrasi Pengujian Indonesia • Laboratorium Kalibrasi ISO/IEC 17025:2017
        </div>
    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>
