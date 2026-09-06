<?php
/**
 * Modul Teknisi Kalibrasi - Lembar Kerja Data Mentah (Worksheet)
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Structured Enterprise Workbench
 */

declare(strict_types=1);

$pageTitle = '2. Lembar Kerja Kalibrasi';
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
            setFlash('success', 'Data mentah lembar kerja BERHASIL diserahkan ke Bagian Sertifikat untuk dibuatkan nomor resmi!');
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
        setFlash('error', 'Gagal menyimpan: ' . $e->getMessage());
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

$readings = [];
if ($currentWs && !empty($currentWs['readings_json'])) {
    $readings = json_decode($currentWs['readings_json'], true) ?: [];
}

if (empty($readings)) {
    $unit = 'unit';
    if ($currentInst) {
        $sc = $currentInst['scope_code'];
        if ($sc === 'P') $unit = 'bar';
        elseif ($sc === 'S') $unit = '°C';
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
        <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
            <span>Portal Karyawan</span>
            <span>/</span>
            <span>Laboratorium Teknisi</span>
            <span>/</span>
            <span class="text-slate-800 font-semibold">Lembar Kerja Kalibrasi</span>
        </div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Workbench Lembar Kerja Teknisi (Worksheet)</h1>
    </div>
</div>

<!-- Two-Column Workbench Layout -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

    <!-- Left Column (4 of 12 = ~33%): Instrument List -->
    <div class="lg:col-span-4 space-y-4">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-4 sm:p-5">
            <div class="flex items-center justify-between mb-3 border-b border-gray-100 pb-2.5">
                <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Antrean Alat Teknisi</h2>
                <span class="text-[11px] font-mono bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full font-bold"><?= count($instrumentList) ?> Alat</span>
            </div>

            <div class="space-y-2.5 max-h-[640px] overflow-y-auto pr-1">
                <?php foreach ($instrumentList as $item): ?>
                    <?php 
                        $isSelected = ($item['id'] === $selectedInstId);
                        $scInfo = $scopes[$item['scope_code']] ?? ['name' => $item['scope_code'], 'badge_class' => 'bg-gray-100 text-gray-700'];
                    ?>
                    <a href="worksheet.php?instrument_id=<?= $item['id'] ?>" class="block p-3 rounded-xl border transition-all text-left <?= $isSelected ? 'bg-red-50/50 border-[#C81E26] shadow-2xs' : 'bg-gray-50/50 border-gray-200 hover:border-gray-300 hover:bg-white' ?>">
                        <div class="flex items-center justify-between mb-1">
                            <span class="font-mono text-[11px] font-bold text-slate-900"><?= htmlspecialchars($item['order_number']) ?></span>
                            <span class="text-[10px] font-bold px-1.5 py-0.2 rounded border <?= $scInfo['badge_class'] ?>">
                                [<?= htmlspecialchars($item['scope_code']) ?>]
                            </span>
                        </div>
                        <h4 class="font-bold text-xs text-slate-900 <?= $isSelected ? 'text-[#C81E26]' : '' ?>"><?= htmlspecialchars($item['name']) ?></h4>
                        <p class="text-[11px] text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($item['customer_name']) ?></p>
                        
                        <div class="flex items-center justify-between mt-2 text-[11px]">
                            <span class="font-mono text-gray-400 text-[10px]">SN: <?= htmlspecialchars($item['serial_number']) ?></span>
                            <?= renderLocationBadge($item['service_type']) ?>
                        </div>

                        <div class="mt-2 pt-2 border-t border-gray-100 flex items-center justify-between">
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
            <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center text-gray-400">
                <p>Silakan pilih alat di sebelah kiri untuk mengisi lembar kerja.</p>
            </div>
        <?php else: ?>
            
            <form action="worksheet.php" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 sm:p-6 space-y-5 text-xs">
                <input type="hidden" name="action" value="save_worksheet">
                <input type="hidden" name="instrument_id" value="<?= $currentInst['id'] ?>">

                <!-- Instrument Top Identity Bar -->
                <div class="border-b border-gray-100 pb-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-slate-900 bg-gray-100 px-2 py-0.5 rounded">
                                <?= htmlspecialchars($currentInst['order_number']) ?>
                            </span>
                            <?= renderLocationBadge($currentInst['service_type']) ?>
                            <span class="px-2 py-0.5 rounded font-bold border <?= $scopes[$currentInst['scope_code']]['badge_class'] ?>">
                                Lingkup: <?= htmlspecialchars($scopes[$currentInst['scope_code']]['name']) ?>
                            </span>
                        </div>
                        <?= renderStatusBadge($currentInst['status']) ?>
                    </div>

                    <h2 class="text-xl font-black text-slate-900"><?= htmlspecialchars($currentInst['name']) ?></h2>
                    <p class="text-gray-500 text-xs mt-0.5">
                        Customer: <strong class="text-slate-800"><?= htmlspecialchars($currentInst['customer_name']) ?></strong> • 
                        Alamat: <?= htmlspecialchars($currentInst['customer_address'] ?: '-') ?>
                    </p>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mt-3 bg-gray-50 p-3 rounded-xl border border-gray-200 text-[11px]">
                        <div>
                            <span class="text-gray-400 block">Merk / Tipe:</span>
                            <span class="font-semibold text-slate-800"><?= htmlspecialchars($currentInst['brand']) ?> / <?= htmlspecialchars($currentInst['model_type']) ?></span>
                        </div>
                        <div>
                            <span class="text-gray-400 block">Nomor Seri:</span>
                            <span class="font-mono font-bold text-slate-900"><?= htmlspecialchars($currentInst['serial_number']) ?></span>
                        </div>
                        <div>
                            <span class="text-gray-400 block">Kapasitas / Resolusi:</span>
                            <span class="font-medium text-slate-800"><?= htmlspecialchars($currentInst['capacity_range'] ?: '-') ?> (<?= htmlspecialchars($currentInst['resolution'] ?: '-') ?>)</span>
                        </div>
                        <div>
                            <span class="text-gray-400 block">Teknisi Bertugas:</span>
                            <span class="font-bold text-[#C81E26]"><?= htmlspecialchars($currentInst['technician_name']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Section 1: Kondisi Lingkungan -->
                <div class="space-y-2">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="ph-bold ph-thermometer text-amber-600"></i>
                        1. Kondisi Lingkungan Kalibrasi (ISO/IEC 17025)
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="bg-gray-50 p-3.5 rounded-xl border border-gray-200">
                            <label class="block font-semibold text-slate-700 mb-1.5">Suhu Ruangan Lab / Site (°C)</label>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.1" name="temperature" value="<?= htmlspecialchars((string)($currentWs['temperature'] ?? 20.0)) ?>" class="bg-white border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 w-20 text-center font-mono font-bold focus:border-[#C81E26]">
                                <span class="text-gray-400 text-xs">±</span>
                                <input type="number" step="0.1" name="temperature_uncertainty" value="<?= htmlspecialchars((string)($currentWs['temperature_uncertainty'] ?? 1.0)) ?>" class="bg-white border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 w-16 text-center font-mono focus:border-[#C81E26]">
                                <span class="text-gray-500 text-xs">°C</span>
                            </div>
                        </div>

                        <div class="bg-gray-50 p-3.5 rounded-xl border border-gray-200">
                            <label class="block font-semibold text-slate-700 mb-1.5">Kelembaban Relatif (% RH)</label>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.1" name="humidity" value="<?= htmlspecialchars((string)($currentWs['humidity'] ?? 55.0)) ?>" class="bg-white border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 w-20 text-center font-mono font-bold focus:border-[#C81E26]">
                                <span class="text-gray-400 text-xs">±</span>
                                <input type="number" step="0.1" name="humidity_uncertainty" value="<?= htmlspecialchars((string)($currentWs['humidity_uncertainty'] ?? 5.0)) ?>" class="bg-white border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 w-16 text-center font-mono focus:border-[#C81E26]">
                                <span class="text-gray-500 text-xs">% RH</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Standar Acuan -->
                <div class="space-y-2">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="ph-bold ph-shield-check text-blue-600"></i>
                        2. Standar Acuan Tertelusur (Calibrator)
                    </h3>

                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 space-y-2.5">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Nama Alat Standar Acuan & SN *</label>
                            <input type="text" name="standard_calibrator" required value="<?= htmlspecialchars((string)($currentWs['standard_calibrator'] ?? 'Dead Weight Tester Fluke P3000 Series SN: DWT-4412')) ?>" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">No. Sertifikat Standar</label>
                                <input type="text" name="standard_cert_no" value="<?= htmlspecialchars((string)($currentWs['standard_cert_no'] ?? 'CERT-KAN-2025-042')) ?>" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-mono focus:border-[#C81E26]">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-700 mb-1">Masa Berlaku Standar</label>
                                <input type="date" name="standard_valid_until" value="<?= htmlspecialchars((string)($currentWs['standard_valid_until'] ?? date('Y-m-d', strtotime('+1 year')))) ?>" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                            </div>
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Metode Kalibrasi (Instruksi Kerja)</label>
                            <input type="text" name="calibration_method" value="<?= htmlspecialchars((string)($currentWs['calibration_method'] ?? 'Instruksi Kerja Kalibrasi IK-KAL-01 (EURAMET/JIS)')) ?>" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                        </div>
                    </div>
                </div>

                <!-- Section 3: Tabel Pembacaan Ukur -->
                <div class="space-y-2">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                                <i class="ph-bold ph-table text-[#C81E26]"></i>
                                3. Tabel Rekapitulasi Data Mentah
                            </h3>
                            <span class="font-mono text-[10px] text-gray-500 bg-gray-100 px-2 py-0.5 rounded hidden md:inline">Koreksi = Standar - Rata-rata</span>
                        </div>

                        <?php if (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                            <!-- Table Tools -->
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <button type="button" onclick="autoFillWorksheetDemo('<?= $currentInst['scope_code'] ?>')" class="px-2.5 py-1 rounded bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-300 font-bold text-[10px] inline-flex items-center gap-1 transition-colors shadow-2xs">
                                    <i class="ph-bold ph-lightning"></i>
                                    <span>Isi Contoh Ukur</span>
                                </button>
                                <button type="button" onclick="addWorksheetRow()" class="px-2 py-1 rounded bg-white hover:bg-gray-100 text-slate-700 border border-gray-300 font-semibold text-[10px] inline-flex items-center gap-0.5 shadow-2xs">
                                    <i class="ph-bold ph-plus"></i> Tambah Titik
                                </button>
                                <button type="button" onclick="removeWorksheetRow()" class="px-2 py-1 rounded bg-white hover:bg-red-50 text-red-600 border border-gray-300 font-semibold text-[10px] inline-flex items-center gap-0.5 shadow-2xs">
                                    <i class="ph-bold ph-trash"></i> Hapus
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="overflow-x-auto bg-gray-50 p-2.5 rounded-xl border border-gray-200">
                        <table id="worksheet-table" class="w-full text-left">
                            <thead class="bg-white text-gray-600 font-semibold border-b border-gray-200 text-[11px]">
                                <tr>
                                    <th class="py-2 px-2.5">Titik Nominal</th>
                                    <th class="py-2 px-2.5">Nilai Standar</th>
                                    <th class="py-2 px-1.5">Run 1</th>
                                    <th class="py-2 px-1.5">Run 2</th>
                                    <th class="py-2 px-1.5">Run 3</th>
                                    <th class="py-2 px-1.5">Rata-rata (X̄)</th>
                                    <th class="py-2 px-1.5">Koreksi</th>
                                    <th class="py-2 px-2">U95 (k=2)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 font-mono text-[11px]">
                                <?php foreach ($readings as $idx => $r): ?>
                                    <tr class="reading-calc-row hover:bg-white transition-colors">
                                        <td class="py-1.5 px-1.5">
                                            <input type="text" name="points[]" value="<?= htmlspecialchars((string)$r['point']) ?>" class="bg-white border border-gray-300 rounded px-2 py-1 text-[11px] text-slate-900 w-24 focus:border-[#C81E26]">
                                        </td>
                                        <td class="py-1.5 px-1.5">
                                            <input type="number" step="any" name="standards[]" value="<?= htmlspecialchars((string)$r['standard']) ?>" class="std-val bg-white border border-gray-300 rounded px-2 py-1 text-[11px] text-slate-900 w-20 text-right focus:border-[#C81E26]">
                                        </td>
                                        <td class="py-1.5 px-1">
                                            <input type="number" step="any" name="run1[]" value="<?= htmlspecialchars((string)$r['run1']) ?>" class="r1-val bg-white border border-gray-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:border-[#C81E26]">
                                        </td>
                                        <td class="py-1.5 px-1">
                                            <input type="number" step="any" name="run2[]" value="<?= htmlspecialchars((string)$r['run2']) ?>" class="r2-val bg-white border border-gray-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:border-[#C81E26]">
                                        </td>
                                        <td class="py-1.5 px-1">
                                            <input type="number" step="any" name="run3[]" value="<?= htmlspecialchars((string)$r['run3']) ?>" class="r3-val bg-white border border-gray-300 rounded px-1.5 py-1 text-[11px] text-slate-900 w-16 text-right focus:border-[#C81E26]">
                                        </td>
                                        <td class="py-1.5 px-1">
                                            <input type="text" readonly value="<?= number_format((float)$r['mean'], 4, '.', '') ?>" class="mean-val bg-gray-100 border border-gray-200 rounded px-1.5 py-1 text-[11px] text-slate-900 w-18 text-right font-bold cursor-not-allowed">
                                        </td>
                                        <td class="py-1.5 px-1">
                                            <input type="text" readonly value="<?= ($r['correction'] >= 0 ? '+' : '') . number_format((float)$r['correction'], 4, '.', '') ?>" class="corr-val bg-gray-100 border border-gray-200 rounded px-1.5 py-1 text-[11px] <?= $r['correction'] >= 0 ? 'text-emerald-700' : 'text-[#C81E26]' ?> w-18 text-right font-bold cursor-not-allowed">
                                        </td>
                                        <td class="py-1.5 px-1.5">
                                            <input type="number" step="any" name="uncertainties[]" value="<?= htmlspecialchars((string)$r['uncertainty']) ?>" class="bg-white border border-gray-300 rounded px-1.5 py-1 text-[11px] text-amber-800 w-18 text-right focus:border-[#C81E26]">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Section 4: Catatan & Visual -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Pemeriksaan Visual / Fisik</label>
                        <input type="text" name="visual_inspection" value="<?= htmlspecialchars((string)($currentWs['visual_inspection'] ?? 'Fisik bersih, display jernih, berfungsi normal')) ?>" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Catatan Tambahan Teknisi</label>
                        <input type="text" name="technician_notes" value="<?= htmlspecialchars((string)($currentWs['technician_notes'] ?? 'Pengambilan data lancar, siap diterbitkan sertifikat.')) ?>" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                </div>

                <!-- Action Toolbar -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-gray-100">
                    <span class="text-gray-400 text-[11px]">
                        Status Alat: <strong class="text-slate-800"><?= htmlspecialchars($currentInst['status']) ?></strong>
                    </span>

                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <?php if (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                            <button type="submit" class="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-slate-700 font-semibold text-xs flex-1 sm:flex-none justify-center">
                                Simpan Draf Saja
                            </button>

                            <button type="submit" name="submit_to_certificate" value="1" class="bg-[#C81E26] hover:bg-[#A8141B] text-white px-5 py-2 rounded-lg font-semibold text-xs shadow-sm flex-1 sm:flex-none justify-center inline-flex items-center gap-1.5">
                                <i class="ph-bold ph-paper-plane-right"></i>
                                <span>Serahkan ke Bagian Sertifikat &rarr;</span>
                            </button>
                        <?php else: ?>
                            <span class="px-3.5 py-2 rounded-lg bg-gray-100 text-gray-500 font-medium text-xs inline-flex items-center gap-1.5 border border-gray-200">
                                <i class="ph-bold ph-lock-key text-gray-400"></i>
                                <span>Mode Read-Only (Hanya Teknisi / Super Admin)</span>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

            </form>

        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
