<?php
/**
 * Official ISO/IEC 17025 Calibration Certificate View & Print
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Formatted with official PT Kalpindo branding & KAN accreditation
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$certNumber = strtoupper(trim($_GET['cert'] ?? ''));
$db = getDbConnection();

// Dukung parameter instrument_id jika cert kosong (misal navigasi dari lembar kerja)
if (empty($certNumber) && !empty($_GET['instrument_id'])) {
    $targetInstId = (int)$_GET['instrument_id'];
    $stmtFindCert = $db->prepare("SELECT certificate_number FROM certificates WHERE instrument_id = ?");
    $stmtFindCert->execute([$targetInstId]);
    $certNumber = strtoupper((string)($stmtFindCert->fetchColumn() ?: ''));
}

// Jika tidak ada parameter cert atau instrument_id, wajib login
if (empty($certNumber)) {
    requireLogin();
    die('<div style="font-family:sans-serif;padding:30px;text-align:center;color:#b91c1c;"><h3>Nomor sertifikat tidak ditentukan.</h3><p><a href="index.php">Kembali ke Dashboard</a></p></div>');
}

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
    WHERE UPPER(c.certificate_number) = ?
");
$stmt->execute([$certNumber]);
$cert = $stmt->fetch();

if (!$cert) {
    // Jika tidak ditemukan secara langsung, periksa apakah ada revisi terbaru
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
            WHERE UPPER(c.certificate_number) LIKE ?
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
    die('<div style="font-family:sans-serif;padding:30px;text-align:center;color:#b91c1c;"><h3>Sertifikat dengan nomor ' . htmlspecialchars($certNumber) . ' tidak ditemukan di database.</h3><p><a href="index.php">Kembali ke Dashboard</a></p></div>');
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

// Normalisasi URL untuk QR Code agar terbebas dari backslash di Windows dan spasi ter-encode (%20)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$rawUri = dirname($_SERVER['PHP_SELF'] ?? '');
$uri = str_replace('\\', '/', $rawUri);
if ($uri === '/' || $uri === '.') {
    $cleanUri = '';
} else {
    $segments = array_filter(explode('/', $uri), fn($seg) => $seg !== '');
    $cleanUri = !empty($segments) ? '/' . implode('/', array_map('rawurlencode', $segments)) : '';
}
$verifyUrl = rtrim($protocol . $host . $cleanUri, '/') . '/verify.php?cert=' . urlencode($cert['certificate_number']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sertifikat Kalibrasi No. <?= htmlspecialchars($cert['certificate_number']) ?> - PT Kalpindo</title>
    
    <!-- 100% Full Local Stylesheets -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/phosphor.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/print.css">

    <style>
        @media screen {
            body {
                background-color: #0F172A;
                padding: 24px 16px;
                margin: 0;
            }
            .cert-action-bar {
                max-width: 210mm;
                width: 100%;
                margin: 0 auto 24px auto;
                background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
                border: 1px solid #334155;
                color: #F8FAFC;
                border-radius: 12px;
                padding: 12px 20px;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                box-shadow: 0 12px 28px -5px rgba(0, 0, 0, 0.5);
            }
            .cert-btn-back {
                background-color: #1E293B !important;
                color: #F1F5F9 !important;
                border: 1px solid #475569 !important;
                padding: 8px 16px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                text-decoration: none !important;
                transition: all 0.15s ease;
            }
            .cert-btn-back:hover {
                background-color: #334155 !important;
                border-color: #64748B !important;
                color: #FFFFFF !important;
            }
            .cert-btn-print {
                background-color: #C81E26 !important;
                color: #FFFFFF !important;
                border: 1px solid #B2151D !important;
                padding: 10px 22px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(200, 30, 38, 0.4);
                transition: all 0.15s ease;
                letter-spacing: 0.3px;
            }
            .cert-btn-print:hover {
                background-color: #A8181F !important;
                border-color: #8E1319 !important;
                box-shadow: 0 6px 18px rgba(200, 30, 38, 0.55);
                transform: translateY(-1px);
            }
            .cert-btn-print:active {
                transform: translateY(0);
            }
            .certificate-sheet {
                background: #ffffff;
                color: #0f172a;
                width: 210mm;
                min-height: 297mm;
                margin: 0 auto 30px auto;
                padding: 14mm 16mm 14mm 16mm;
                box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.6);
                border-radius: 6px;
                position: relative;
            }
            .cert-watermark {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-35deg);
                font-size: 65pt;
                color: rgba(15, 23, 42, 0.035) !important;
                font-weight: 900;
                letter-spacing: 0.25em;
                pointer-events: none;
                user-select: none;
                z-index: 0;
                white-space: nowrap;
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
    <div class="no-print cert-action-bar">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <?php if (isLoggedIn()): ?>
                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                    <a href="certificates.php" class="cert-btn-back">
                        <i class="ph-bold ph-arrow-left"></i>
                        <span>Kembali ke Bagian Sertifikat</span>
                    </a>
                <?php else: ?>
                    <a href="index.php" class="cert-btn-back">
                        <i class="ph-bold ph-arrow-left"></i>
                        <span>Kembali ke Dashboard</span>
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="verify.php?cert=<?= urlencode($cert['certificate_number']) ?>" class="cert-btn-back">
                    <i class="ph-bold ph-shield-check"></i>
                    <span>Verifikasi Keabsahan</span>
                </a>
            <?php endif; ?>
            
            <span style="color: #64748B; font-size: 15px;">|</span>
            
            <span style="font-family: monospace; font-weight: 800; color: #FFFFFF; font-size: 13px; letter-spacing: 0.5px;">
                <?= htmlspecialchars($cert['certificate_number']) ?>
            </span>
            <span style="color: #94A3B8; font-weight: 500; font-size: 11px;">
                (<?= $isKan ? 'Akreditasi KAN LK-366-IDN' : 'Non-KAN Tertelusur' ?>)
            </span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button type="button" onclick="window.print()" class="cert-btn-print" title="Klik untuk mencetak atau simpan sebagai file PDF">
                <i class="ph-bold ph-printer" style="font-size: 16px;"></i>
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
                    <p class="text-[9.5px] font-bold uppercase" style="color: #C81E26 !important; letter-spacing: 0.5px;">
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
                    <img src="assets/img/kan.png" alt="Logo KAN LK-366-IDN" class="h-14 object-contain" style="max-height: 56px;">
                    <span class="text-[8px] text-slate-600 font-mono mt-0.5 font-semibold">ISO/IEC 17025:2017</span>
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
                <span class="font-mono font-black text-sm tracking-wider" style="color: #C81E26 !important;">
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
                        <td class="font-bold text-slate-900"><?= htmlspecialchars((string)($cert['instrument_name'] ?? '-')) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Merk / <i>Manufacturer</i></td>
                        <td>:</td>
                        <td><?= htmlspecialchars((string)($cert['brand'] ?: '-')) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Tipe / <i>Model</i></td>
                        <td>:</td>
                        <td><?= htmlspecialchars((string)($cert['model_type'] ?: '-')) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">No. Seri / <i>Serial No</i></td>
                        <td>:</td>
                        <td class="font-mono font-bold"><?= htmlspecialchars((string)($cert['serial_number'] ?? '-')) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Kapasitas / <i>Range</i></td>
                        <td>:</td>
                        <td><?= htmlspecialchars((string)($cert['capacity_range'] ?: '-')) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Resolusi / <i>Resolution</i></td>
                        <td>:</td>
                        <td><?= htmlspecialchars((string)($cert['resolution'] ?: '-')) ?></td>
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
                        <td>:</td>
                        <td class="font-bold text-slate-900"><?= htmlspecialchars((string)($cert['customer_name'] ?? '-')) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600 align-top">Alamat / <i>Address</i></td>
                        <td class="align-top">:</td>
                        <td class="text-[9.5px] text-slate-800"><?= htmlspecialchars((string)($cert['customer_address'] ?: '-')) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">No. Order / <i>Order No</i></td>
                        <td>:</td>
                        <td class="font-mono font-bold text-slate-900"><?= htmlspecialchars((string)($cert['order_number'] ?? '-')) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Tgl Kalibrasi / <i>Cal. Date</i></td>
                        <td>:</td>
                        <td><?= formatIndonesianDate($cert['calibration_date'] ?? null) ?></td>
                    </tr>
                    <tr>
                        <td class="text-slate-600">Lokasi / <i>Location</i></td>
                        <td>:</td>
                        <td class="font-semibold">
                            <?= ($cert['service_type'] ?? '') === 'ON_SITE' ? 'On-Site (Lokasi Pelanggan)' : 'In-Lab (Laboratorium Kalpindo)' ?>
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
                        Suhu Ruang / <i>Temperature</i> : <strong><?= htmlspecialchars((string)($cert['temperature'] ?? '20.0')) ?> ± <?= htmlspecialchars((string)($cert['temperature_uncertainty'] ?? '1.0')) ?> °C</strong><br>
                        Kelembaban / <i>Relative Humidity</i> : <strong><?= htmlspecialchars((string)($cert['humidity'] ?? '55.0')) ?> ± <?= htmlspecialchars((string)($cert['humidity_uncertainty'] ?? '5.0')) ?> % RH</strong>
                    </p>
                </div>
                <div>
                    <span class="font-bold uppercase text-slate-800 block mb-0.5">4. Standar Acuan / <i>Traceability Standard</i>:</span>
                    <p class="text-slate-700 leading-tight">
                        <strong><?= htmlspecialchars((string)($cert['standard_calibrator'] ?: '-')) ?></strong><br>
                        Sertifikat: <?= htmlspecialchars((string)($cert['standard_cert_no'] ?: '-')) ?> | Berlaku s/d: <?= formatIndonesianDate($cert['standard_valid_until'] ?? null) ?><br>
                        <span class="text-[9px] text-slate-600 italic">Tertelusur ke Satuan SI melalui SNSU - Badan Standardisasi Nasional (BSN)</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- 5. TABEL HASIL KALIBRASI -->
        <div class="mb-3">
            <h3 class="font-bold text-[10.5px] uppercase mb-1 text-slate-900 flex items-center justify-between">
                <span>5. HASIL KALIBRASI / <i>CALIBRATION RESULTS</i></span>
                <span class="text-[9px] font-normal italic text-slate-600">Metode: <?= htmlspecialchars((string)($cert['calibration_method'] ?: 'EURAMET / JIS Guide')) ?></span>
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

                    <?php foreach ($readings as $r): 
                        $stdVal = (float)($r['standard'] ?? 0);
                        $meanVal = isset($r['mean']) ? (float)$r['mean'] : round(((float)($r['run1'] ?? 0) + (float)($r['run2'] ?? 0) + (float)($r['run3'] ?? 0)) / 3, 4);
                        $corrVal = isset($r['correction']) ? (float)$r['correction'] : round($stdVal - $meanVal, 4);
                        $uVal = (float)($r['uncertainty'] ?? 0.005);
                    ?>
                        <tr>
                            <td class="font-semibold text-slate-900"><?= htmlspecialchars((string)($r['point'] ?? '-')) ?></td>
                            <td><?= number_format($stdVal, 4, '.', '') ?></td>
                            <td><?= number_format($meanVal, 4, '.', '') ?></td>
                            <td class="font-bold text-slate-900">
                                <?= ($corrVal >= 0 ? '+' : '') . number_format($corrVal, 4, '.', '') ?>
                            </td>
                            <td>± <?= number_format($uVal, 4, '.', '') ?></td>
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
                    <div id="qrcode-box" class="w-20 h-20 border border-slate-400 p-1 bg-white rounded flex items-center justify-center shrink-0">
                        <div id="cert-qrcode"></div>
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
                        <div class="absolute right-10 w-20 h-20 rounded-full border-2 border-dashed flex flex-col items-center justify-center text-[7px] font-bold uppercase rotate-[-12deg] pointer-events-none select-none" style="border-color: rgba(200, 30, 38, 0.5) !important; color: #C81E26 !important;">
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
            <span>Sertifikat ini tidak boleh digandakan sebagian tanpa persetujuan tertulis dari PT Kalpindo.<?= $isKan ? ' • Akreditasi KAN LK-366-IDN' : ' • Sertifikat Kalibrasi Tertelusur (Non-Akreditasi KAN)' ?></span>
            <span>Halaman 1 dari 1</span>
        </div>

    </div>

    <!-- Local Offline QR Code Generator -->
    <script src="assets/js/qrcode.min.js"></script>
    <script>
        (function() {
            var qrContainer = document.getElementById("cert-qrcode");
            if (qrContainer) {
                new QRCode(qrContainer, {
                    text: <?= json_encode($verifyUrl) ?>,
                    width: 72,
                    height: 72,
                    colorDark: "#0F172A",
                    colorLight: "#FFFFFF",
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        })();
    </script>
</body>
</html>
