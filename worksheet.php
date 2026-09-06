<?php
/**
 * Modul Teknisi Kalibrasi - Lembar Kerja Data Mentah (Worksheet)
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Clean Modern Enterprise Workbench
 */

declare(strict_types=1);

$pageTitle = 'Lembar Kerja Kalibrasi';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

// Proteksi Hak Akses: Hanya Tim Teknisi dan Master Admin yang berhak mengakses lembar kerja kalibrasi
requireRole(['SUPER_ADMIN', 'TECHNICIAN'], 'index.php');

$db = getDbConnection();
$scopes = getScopeList();

$selectedInstId = isset($_GET['instrument_id']) ? (int)$_GET['instrument_id'] : 0;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_worksheet') {
    requireRole(['SUPER_ADMIN', 'TECHNICIAN'], 'worksheet.php');
    try {
        $instId = (int)($_POST['instrument_id'] ?? 0);

        // Kunci Integritas Audit ISO/IEC 17025: Lembar kerja yang instrumennya sudah CERTIFIED tidak boleh diedit
        $currentInstStatus = $db->query("SELECT status FROM instruments WHERE id = {$instId}")->fetchColumn();
        if ($currentInstStatus === 'CERTIFIED') {
            throw new Exception("Instrumen ini telah diterbitkan sertifikat resmi ('CERTIFIED'). Lembar kerja dikunci secara permanen (Read-Only) sesuai standar audit ISO/IEC 17025. Jika diperlukan koreksi, terbitkan amandemen/revisi sertifikat pada modul Sertifikat.");
        }

        $temp = (float)($_POST['temperature'] ?? 20.0);
        $tempU = (float)($_POST['temperature_uncertainty'] ?? 1.0);
        $humidity = (float)($_POST['humidity'] ?? 55.0);
        $humidityU = (float)($_POST['humidity_uncertainty'] ?? 5.0);
        $standardCal = trim($_POST['standard_calibrator'] ?? '');
        $standardCert = trim($_POST['standard_cert_no'] ?? '');
        $standardValid = $_POST['standard_valid_until'] ?? null;
        $calibMethod = trim($_POST['calibration_method'] ?? '');
        $visual = trim($_POST['visual_inspection'] ?? 'Normal & Berfungsi Baik');
        $notes = trim($_POST['technician_notes'] ?? '');
        $submitToCert = isset($_POST['submit_to_certificate']);

        $points = $_POST['points'] ?? [];
        $standards = $_POST['standards'] ?? [];
        $run1 = $_POST['run1'] ?? [];
        $run2 = $_POST['run2'] ?? [];
        $run3 = $_POST['run3'] ?? [];
        $uncertainties = $_POST['uncertainties'] ?? [];

        $readingsData = [];
        for ($i = 0; $i < count($points); $i++) {
            if (trim((string)$points[$i]) === '') continue;
            
            $stdVal = (float)($standards[$i] ?? 0);
            $r1Val = (float)($run1[$i] ?? 0);
            $r2Val = (float)($run2[$i] ?? 0);
            $r3Val = (float)($run3[$i] ?? 0);
            $meanVal = round(($r1Val + $r2Val + $r3Val) / 3, 4);
            $corrVal = round($stdVal - $meanVal, 4);
            $uVal = (float)($uncertainties[$i] ?? 0.005);

            $readingsData[] = [
                'point' => trim((string)$points[$i]),
                'standard' => $stdVal,
                'run1' => $r1Val,
                'run2' => $r2Val,
                'run3' => $r3Val,
                'mean' => $meanVal,
                'correction' => $corrVal,
                'uncertainty' => $uVal
            ];
        }

        $readingsJson = json_encode($readingsData, JSON_PRETTY_PRINT);

        $db->beginTransaction();

        $existingWsId = $db->query("SELECT id FROM worksheets WHERE instrument_id = {$instId}")->fetchColumn();

        if ($existingWsId) {
            $stmt = $db->prepare("
                UPDATE worksheets SET
                    temperature = ?, temperature_uncertainty = ?,
                    humidity = ?, humidity_uncertainty = ?,
                    standard_calibrator = ?, standard_cert_no = ?, standard_valid_until = ?,
                    calibration_method = ?, readings_json = ?, visual_inspection = ?, technician_notes = ?,
                    submitted_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $temp, $tempU, $humidity, $humidityU,
                $standardCal, $standardCert, $standardValid,
                $calibMethod, $readingsJson, $visual, $notes,
                $existingWsId
            ]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO worksheets (
                    instrument_id, temperature, temperature_uncertainty,
                    humidity, humidity_uncertainty, standard_calibrator, standard_cert_no,
                    standard_valid_until, calibration_method, readings_json, visual_inspection, technician_notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $instId, $temp, $tempU, $humidity, $humidityU,
                $standardCal, $standardCert, $standardValid,
                $calibMethod, $readingsJson, $visual, $notes
            ]);
        }

        if ($submitToCert) {
            $db->exec("UPDATE instruments SET status = 'DATA_SUBMITTED' WHERE id = {$instId}");
            $db->commit();
            setFlash('success', 'Data mentah lembar kerja BERHASIL diserahkan ke Bagian Sertifikat untuk diterbitkan nomor resmi.');
            header('Location: index.php');
            exit;
        } else {
            $db->exec("UPDATE instruments SET status = 'IN_PROGRESS' WHERE id = {$instId}");
            $db->commit();
            setFlash('success', 'Draf lembar kerja kalibrasi berhasil disimpan.');
            header("Location: worksheet.php?instrument_id={$instId}");
            exit;
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'Gagal menyimpan lembar kerja: ' . $e->getMessage());
    }
}

// Fetch list of instruments
$instrumentList = $db->query("
    SELECT 
        i.*,
        o.order_number,
        o.customer_name,
        o.service_type,
        w.id as worksheet_id,
        w.submitted_at
    FROM instruments i
    JOIN orders o ON i.order_id = o.id
    LEFT JOIN worksheets w ON w.instrument_id = i.id
    ORDER BY 
        CASE 
            WHEN i.status = 'DATA_SUBMITTED' THEN 2
            WHEN i.status = 'CERTIFIED' THEN 3
            ELSE 1
        END,
        i.id DESC
")->fetchAll();

if ($selectedInstId === 0 && !empty($instrumentList)) {
    $firstPending = array_filter($instrumentList, fn($x) => in_array($x['status'], ['ASSIGNED', 'IN_PROGRESS']));
    if (!empty($firstPending)) {
        $selectedInstId = (int)reset($firstPending)['id'];
    } else {
        $selectedInstId = (int)$instrumentList[0]['id'];
    }
}

$currentInst = null;
$currentWs = null;
if ($selectedInstId > 0) {
    $stmt = $db->prepare("
        SELECT 
            i.*,
            o.order_number,
            o.customer_name,
            o.customer_address,
            o.service_type,
            o.order_date,
            c.certificate_number
        FROM instruments i
        JOIN orders o ON i.order_id = o.id
        LEFT JOIN certificates c ON c.instrument_id = i.id
        WHERE i.id = ?
    ");
    $stmt->execute([$selectedInstId]);
    $currentInst = $stmt->fetch();

    if ($currentInst) {
        $stmtWs = $db->prepare("SELECT * FROM worksheets WHERE instrument_id = ?");
        $stmtWs->execute([$selectedInstId]);
        $currentWs = $stmtWs->fetch();
    }
}

$isCertified = ($currentInst && ($currentInst['status'] === 'CERTIFIED' || !empty($currentInst['certificate_number'])));

$readings = [];
if ($currentWs && !empty($currentWs['readings_json'])) {
    $readings = json_decode($currentWs['readings_json'], true) ?: [];
}

if (empty($readings)) {
    $unit = 'unit';
    if ($currentInst) {
        $sc = $currentInst['scope_code'];
        if ($sc === 'P') $unit = 'bar';
        elseif ($sc === 'T' || $sc === 'S') $unit = '°C';
        elseif ($sc === 'M') $unit = 'g';
        elseif ($sc === 'D') $unit = 'mm';
        elseif ($sc === 'E') $unit = 'V';
        elseif ($sc === 'V') $unit = 'ml';
    }
    $readings = [
        ['point' => "0.00 {$unit}", 'standard' => 0.00, 'run1' => 0.00, 'run2' => 0.00, 'run3' => 0.00, 'mean' => 0.00, 'correction' => 0.00, 'uncertainty' => 0.005],
        ['point' => "2.50 {$unit}", 'standard' => 2.50, 'run1' => 2.50, 'run2' => 2.50, 'run3' => 2.50, 'mean' => 2.50, 'correction' => 0.00, 'uncertainty' => 0.005],
        ['point' => "5.00 {$unit}", 'standard' => 5.00, 'run1' => 5.00, 'run2' => 5.00, 'run3' => 5.00, 'mean' => 5.00, 'correction' => 0.00, 'uncertainty' => 0.006],
        ['point' => "7.50 {$unit}", 'standard' => 7.50, 'run1' => 7.50, 'run2' => 7.50, 'run3' => 7.50, 'mean' => 7.50, 'correction' => 0.00, 'uncertainty' => 0.007],
        ['point' => "10.00 {$unit}", 'standard' => 10.00, 'run1' => 10.00, 'run2' => 10.00, 'run3' => 10.00, 'mean' => 10.00, 'correction' => 0.00, 'uncertainty' => 0.008]
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header Control Bar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-xl font-bold text-slate-900 tracking-tight">Lembar Kerja Kalibrasi (Worksheet)</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            Pencatatan kondisi lingkungan uji, kalibrator acuan tertelusur, dan rekapitulasi data mentah teknisi.
        </p>
    </div>
    <?php if ($isCertified): ?>
        <div>
            <span class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 font-medium text-xs inline-flex items-center gap-1.5 border border-slate-200">
                <i class="ph-bold ph-lock-key text-slate-500"></i>
                <span>Terkunci (Sertifikat Terbit)</span>
            </span>
        </div>
    <?php endif; ?>
</div>

<!-- Two-Column Workbench Layout -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

    <!-- Left Column (4 of 12 = ~33%): Instrument List -->
    <div class="lg:col-span-4 space-y-4">
        <div class="bg-white rounded-xl border border-slate-200/80 shadow-subtle overflow-hidden">
            <div class="p-3.5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                <h2 class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Antrean Alat Teknisi</h2>
                <span class="text-xs text-slate-500 font-medium"><?= count($instrumentList) ?> alat</span>
            </div>

            <div class="divide-y divide-slate-100 max-h-[640px] overflow-y-auto">
                <?php if (empty($instrumentList)): ?>
                    <div class="p-6 text-center text-slate-400 text-xs">Belum ada alat yang ditugaskan.</div>
                <?php endif; ?>

                <?php foreach ($instrumentList as $item): ?>
                    <?php 
                        $isSelected = ($item['id'] === $selectedInstId);
                        $isItemKan = ((int)($item['is_kan'] ?? 1) === 1);
                    ?>
                    <a href="worksheet.php?instrument_id=<?= $item['id'] ?>" class="block p-3.5 transition-colors text-left <?= $isSelected ? 'bg-slate-50/90 border-l-3 border-l-slate-900' : 'hover:bg-slate-50/50' ?>">
                        <div class="flex items-center justify-between mb-1">
                            <span class="font-mono text-xs font-semibold <?= $isSelected ? 'text-slate-900' : 'text-slate-700' ?>">
                                <?= htmlspecialchars($item['order_number']) ?>
                            </span>
                            <span class="font-mono text-[11px] text-slate-500">
                                [<?= htmlspecialchars($item['scope_code']) ?>] <?= $isItemKan ? 'KAN' : 'Non-KAN' ?>
                            </span>
                        </div>
                        
                        <h4 class="font-medium text-xs text-slate-900 line-clamp-1"><?= htmlspecialchars($item['name']) ?></h4>
                        <p class="text-[11px] text-slate-500 mt-0.5 truncate"><?= htmlspecialchars($item['customer_name']) ?></p>
                        
                        <div class="flex items-center justify-between mt-2.5 pt-2 border-t border-slate-100/70 text-[11px]">
                            <span class="font-mono text-[10px] text-slate-400">SN: <?= htmlspecialchars($item['serial_number']) ?></span>
                            <?= renderStatusBadge($item['status']) ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right Column (8 of 12 = ~67%): Worksheet Digital Form -->
    <div class="lg:col-span-8">
        <?php if (!$currentInst): ?>
            <div class="bg-white rounded-xl border border-slate-200/80 p-12 text-center text-slate-400 text-xs shadow-subtle">
                <i class="ph-bold ph-tray text-3xl text-slate-300 mb-2 block"></i>
                <p>Silakan pilih salah satu alat pada antrean di sebelah kiri untuk membuka lembar kerja kalibrasi.</p>
            </div>
        <?php else: ?>
            
            <form action="worksheet.php" method="POST" class="bg-white rounded-xl border border-slate-200/80 shadow-subtle p-5 sm:p-6 space-y-6 text-xs">
                <input type="hidden" name="action" value="save_worksheet">
                <input type="hidden" name="instrument_id" value="<?= $currentInst['id'] ?>">

                <!-- Audit Lock Notice Banner -->
                <?php if ($isCertified): ?>
                    <div class="bg-slate-50 border border-slate-200 text-slate-700 p-3.5 rounded-lg flex items-start gap-2.5">
                        <i class="ph-bold ph-lock-key text-base text-slate-500 shrink-0 mt-0.5"></i>
                        <div class="text-xs">
                            <p class="font-semibold text-slate-900">Lembar Kerja Terkunci (Read-Only)</p>
                            <p class="text-[11px] text-slate-500 mt-0.5">
                                Sertifikat resmi telah diterbitkan dengan Nomor: <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars($currentInst['certificate_number'] ?? '-') ?></span>.
                                Demi kepatuhan audit ISO/IEC 17025, data mentah tidak dapat dimodifikasi langsung.
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Instrument Top Identity Bar -->
                <div class="border-b border-slate-100 pb-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs font-semibold text-slate-900 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                                <?= htmlspecialchars($currentInst['order_number']) ?>
                            </span>
                            <?= renderLocationBadge($currentInst['service_type']) ?>
                            <span class="text-xs text-slate-600 font-medium">
                                Lingkup: <?= htmlspecialchars($scopes[$currentInst['scope_code']]['name'] ?? $currentInst['scope_code']) ?>
                                (<?= ((int)($currentInst['is_kan'] ?? 1) === 1) ? 'KAN' : 'Non-KAN' ?>)
                            </span>
                        </div>
                        <?= renderStatusBadge($currentInst['status']) ?>
                    </div>

                    <h2 class="text-base font-bold text-slate-900 tracking-tight mt-1"><?= htmlspecialchars($currentInst['name']) ?></h2>
                    <p class="text-slate-500 text-xs mt-0.5">
                        Pelanggan: <span class="font-semibold text-slate-700"><?= htmlspecialchars($currentInst['customer_name']) ?></span> • 
                        Alamat: <?= htmlspecialchars($currentInst['customer_address'] ?: '-') ?>
                    </p>

                    <!-- Compact Specs Strip -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 bg-slate-50 px-3.5 py-2.5 rounded-lg border border-slate-200 text-[11px]">
                        <div>
                            <span class="text-slate-400 block text-[10px] uppercase font-medium">Merk / Tipe</span>
                            <span class="font-medium text-slate-800"><?= htmlspecialchars($currentInst['brand'] ?: '-') ?> / <?= htmlspecialchars($currentInst['model_type'] ?: '-') ?></span>
                        </div>
                        <div>
                            <span class="text-slate-400 block text-[10px] uppercase font-medium">Nomor Seri (SN)</span>
                            <span class="font-mono font-medium text-slate-900"><?= htmlspecialchars($currentInst['serial_number']) ?></span>
                        </div>
                        <div>
                            <span class="text-slate-400 block text-[10px] uppercase font-medium">Kapasitas / Resolusi</span>
                            <span class="font-medium text-slate-800"><?= htmlspecialchars($currentInst['capacity_range'] ?: '-') ?> (<?= htmlspecialchars($currentInst['resolution'] ?: '-') ?>)</span>
                        </div>
                        <div>
                            <span class="text-slate-400 block text-[10px] uppercase font-medium">Teknisi Bertugas</span>
                            <span class="font-medium text-slate-900"><?= htmlspecialchars($currentInst['technician_name'] ?: '-') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Section 1: Kondisi Lingkungan -->
                <div class="space-y-3">
                    <div class="border-b border-slate-100 pb-2">
                        <span class="text-[11px] font-semibold text-slate-900 uppercase tracking-wider">1. Kondisi Lingkungan Kalibrasi</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-slate-50/60 p-3.5 rounded-lg border border-slate-200">
                            <label class="block font-medium text-slate-700 mb-1.5">Suhu Ruangan Lab / Site (°C)</label>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.1" name="temperature" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['temperature'] ?? 20.0)) ?>" class="bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 w-20 text-center font-mono font-medium focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                <span class="text-slate-400 text-xs">±</span>
                                <input type="number" step="0.1" name="temperature_uncertainty" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['temperature_uncertainty'] ?? 1.0)) ?>" class="bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 w-16 text-center font-mono focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                <span class="text-slate-500 text-xs">°C</span>
                            </div>
                        </div>

                        <div class="bg-slate-50/60 p-3.5 rounded-lg border border-slate-200">
                            <label class="block font-medium text-slate-700 mb-1.5">Kelembaban Relatif (% RH)</label>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.1" name="humidity" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['humidity'] ?? 55.0)) ?>" class="bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 w-20 text-center font-mono font-medium focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                <span class="text-slate-400 text-xs">±</span>
                                <input type="number" step="0.1" name="humidity_uncertainty" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['humidity_uncertainty'] ?? 5.0)) ?>" class="bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 w-16 text-center font-mono focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                <span class="text-slate-500 text-xs">% RH</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Standar Acuan -->
                <div class="space-y-3">
                    <div class="border-b border-slate-100 pb-2">
                        <span class="text-[11px] font-semibold text-slate-900 uppercase tracking-wider">2. Standar Acuan Tertelusur (Calibrator)</span>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1">Nama Alat Standar Acuan & SN <span class="text-rose-500">*</span></label>
                            <input type="text" name="standard_calibrator" <?= $isCertified ? 'disabled' : '' ?> required value="<?= htmlspecialchars((string)($currentWs['standard_calibrator'] ?? 'Dead Weight Tester Fluke P3000 Series SN: DWT-4412')) ?>" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">No. Sertifikat Standar</label>
                                <input type="text" name="standard_cert_no" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['standard_cert_no'] ?? 'CERT-KAN-2025-042')) ?>" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                            </div>
                            <div>
                                <label class="block font-medium text-slate-700 mb-1">Masa Berlaku Kalibrasi Standar</label>
                                <input type="date" name="standard_valid_until" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['standard_valid_until'] ?? date('Y-m-d', strtotime('+1 year')))) ?>" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                            </div>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1">Metode Kalibrasi (Instruksi Kerja)</label>
                            <input type="text" name="calibration_method" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['calibration_method'] ?? 'Instruksi Kerja Kalibrasi IK-KAL-01 (EURAMET/JIS)')) ?>" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                        </div>
                    </div>
                </div>

                <!-- Section 3: Tabel Pembacaan Ukur -->
                <div class="space-y-3">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-2">
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-semibold text-slate-900 uppercase tracking-wider">3. Tabel Rekapitulasi Data Mentah</span>
                            <span class="font-mono text-[10px] text-slate-400 hidden sm:inline">(Koreksi = Standar - Rata-rata)</span>
                        </div>

                        <?php if (!$isCertified && hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <button type="button" onclick="autoFillWorksheetDemo('<?= $currentInst['scope_code'] ?>')" class="px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 font-medium text-[11px] transition-colors">
                                    Contoh Ukur
                                </button>
                                <button type="button" onclick="addWorksheetRow()" class="px-2.5 py-1 rounded bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 font-medium text-[11px] transition-colors">
                                    + Tambah Titik
                                </button>
                                <button type="button" onclick="removeWorksheetRow()" class="px-2.5 py-1 rounded bg-white hover:bg-rose-50 text-rose-700 border border-slate-300 font-medium text-[11px] transition-colors">
                                    Hapus
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="overflow-x-auto border border-slate-200 rounded-lg">
                        <table id="worksheet-table" class="w-full text-left min-w-[680px]">
                            <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200 text-[11px]">
                                <tr>
                                    <th class="py-2.5 px-3">Titik Nominal</th>
                                    <th class="py-2.5 px-3 text-right">Nilai Standar</th>
                                    <th class="py-2.5 px-2 text-right">Run 1</th>
                                    <th class="py-2.5 px-2 text-right">Run 2</th>
                                    <th class="py-2.5 px-2 text-right">Run 3</th>
                                    <th class="py-2.5 px-2 text-right">Rata-rata (X̄)</th>
                                    <th class="py-2.5 px-2 text-right">Koreksi</th>
                                    <th class="py-2.5 px-3 text-right">U95 (k=2)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-mono text-[11px]">
                                <?php foreach ($readings as $idx => $r): ?>
                                    <tr class="reading-calc-row hover:bg-slate-50/50 transition-colors">
                                        <td class="py-2 px-2.5">
                                            <input type="text" name="points[]" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['point']) ?>" class="bg-white border border-slate-300 rounded px-2 py-1 text-[11px] text-slate-900 w-24 focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                        </td>
                                        <td class="py-2 px-2.5 text-right">
                                            <input type="number" step="any" name="standards[]" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['standard']) ?>" class="std-val bg-white border border-slate-300 rounded px-2 py-1 text-[11px] text-slate-900 w-20 text-right focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                        </td>
                                        <td class="py-2 px-1 text-right">
                                            <input type="number" step="any" name="run1[]" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['run1']) ?>" class="r1-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                        </td>
                                        <td class="py-2 px-1 text-right">
                                            <input type="number" step="any" name="run2[]" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['run2']) ?>" class="r2-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                        </td>
                                        <td class="py-2 px-1 text-right">
                                            <input type="number" step="any" name="run3[]" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['run3']) ?>" class="r3-val bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                        </td>
                                        <td class="py-2 px-1 text-right">
                                            <input type="text" readonly value="<?= number_format((float)$r['mean'], 4, '.', '') ?>" class="mean-val bg-slate-100 border border-slate-200 rounded px-1.5 py-1 text-[11px] text-slate-700 w-18 text-right font-medium cursor-not-allowed">
                                        </td>
                                        <td class="py-2 px-1 text-right">
                                            <input type="text" readonly value="<?= ($r['correction'] >= 0 ? '+' : '') . number_format((float)$r['correction'], 4, '.', '') ?>" class="corr-val bg-slate-100 border border-slate-200 rounded px-1.5 py-1 text-[11px] text-slate-800 w-18 text-right font-medium cursor-not-allowed">
                                        </td>
                                        <td class="py-2 px-2.5 text-right">
                                            <input type="number" step="any" name="uncertainties[]" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['uncertainty']) ?>" class="bg-white border border-slate-300 rounded px-1.5 py-1 text-[11px] text-slate-700 w-18 text-right focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Section 4: Catatan & Visual -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Pemeriksaan Visual / Kondisi Fisik</label>
                        <input type="text" name="visual_inspection" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['visual_inspection'] ?? 'Fisik bersih, display jernih, berfungsi normal')) ?>" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Catatan Tambahan Teknisi</label>
                        <input type="text" name="technician_notes" <?= $isCertified ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)($currentWs['technician_notes'] ?? 'Pengambilan data lancar, siap diterbitkan sertifikat.')) ?>" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 <?= $isCertified ? 'opacity-60 bg-slate-100 cursor-not-allowed' : '' ?>">
                    </div>
                </div>

                <!-- Action Toolbar -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-slate-100">
                    <span class="text-slate-500 text-[11px]">
                        Status Alur: <strong class="text-slate-800"><?= htmlspecialchars($currentInst['status']) ?></strong>
                    </span>

                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <?php if ($isCertified): ?>
                            <a href="print_certificate.php?instrument_id=<?= $currentInst['id'] ?>&action=preview" target="_blank" class="px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 text-xs inline-flex items-center gap-1.5 transition-colors">
                                <i class="ph-bold ph-printer"></i>
                                <span>Cetak Sertifikat Resmi</span>
                            </a>
                        <?php elseif (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                            <button type="submit" class="px-3.5 py-2 rounded-lg bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-medium text-xs transition-colors flex-1 sm:flex-none justify-center">
                                Simpan Draf
                            </button>

                            <button type="submit" name="submit_to_certificate" value="1" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-4 py-2 rounded-lg font-semibold text-xs shadow-subtle transition-colors flex-1 sm:flex-none justify-center inline-flex items-center gap-1.5">
                                <i class="ph-bold ph-paper-plane-right"></i>
                                <span>Serahkan ke Bagian Sertifikat &rarr;</span>
                            </button>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-500 font-medium text-xs inline-flex items-center gap-1.5 border border-slate-200">
                                <i class="ph-bold ph-lock-key text-slate-400"></i>
                                <span>Mode Tinjau</span>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

            </form>

        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
