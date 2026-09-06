<?php
/**
 * Official ISO/IEC 17025 Calibration Certificate View & Print
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Formatted with official PT Kalpindo branding & KAN accreditation
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$certNumber = trim($_GET['cert'] ?? '');

// Jika tidak ada parameter cert, wajib login
if (empty($certNumber)) {
    requireLogin();
    die('Nomor sertifikat tidak ditentukan.');
}

$db = getDbConnection();

$stmt = $db->prepare("
    SELECT 
        c.*,
        i.name as instrument_name,
        i.brand,
        i.model_type,
        i.serial_number,
        i.capacity_range,
        i.resolution,
        i.technician_name,
        i.calibration_date,
        o.order_number,
        o.customer_name,
        o.customer_address,
        o.service_type,
        w.temperature,
        w.temperature_uncertainty,
        w.humidity,
        w.humidity_uncertainty,
        w.standard_calibrator,
        w.standard_cert_no,
        w.standard_valid_until,
        w.calibration_method,
        w.readings_json,
        w.visual_inspection,
        w.technician_notes
    FROM certificates c
    JOIN instruments i ON c.instrument_id = i.id
    JOIN orders o ON i.order_id = o.id
    LEFT JOIN worksheets w ON w.instrument_id = i.id
    WHERE c.certificate_number = ?
");
$stmt->execute([$certNumber]);
$cert = $stmt->fetch();

if (!$cert) {
    // Jika tidak ditemukan secara langsung, periksa apakah revisi
    if (strlen($certNumber) > 3) {
        $baseCertNumber = substr($certNumber, 0, -3);
        $stmtBase = $db->prepare("
            SELECT 
                c.*,
                i.name as instrument_name,
                i.brand,
                i.model_type,
                i.serial_number,
                i.capacity_range,
                i.resolution,
                i.technician_name,
                i.calibration_date,
                o.order_number,
                o.customer_name,
                o.customer_address,
                o.service_type,
                w.temperature,
                w.temperature_uncertainty,
                w.humidity,
                w.humidity_uncertainty,
                w.standard_calibrator,
                w.standard_cert_no,
                w.standard_valid_until,
                w.calibration_method,
                w.readings_json,
                w.visual_inspection,
                w.technician_notes
            FROM certificates c
            JOIN instruments i ON c.instrument_id = i.id
            JOIN orders o ON i.order_id = o.id
            LEFT JOIN worksheets w ON w.instrument_id = i.id
            WHERE c.certificate_number LIKE ?
            ORDER BY c.revision_number DESC
            LIMIT 1
        ");
        $stmtBase->execute(["{$baseCertNumber}-%"]);
        $cert = $stmtBase->fetch();
    }
}

if (!$cert) {
    if (!isLoggedIn()) {
        requireLogin();
    }
    die('Sertifikat dengan nomor ' . htmlspecialchars($certNumber) . ' tidak ditemukan di database.');
}

$scopes = getScopeList();
$scopeInfo = $scopes[$cert['scope_code']] ?? ['name' => $cert['scope_code'], 'desc' => ''];
$readings = json_decode((string)($cert['readings_json'] ?? '[]'), true) ?: [];

// Cek apakah sertifikat berstatus Akreditasi KAN atau Non-KAN (Awalan N)
$isKan = true;
if (isset($cert['is_kan'])) {
    $isKan = ((int)$cert['is_kan'] === 1 && substr($cert['certificate_number'], 0, 1) !== 'N');
} else {
    $isKan = (substr($cert['certificate_number'], 0, 1) !== 'N');
}

// Normalisasi URL untuk QR Code agar terbebas dari backslash di lingkungan Windows
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$rawUri = dirname($_SERVER['PHP_SELF'] ?? '');
$uri = str_replace('\\', '/', $rawUri);
if ($uri === '/' || $uri === '.') {
    $uri = '';
}
$verifyUrl = rtrim($protocol . $host . $uri, '/') . '/verify.php?cert=' . urlencode($cert['certificate_number']);
$qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode($verifyUrl);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sertifikat Kalibrasi No. <?= htmlspecialchars($cert['certificate_number']) ?> - PT Kalpindo</title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/print.css">

    <style>
        @media screen {
            body {
                background-color: #0F172A;
                padding: 24px;
            }
            .certificate-sheet {
                background: #ffffff;
                color: #0f172a;
                width: 210mm;
                min-height: 297mm;
                margin: 0 auto 30px auto;
                padding: 14mm 16mm 14mm 16mm;
                box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.5);
                border-radius: 6px;
                position: relative;
            }
        }
        .cert-table th, .cert-table td {
            border: 1px solid #1e293b;
            padding: 5px 8px;
        }
        .cert-table th {
            background-color: #f8fafc;
            font-weight: 700;
        }
    </style>
</head>
<body>

    <!-- Web UI Action Bar (Hidden on Print) -->
    <div class="no-print max-w-[210mm] mx-auto mb-6 flex flex-wrap items-center justify-between gap-4 bg-slate-900/90 text-slate-200 border border-slate-700/80 px-5 py-3.5 rounded-xl shadow-2xl backdrop-blur text-xs">
        <div class="flex items-center gap-3">
            <?php if (isLoggedIn()): ?>
                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                    <a href="certificates.php" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium flex items-center gap-1.5 transition-colors border border-slate-700">
                        <i class="ph-bold ph-arrow-left"></i> Kembali ke Bagian Sertifikat
                    </a>
                <?php else: ?>
                    <a href="index.php" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium flex items-center gap-1.5 transition-colors border border-slate-700">
                        <i class="ph-bold ph-arrow-left"></i> Kembali ke Dashboard
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="verify.php?cert=<?= urlencode($cert['certificate_number']) ?>" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium flex items-center gap-1.5 transition-colors border border-slate-700">
                    <i class="ph-bold ph-shield-check"></i> Verifikasi Keabsahan
                </a>
            <?php endif; ?>
            
            <span class="text-slate-600">|</span>
            
            <span class="font-mono font-semibold text-white tracking-tight">
                <?= htmlspecialchars($cert['certificate_number']) ?>
            </span>
            <span class="text-slate-400 font-medium text-[11px]">
                (<?= $isKan ? 'Akreditasi KAN LK-088' : 'Non-KAN Tertelusur' ?>)
            </span>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="px-4 py-2 rounded-lg text-xs font-semibold bg-[#C81E26] hover:bg-[#B2151D] text-white flex items-center gap-1.5 shadow-subtle transition-colors" title="Klik untuk mencetak atau simpan sebagai file PDF">
                <i class="ph-bold ph-file-pdf text-sm"></i>
                <span>Cetak / Simpan PDF (A4)</span>
            </button>
        </div>
    </div>

    <!-- Official A4 Calibration Certificate Sheet -->
    <div class="certificate-sheet relative overflow-hidden text-slate-900 font-sans text-[11px] leading-tight">
        
        <!-- Watermark -->
        <div class="cert-watermark uppercase select-none">
            <?= $isKan ? 'KALPINDO' : 'KALPINDO TERTELUSUR' ?>
        </div>

        <!-- 1. KOP RESMI LABORATORIUM -->
        <div class="border-b-2 border-slate-900 pb-3 mb-3 flex items-start justify-between gap-4">
            <!-- Left: Official Logo & Company Info -->
            <div class="flex items-center gap-4">
                <img src="assets/img/logo.png" alt="Logo PT Kalpindo" class="h-14 object-contain">
                <div class="border-l-2 border-slate-300 pl-3">
                    <h1 class="text-sm font-extrabold text-slate-950 uppercase tracking-tight leading-tight">
                        PT. KALIBRASI PENGUJIAN INDONESIA
                    </h1>
                    <p class="text-[9.5px] font-bold text-[#C81E26] tracking-wide uppercase">
                        LABORATORIUM KALIBRASI & PENGUJIAN INDUSTRI
                    </p>
                    <p class="text-[9px] text-slate-600 mt-0.5">
                        Grand Nusa Indah, Blok S2 No. 16, Jl. Transyogi, Cileungsi, Bogor 16820, Indonesia
                    </p>
                    <p class="text-[8.5px] text-slate-500 font-mono">
                        Telp: 08111-620-848 | Email: certificate@kalpindo.co.id | Web: www.kalpindo.co.id
                    </p>
                </div>
            </div>

            <!-- Right: KAN Accreditation or Non-KAN Notice -->
            <?php if ($isKan): ?>
                <div class="text-right flex flex-col items-end">
                    <img src="assets/img/kan.png" alt="Logo KAN" class="h-14 object-contain drop-shadow-sm">
                    <div class="text-[8.5px] font-mono font-extrabold text-slate-900 mt-0.5">LK-088-IDN</div>
                    <span class="text-[7.5px] text-slate-500 font-mono">ISO/IEC 17025:2017</span>
                </div>
            <?php else: ?>
                <div class="text-right flex flex-col items-end justify-center h-14">
                    <div class="border-2 border-dashed border-slate-400 rounded-lg px-3 py-1 bg-slate-50 text-right">
                        <span class="text-[9px] font-black text-slate-900 uppercase tracking-wider block leading-tight">KALIBRASI TERTELUSUR</span>
                        <span class="text-[7.5px] font-mono font-bold text-amber-800 uppercase block">NON-AKREDITASI KAN</span>
                        <span class="text-[7.5px] text-slate-600 font-mono block mt-0.5">Tertelusur SNSU-BSN / SI</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. JUDUL DOKUMEN & NOMOR SERTIFIKAT -->
        <div class="text-center my-3">
            <?php if ($isKan): ?>
                <h2 class="text-sm font-black tracking-wider uppercase underline underline-offset-4 text-slate-900">
                    SERTIFIKAT KALIBRASI
                </h2>
                <p class="text-[10px] italic text-slate-600 font-serif">CERTIFICATE OF CALIBRATION</p>
            <?php else: ?>
                <h2 class="text-sm font-black tracking-wider uppercase underline underline-offset-4 text-slate-900">
                    SERTIFIKAT KALIBRASI TERTELUSUR
                </h2>
                <p class="text-[10px] italic text-slate-600 font-serif">TRACEABLE CERTIFICATE OF CALIBRATION (NON-KAN)</p>
            <?php endif; ?>
            
            <div class="mt-2 inline-flex items-center gap-2 px-3 py-1 border border-slate-900 rounded bg-slate-50">
                <span class="font-semibold text-slate-700 text-[10px]">Nomor Sertifikat / Certificate No :</span>
                <span class="font-mono font-black text-sm text-[#C81E26] tracking-wider">
                    <?= htmlspecialchars($cert['certificate_number']) ?>
                </span>
                <?php if ($cert['revision_number'] !== '00'): ?>
                    <span class="text-[9px] px-1.5 py-0.2 bg-amber-200 text-amber-900 font-bold rounded">
                        (REVISI <?= $cert['revision_number'] ?>)
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3. IDENTITAS ALAT & PELANGGAN (2-COLUMN GRID) -->
        <div class="grid grid-cols-2 gap-3 mb-3 text-[10.5px]">
            
            <!-- Kolom Kiri: Identitas Alat -->
            <div class="border border-slate-700 rounded p-2.5 bg-white">
                <h3 class="font-bold text-[10.5px] uppercase border-b border-slate-300 pb-1 mb-1.5 text-slate-900">
                    1. IDENTITAS INSTRUMEN / <i>INSTRUMENT IDENTIFICATION</i>
                </h3>
                <table class="w-full text-left leading-snug">
                    <tr>
                        <td class="w-32 text-slate-600">Nama Alat / <i>Instrument</i></td>
                        <td class="w-2">:</td>
                        <td class="font-bold text-slate-900"><?= htmlspecialchars($cert['instrument_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Merk / <i>Manufacturer</i></td>
                        <td>:</td>
                        <td><?= htmlspecialchars($cert['brand']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Tipe / <i>Model</i></td>
                        <td>:</td>
                        <td><?= htmlspecialchars($cert['model_type']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">No. Seri / <i>Serial No</i></td>
                        <td>:</td>
                        <td class="font-mono font-bold"><?= htmlspecialchars($cert['serial_number']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Kapasitas / <i>Range</i></td>
                        <td>:</td>
                        <td><?= htmlspecialchars($cert['capacity_range'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Resolusi / <i>Resolution</i></td>
                        <td>:</td>
                        <td><?= htmlspecialchars($cert['resolution'] ?: '-') ?></td>
                    </tr>
                </table>
            </div>

            <!-- Kolom Kanan: Identitas Pelanggan & Info Order -->
            <div class="border border-slate-700 rounded p-2.5 bg-white">
                <h3 class="font-bold text-[10.5px] uppercase border-b border-slate-300 pb-1 mb-1.5 text-slate-900">
                    2. IDENTITAS PELANGGAN / <i>CUSTOMER IDENTIFICATION</i>
                </h3>
                <table class="w-full text-left leading-snug">
                    <tr>
                        <td class="w-32 text-slate-600">Nama / <i>Customer</i></td>
                        <td class="w-2">:</td>
                        <td class="font-bold text-slate-900"><?= htmlspecialchars($cert['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600 align-top">Alamat / <i>Address</i></td>
                        <td class="align-top">:</td>
                        <td class="text-[9.5px] text-slate-800"><?= htmlspecialchars($cert['customer_address'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">No. Order / <i>Order No</i></td>
                        <td>:</td>
                        <td class="font-mono font-bold text-slate-900"><?= htmlspecialchars($cert['order_number']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Tgl Kalibrasi / <i>Cal. Date</i></td>
                        <td>:</td>
                        <td><?= formatIndonesianDate($cert['calibration_date']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Lokasi / <i>Location</i></td>
                        <td>:</td>
                        <td class="font-semibold">
                            <?= $cert['service_type'] === 'ON_SITE' ? 'On-Site (Lokasi Pelanggan)' : 'In-Lab (Laboratorium Kalpindo)' ?>
                        </td>
                    </tr>
                </table>
            </div>

        </div>

        <!-- 4. KONDISI LINGKUNGAN & KETERTELUSURAN STANDAR -->
        <div class="border border-slate-700 rounded p-2.5 mb-3 bg-white text-[10px]">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="font-bold uppercase text-slate-800 block mb-0.5">3. Kondisi Lingkungan / <i>Environmental Conditions</i>:</span>
                    <p class="text-slate-700">
                        Suhu Ruang / <i>Temperature</i> : <strong><?= $cert['temperature'] ?> ± <?= $cert['temperature_uncertainty'] ?> °C</strong><br>
                        Kelembaban / <i>Relative Humidity</i> : <strong><?= $cert['humidity'] ?> ± <?= $cert['humidity_uncertainty'] ?> % RH</strong>
                    </p>
                </div>
                <div>
                    <span class="font-bold uppercase text-slate-800 block mb-0.5">4. Standar Acuan / <i>Traceability Standard</i>:</span>
                    <p class="text-slate-700 leading-tight">
                        <strong><?= htmlspecialchars($cert['standard_calibrator'] ?: '-') ?></strong><br>
                        Sertifikat: <?= htmlspecialchars($cert['standard_cert_no'] ?: '-') ?> | Berlaku s/d: <?= formatIndonesianDate($cert['standard_valid_until']) ?><br>
                        <span class="text-[9px] text-slate-600 italic">Tertelusur ke Satuan SI melalui SNSU - Badan Standardisasi Nasional (BSN)</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- 5. TABEL HASIL KALIBRASI -->
        <div class="mb-3">
            <h3 class="font-bold text-[10.5px] uppercase mb-1 text-slate-900 flex items-center justify-between">
                <span>5. HASIL KALIBRASI / <i>CALIBRATION RESULTS</i></span>
                <span class="text-[9px] font-normal italic text-slate-600">Metode: <?= htmlspecialchars($cert['calibration_method'] ?: 'EURAMET / JIS Guide') ?></span>
            </h3>

            <table class="cert-table w-full text-center text-[10px]">
                <thead>
                    <tr>
                        <th class="py-1 px-2">Titik Ukur / Nominal<br><i>Set Point</i></th>
                        <th class="py-1 px-2">Nilai Standar<br><i>Standard Value</i></th>
                        <th class="py-1 px-2">Pembacaan Alat (X̄)<br><i>Instrument Reading</i></th>
                        <th class="py-1 px-2">Koreksi<br><i>Correction</i></th>
                        <th class="py-1 px-2">Ketidakpastian U95 (k=2)<br><i>Uncertainty</i></th>
                    </tr>
                </thead>
                <tbody class="font-mono">
                    <?php if (empty($readings)): ?>
                        <tr>
                            <td colspan="5" class="py-4 text-center text-slate-500 font-sans italic">Data hasil pengukuran belum diinput.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($readings as $r): ?>
                        <tr>
                            <td class="font-semibold text-slate-900"><?= htmlspecialchars((string)$r['point']) ?></td>
                            <td><?= number_format((float)$r['standard'], 4, '.', '') ?></td>
                            <td><?= number_format((float)$r['mean'], 4, '.', '') ?></td>
                            <td class="font-bold text-slate-900">
                                <?= ($r['correction'] >= 0 ? '+' : '') . number_format((float)$r['correction'], 4, '.', '') ?>
                            </td>
                            <td>± <?= number_format((float)$r['uncertainty'], 4, '.', '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p class="text-[8.5px] text-slate-500 italic mt-1 leading-tight">
                * Koreksi dihitung dari: Nilai Standar - Pembacaan Rata-rata Alat. Nilai sebenarnya = Pembacaan Alat + Koreksi.<br>
                ** Ketidakpastian yang dilaporkan adalah ketidakpastian yang diperluas dengan faktor cakupan k = 2 pada tingkat kepercayaan sekitar 95%.
            </p>
        </div>

        <!-- 6. KESIMPULAN & TANDA TANGAN LEGALITAS -->
        <div class="avoid-break mt-4 border-t border-slate-800 pt-3">
            <div class="flex items-end justify-between gap-4">
                
                <!-- Left: QR Code & Verification Notice -->
                <div class="flex items-center gap-3">
                    <div class="w-20 h-20 border border-slate-400 p-0.5 bg-white rounded flex items-center justify-center shrink-0">
                        <img src="<?= $qrApiUrl ?>" alt="QR Code Verifikasi" class="w-full h-full object-contain">
                    </div>
                    <div class="text-[9px] text-slate-600 max-w-[240px]">
                        <span class="font-bold text-slate-900 block">VERIFIKASI KEASLIAN DOKUMEN:</span>
                        Scan QR Code di samping untuk memverifikasi validitas sertifikat ini secara online pada basis data PT Kalibrasi Pengujian Indonesia (Kalpindo).
                    </div>
                </div>

                <!-- Right: Signature & Stamp -->
                <div class="text-right w-64 relative">
                    <p class="text-[10px] text-slate-700">
                        Bogor, <?= formatIndonesianDate($cert['issue_date']) ?>
                    </p>
                    <p class="text-[10px] font-bold text-slate-900 uppercase mt-0.5">
                        PT. KALIBRASI PENGUJIAN INDONESIA
                    </p>

                    <div class="h-20 flex items-center justify-end relative my-1">
                        <!-- Stempel Kalibrasi Bulat Kalpindo -->
                        <div class="absolute right-10 w-20 h-20 rounded-full border-2 border-dashed border-[#C81E26]/50 text-[#C81E26] flex flex-col items-center justify-center text-[7px] font-bold uppercase rotate-[-12deg] pointer-events-none select-none">
                            <span class="text-[6.5px]">PT KALPINDO</span>
                            <span class="font-black text-[9px] tracking-wider">CALIBRATED</span>
                            <span class="text-[6.5px]"><?= $isKan ? 'ISO/IEC 17025' : 'TERTELUSUR SI' ?></span>
                        </div>
                        
                        <!-- Signature Path -->
                        <svg class="w-32 h-12 text-slate-900 opacity-90 relative z-10 mr-4" viewBox="0 0 200 80" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                            <path d="M 20 60 Q 40 10 70 40 T 110 30 Q 140 70 170 30" />
                            <path d="M 50 35 Q 80 50 150 45" />
                        </svg>
                    </div>

                    <p class="font-bold text-[11px] text-slate-950 underline underline-offset-2">
                        <?= htmlspecialchars($cert['technical_manager']) ?>
                    </p>
                    <p class="text-[9.5px] text-slate-600 font-medium">Manajer Teknis / <i>Technical Manager</i></p>
                </div>

            </div>
        </div>

        <!-- 7. FOOTNOTE AKREDITASI -->
        <div class="mt-4 pt-2 border-t border-slate-300 flex items-center justify-between text-[8px] text-slate-500 font-mono">
            <span>Sertifikat ini tidak boleh digandakan sebagian tanpa persetujuan tertulis dari PT Kalpindo.<?= $isKan ? ' • Akreditasi KAN LK-088-IDN' : ' • Sertifikat Kalibrasi Tertelusur (Non-Akreditasi KAN)' ?></span>
            <span>Halaman 1 dari 1</span>
        </div>

    </div>

</body>
</html>
