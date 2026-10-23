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

// Proteksi Hak Akses: Tim Teknisi, Pengurus Sertifikat (Reviewer), dan Master Admin
requireRole(['SUPER_ADMIN', 'TECHNICIAN', 'CERT_ADMIN'], 'index.php');

$db = getDbConnection();
$scopes = getScopeList();

$selectedInstId = isset($_GET['instrument_id']) ? (int)$_GET['instrument_id'] : 0;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_worksheet') {
    requireRole(['SUPER_ADMIN', 'TECHNICIAN'], 'worksheet.php');
    try {
        $instId = (int)($_POST['instrument_id'] ?? 0);

        // Kunci Integritas Audit ISO/IEC 17025: Lembar kerja yang instrumennya sudah CERTIFIED tidak boleh diedit
        $stmtStatusCheck = $db->prepare("SELECT status FROM instruments WHERE id = ?");
        $stmtStatusCheck->execute([$instId]);
        $currentInstStatus = $stmtStatusCheck->fetchColumn();
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

        $stmtWsCheck = $db->prepare("SELECT id FROM worksheets WHERE instrument_id = ?");
        $stmtWsCheck->execute([$instId]);
        $existingWsId = $stmtWsCheck->fetchColumn();

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

        $calDate = !empty($_POST['calibration_date']) ? $_POST['calibration_date'] : date('Y-m-d');
        $stmtUpdateDate = $db->prepare("UPDATE instruments SET calibration_date = ? WHERE id = ?");
        $stmtUpdateDate->execute([$calDate, $instId]);

        if ($submitToCert) {
            $stmtStatus = $db->prepare("UPDATE instruments SET status = 'DATA_SUBMITTED' WHERE id = ?");
            $stmtStatus->execute([$instId]);
            $db->commit();
            setFlash('success', 'Data mentah lembar kerja BERHASIL diserahkan ke Bagian Sertifikat untuk diterbitkan nomor resmi.');
            header('Location: index.php');
            exit;
        } else {
            $stmtStatus = $db->prepare("UPDATE instruments SET status = 'IN_PROGRESS' WHERE id = ?");
            $stmtStatus->execute([$instId]);
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

$orderIdParam = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($selectedInstId === 0 && $orderIdParam > 0) {
    foreach ($instrumentList as $item) {
        if ((int)$item['order_id'] === $orderIdParam) {
            $selectedInstId = (int)$item['id'];
            break;
        }
    }
}

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

$isCertAdmin = hasRole('CERT_ADMIN');
$isCertified = ($currentInst && ($currentInst['status'] === 'CERTIFIED' || !empty($currentInst['certificate_number'])));
$isReadOnly = ($isCertified || $isCertAdmin);

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

$countTotal = count($instrumentList);
$countPending = 0;
$countCertified = 0;
foreach ($instrumentList as $item) {
    if (in_array($item['status'], ['ASSIGNED', 'IN_PROGRESS'])) {
        $countPending++;
    } else {
        $countCertified++;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Dedicated Worksheet & Queue Styling */
    .ws-queue-card {
        background-color: var(--surface-card);
        border: 1px solid var(--border-subtle);
        border-radius: 0.75rem;
        overflow: hidden;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
    }
    .ws-queue-header {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid var(--border-subtle);
        background-color: var(--surface-subtle);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .ws-queue-search {
        position: relative;
        padding: 0.625rem 0.875rem;
        border-bottom: 1px solid var(--border-subtle);
        background-color: var(--surface-card);
    }
    .ws-queue-search input {
        width: 100%;
        height: 2.25rem;
        padding: 0.375rem 0.75rem 0.375rem 2.25rem;
        font-size: 0.75rem;
        border-radius: 0.5rem;
        border: 1px solid var(--border-strong);
        background-color: var(--surface-subtle);
        color: var(--text-primary);
        outline: none;
        transition: all 0.15s ease;
    }
    .ws-queue-search input:focus {
        border-color: var(--brand-primary);
        background-color: var(--surface-card);
        box-shadow: 0 0 0 2px var(--brand-primary-ring);
    }
    .ws-queue-search .search-icon {
        position: absolute;
        left: 1.5rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: 0.95rem;
        pointer-events: none;
    }
    .ws-queue-tabs {
        display: flex;
        padding: 0.375rem 0.625rem;
        gap: 0.375rem;
        background-color: var(--surface-subtle);
        border-bottom: 1px solid var(--border-subtle);
    }
    .ws-tab-btn {
        flex: 1;
        padding: 0.3125rem 0.375rem;
        font-size: 0.6875rem;
        font-weight: 600;
        border-radius: 0.375rem;
        border: 1px solid transparent;
        background: transparent;
        color: var(--text-muted);
        cursor: pointer;
        transition: all 0.12s ease;
        text-align: center;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.25rem;
        white-space: nowrap;
    }
    .ws-tab-btn:hover {
        color: var(--text-primary);
        background-color: var(--surface-muted);
    }
    .ws-tab-btn.active {
        background-color: var(--surface-card);
        color: var(--brand-primary);
        border-color: var(--border-subtle);
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    }
    .ws-queue-list {
        max-height: 590px;
        overflow-y: auto;
        padding: 0.625rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        scrollbar-width: thin;
        scrollbar-color: var(--border-strong) transparent;
    }
    .ws-queue-list::-webkit-scrollbar {
        width: 5px;
    }
    .ws-queue-list::-webkit-scrollbar-thumb {
        background-color: var(--border-strong);
        border-radius: 4px;
    }
    .ws-queue-item {
        display: block;
        text-decoration: none !important;
        background-color: var(--surface-card);
        border: 1px solid var(--border-subtle);
        border-radius: 0.625rem;
        padding: 0.75rem 0.875rem;
        position: relative;
        overflow: hidden;
        transition: all 0.15s ease;
    }
    .ws-queue-item:hover {
        border-color: var(--border-active);
        background-color: var(--surface-subtle);
        transform: translateY(-1px);
        box-shadow: 0 3px 6px -1px rgba(0, 0, 0, 0.04);
    }
    .ws-queue-item.is-active {
        background-color: rgba(200, 30, 38, 0.04) !important;
        border-color: var(--brand-primary) !important;
        box-shadow: 0 0 0 1px var(--brand-primary), 0 4px 10px -2px rgba(200, 30, 38, 0.15) !important;
    }
    html.dark .ws-queue-item.is-active,
    [data-bs-theme="dark"] .ws-queue-item.is-active {
        background-color: rgba(200, 30, 38, 0.12) !important;
    }
    .ws-queue-item.is-active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background-color: var(--brand-primary);
        border-radius: 4px 0 0 4px;
    }
    .ws-queue-item .item-title {
        font-size: 0.8125rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.35;
        margin-top: 0.3125rem;
    }
    .ws-queue-item .item-customer {
        font-size: 0.6875rem;
        color: var(--text-muted);
        margin-top: 0.1875rem;
        display: flex;
        align-items: center;
        gap: 0.3125rem;
    }
    .ws-queue-item .item-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 0.5rem;
        padding-top: 0.4375rem;
        border-top: 1px solid var(--border-subtle);
    }
    .ws-sn-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-family: var(--font-mono, monospace);
        font-size: 0.625rem;
        font-weight: 600;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        background-color: var(--surface-subtle);
        border: 1px solid var(--border-subtle);
        color: var(--text-secondary);
    }
    .ws-specs-strip {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        border-radius: 0.5rem;
        background-color: var(--surface-subtle);
        border: 1px solid var(--border-subtle);
    }
    @media (min-width: 640px) {
        .ws-specs-strip {
            grid-template-columns: repeat(4, 1fr);
        }
    }
    .ws-spec-box .label {
        display: block;
        font-size: 0.625rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.05em;
        margin-bottom: 0.125rem;
    }
    .ws-spec-box .val {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-primary);
    }
    .ws-env-card {
        padding: 0.875rem;
        border-radius: 0.5rem;
        background-color: var(--surface-subtle);
        border: 1px solid var(--border-subtle);
    }
    .ws-env-card label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.375rem;
    }
    .ws-table th {
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.625rem 0.5rem;
        background-color: var(--surface-subtle);
        color: var(--text-secondary);
        border-bottom: 1px solid var(--border-strong);
        white-space: nowrap;
    }
    .ws-table td {
        padding: 0.4375rem 0.375rem;
        vertical-align: middle;
    }
</style>

<!-- 1. Header Control Bar -->
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="px-2 py-0.5 rounded text-[11px] font-mono font-semibold bg-red-100 text-red-800 border border-red-200 dark:bg-red-950/50 dark:text-red-300 dark:border-red-800/60" style="padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: rgba(200,30,38,0.1); color: #C81E26; border: 1px solid rgba(200,30,38,0.2);">
                LABORATORIUM & PENGUJIAN
            </span>
            <span style="color: var(--text-muted); font-size: 11px;">•</span>
            <span style="color: var(--text-muted); font-size: 12px; font-family: var(--font-mono, monospace);">Input Data Mentah & Ketidakpastian</span>
        </div>
        <h1 class="h3 fw-bold mb-1" style="color: var(--text-primary); letter-spacing: -0.02em;">Lembar Kerja Kalibrasi (Worksheet)</h1>
        <p class="mb-0 text-muted" style="font-size: 0.8125rem;">
            Pencatatan kondisi lingkungan uji lab/site, kalibrator acuan tertelusur, dan rekapitulasi data mentah teknisi sesuai standar ISO/IEC 17025.
        </p>
    </div>
    
    <div class="d-flex align-items-center gap-2 shrink-0">
        <?php if ($isCertAdmin): ?>
            <span class="badge-status info">
                <i class="ph-bold ph-shield-check"></i>
                <span>Mode Tinjau Pengurus Sertifikat</span>
            </span>
        <?php elseif ($isCertified): ?>
            <span class="badge-status success">
                <i class="ph-bold ph-lock-key"></i>
                <span>Terkunci (Sertifikat Terbit)</span>
            </span>
        <?php else: ?>
            <span class="badge-status info">
                <span class="badge-dot"></span>
                <span>Mode Input Teknisi</span>
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if ($isCertAdmin): ?>
    <div class="mb-4 p-3 rounded-lg d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 border" style="background-color: rgba(59, 130, 246, 0.08); border-color: rgba(59, 130, 246, 0.25);">
        <div class="d-flex align-items-center gap-2.5">
            <div class="w-8 h-8 rounded-circle d-flex align-items-center justify-content-center shrink-0" style="background: rgba(59, 130, 246, 0.15); color: #2563EB; width: 36px; height: 36px;">
                <i class="ph-bold ph-shield-check" style="font-size: 1.25rem;"></i>
            </div>
            <div>
                <div class="fw-bold" style="font-size: 0.8125rem; color: #1D4ED8;">
                    Mode Tinjau Pengurus Sertifikat (Read-Only)
                </div>
                <div class="text-muted" style="font-size: 0.75rem;">
                    Anda sedang memeriksa data parameter teknisi dan rekapitulasi data ukur mentah. Seluruh input dikunci untuk menjaga integritas data ISO/IEC 17025.
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 shrink-0">
            <a href="certificates.php" class="btn-surface text-xs" style="height: 32px; padding: 0 12px; display: inline-flex; align-items: center; gap: 6px;">
                <i class="ph-bold ph-arrow-left"></i>
                <span>Kembali ke Sertifikat</span>
            </a>
            <?php if ($currentInst && $currentInst['status'] === 'DATA_SUBMITTED'): ?>
                <a href="certificates.php?instrument_id=<?= (int)$currentInst['id'] ?>" class="btn-brand-primary text-xs" style="height: 32px; padding: 0 12px; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="ph-bold ph-certificate"></i>
                    <span>Terbitkan Sertifikat Resmi &rarr;</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- 2. Two-Column Workbench Layout -->
<div class="row g-4 align-items-start">

    <!-- Left Column (4/12 or 5/12 on large screens): Instrument Queue -->
    <div class="col-12 col-xl-4 col-lg-5">
        <div class="ws-queue-card">
            
            <!-- Queue Header -->
            <div class="ws-queue-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="ph-bold ph-tray" style="color: var(--brand-primary); font-size: 1.1rem;"></i>
                    <h2 class="mb-0 fw-bold" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-primary);">
                        Antrean Alat Teknisi
                    </h2>
                </div>
                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size: 0.6875rem; font-family: monospace;">
                    <span id="queue-visible-count"><?= $countTotal ?></span> / <?= $countTotal ?> alat
                </span>
            </div>

            <!-- Queue Search Filter -->
            <div class="ws-queue-search">
                <i class="ph-bold ph-magnifying-glass search-icon"></i>
                <input 
                    type="text" 
                    id="queue-search" 
                    oninput="filterQueue()" 
                    placeholder="Cari nama alat, SN, SPK, pelanggan..."
                    autocomplete="off"
                >
            </div>

            <!-- Queue Status Tabs -->
            <div class="ws-queue-tabs">
                <button type="button" class="ws-tab-btn active" data-tab="all" onclick="filterQueue('all')">
                    Semua (<?= $countTotal ?>)
                </button>
                <button type="button" class="ws-tab-btn" data-tab="pending" onclick="filterQueue('pending')">
                    <span class="badge-dot bg-warning" style="width: 6px; height: 6px; border-radius: 50%; display: inline-block; background-color: #F59E0B;"></span>
                    Perlu Uji (<?= $countPending ?>)
                </button>
                <button type="button" class="ws-tab-btn" data-tab="certified" onclick="filterQueue('certified')">
                    <span class="badge-dot bg-success" style="width: 6px; height: 6px; border-radius: 50%; display: inline-block; background-color: #10B981;"></span>
                    Selesai (<?= $countCertified ?>)
                </button>
            </div>

            <!-- Scrollable Queue List of Cards -->
            <div class="ws-queue-list" id="queue-items-container">
                <?php if (empty($instrumentList)): ?>
                    <div style="padding: 2.5rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.75rem;">
                        <i class="ph-bold ph-tray" style="font-size: 2rem; margin-bottom: 0.5rem; display: block; opacity: 0.5;"></i>
                        Belum ada alat yang ditugaskan ke teknisi.
                    </div>
                <?php endif; ?>

                <?php foreach ($instrumentList as $item): ?>
                    <?php 
                        $isSelected = ($item['id'] === $selectedInstId);
                        $isItemKan = ((int)($item['is_kan'] ?? 1) === 1);
                        $isItemCertified = in_array($item['status'], ['CERTIFIED', 'DATA_SUBMITTED']);
                    ?>
                    <a 
                        href="worksheet.php?instrument_id=<?= $item['id'] ?>" 
                        class="ws-queue-item <?= $isSelected ? 'is-active' : '' ?>"
                        data-status="<?= htmlspecialchars($item['status']) ?>"
                        data-search="<?= htmlspecialchars(strtolower($item['name'] . ' ' . $item['order_number'] . ' ' . $item['serial_number'] . ' ' . $item['customer_name'])) ?>"
                        title="Klik untuk memilih alat ini"
                    >
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="font-mono fw-bold" style="font-size: 0.75rem; color: <?= $isSelected ? 'var(--brand-primary)' : 'var(--text-primary)' ?>;">
                                <?= htmlspecialchars($item['order_number']) ?>
                            </span>
                            <div class="d-flex align-items-center gap-1">
                                <span style="font-family: monospace; font-size: 10px; color: var(--text-muted); font-weight: 600;">[<?= htmlspecialchars($item['scope_code']) ?>]</span>
                                <?= renderAccreditationBadge($isItemKan) ?>
                            </div>
                        </div>
                        
                        <div class="item-title">
                            <?= htmlspecialchars($item['name']) ?>
                        </div>
                        
                        <div class="item-customer">
                            <i class="ph-bold ph-buildings" style="font-size: 11px;"></i>
                            <span class="text-truncate"><?= htmlspecialchars($item['customer_name']) ?></span>
                        </div>
                        
                        <div class="item-footer">
                            <span class="ws-sn-pill">
                                SN: <?= htmlspecialchars($item['serial_number']) ?>
                            </span>
                            <div class="d-flex align-items-center gap-1">
                                <?= renderStatusBadge($item['status']) ?>
                                <?php if ($isSelected): ?>
                                    <span style="font-size: 9px; font-weight: 700; color: #FFFFFF; background-color: var(--brand-primary); padding: 1px 6px; border-radius: 4px; text-transform: uppercase;">
                                        Aktif
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>

                <!-- Empty Search Notice -->
                <div id="queue-empty-search" style="display: none; padding: 2rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.75rem;">
                    <i class="ph-bold ph-magnifying-glass" style="font-size: 1.5rem; margin-bottom: 0.5rem; display: block; opacity: 0.5;"></i>
                    Tidak ada instrumen yang cocok dengan pencarian.
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column (8/12 or 7/12 on large screens): Worksheet Form -->
    <div class="col-12 col-xl-8 col-lg-7">
        <?php if (!$currentInst): ?>
            <div class="ent-card p-5 text-center" style="color: var(--text-muted); padding: 3.5rem 1.5rem !important;">
                <i class="ph-bold ph-tray" style="font-size: 3rem; margin-bottom: 0.75rem; display: block; color: var(--text-muted); opacity: 0.4;"></i>
                <h4 class="fw-bold mb-1" style="font-size: 0.9375rem; color: var(--text-primary);">Pilih Alat untuk Membuka Lembar Kerja</h4>
                <p class="mb-0" style="font-size: 0.75rem; max-width: 360px; margin: 0 auto;">Silakan klik salah satu alat dari daftar antrean di sebelah kiri untuk mengisi data pengukuran kalibrasi.</p>
            </div>
        <?php else: ?>
            
            <form action="worksheet.php" method="POST" class="ent-card p-4 p-sm-5" style="display: flex; flex-direction: column; gap: 1.5rem;" id="worksheet-form">
                <input type="hidden" name="action" value="save_worksheet">
                <input type="hidden" name="instrument_id" value="<?= $currentInst['id'] ?>">

                <!-- Audit Lock Notice Banner -->
                <?php if ($isCertified): ?>
                    <div style="background-color: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3); color: #B45309; padding: 0.875rem 1rem; border-radius: 0.5rem; display: flex; align-items: flex-start; gap: 0.625rem; font-size: 0.75rem;">
                        <i class="ph-bold ph-lock-key" style="font-size: 1.2rem; flex-shrink: 0; margin-top: 0.125rem;"></i>
                        <div>
                            <p class="fw-bold mb-0" style="color: #92400E;">Lembar Kerja Terkunci (Read-Only Audit)</p>
                            <p class="mb-0 mt-1" style="line-height: 1.4;">
                                Sertifikat resmi telah diterbitkan dengan Nomor: <span style="font-family: monospace; font-weight: 700;"><?= htmlspecialchars($currentInst['certificate_number'] ?? '-') ?></span>.
                                Sesuai klausul audit integritas data ISO/IEC 17025, data mentah tidak dapat diedit langsung.
                            </p>
                        </div>
                    </div>
                <?php elseif ($isCertAdmin): ?>
                    <div style="background-color: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.3); color: #1D4ED8; padding: 0.875rem 1rem; border-radius: 0.5rem; display: flex; flex-direction: column; gap: 0.75rem;" class="flex-sm-row align-items-sm-center justify-content-between">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="ph-bold ph-shield-check" style="font-size: 1.35rem; flex-shrink: 0; color: #2563EB;"></i>
                            <div>
                                <p class="fw-bold mb-0" style="color: #1E40AF; font-size: 0.8125rem;">Mode Tinjau Pengurus Sertifikat (Read-Only)</p>
                                <p class="mb-0 mt-0.5" style="line-height: 1.4; color: #2563EB; font-size: 0.6875rem;">
                                    Anda sedang meninjau parameter dan data mentah teknisi sebelum menetapkan nomor sertifikat resmi.
                                </p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 shrink-0">
                            <a href="certificates.php?tab=queue" class="btn-surface" style="height: 32px; padding: 0 12px; font-size: 11px;">
                                <i class="ph-bold ph-arrow-left"></i>
                                <span>Ke Antrean</span>
                            </a>
                            <?php if ($currentInst['status'] === 'DATA_SUBMITTED'): ?>
                                <a href="certificates.php?instrument_id=<?= (int)$currentInst['id'] ?>" class="btn-brand-primary" style="height: 32px; padding: 0 12px; font-size: 11px;">
                                    <i class="ph-bold ph-certificate"></i>
                                    <span>Terbitkan Nomor &rarr;</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Instrument Top Identity Bar -->
                <div style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 1rem;">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span style="font-family: var(--font-mono, monospace); font-size: 0.75rem; font-weight: 700; background-color: var(--surface-subtle); padding: 0.25rem 0.625rem; border-radius: 0.375rem; border: 1px solid var(--border-subtle); color: var(--text-primary);">
                                <?= htmlspecialchars($currentInst['order_number']) ?>
                            </span>
                            <?= renderLocationBadge($currentInst['service_type']) ?>
                            <?= renderAccreditationBadge((int)($currentInst['is_kan'] ?? 1) === 1) ?>
                        </div>
                        <?= renderStatusBadge($currentInst['status']) ?>
                    </div>

                    <h2 class="h4 fw-bold mb-1" style="color: var(--text-primary); letter-spacing: -0.02em;">
                        <?= htmlspecialchars($currentInst['name']) ?>
                    </h2>
                    <p class="mb-3 text-muted" style="font-size: 0.75rem;">
                        Pelanggan: <span class="fw-semibold" style="color: var(--text-secondary);"><?= htmlspecialchars($currentInst['customer_name']) ?></span> · 
                        Alamat: <?= htmlspecialchars($currentInst['customer_address'] ?: '-') ?>
                    </p>

                    <!-- Compact Specs Strip -->
                    <div class="ws-specs-strip">
                        <div class="ws-spec-box">
                            <span class="label">Merk / Tipe</span>
                            <span class="val"><?= htmlspecialchars($currentInst['brand'] ?: '-') ?> / <?= htmlspecialchars($currentInst['model_type'] ?: '-') ?></span>
                        </div>
                        <div class="ws-spec-box">
                            <span class="label">Nomor Seri (SN)</span>
                            <span class="val font-mono fw-bold" style="color: var(--brand-primary);"><?= htmlspecialchars($currentInst['serial_number']) ?></span>
                        </div>
                        <div class="ws-spec-box">
                            <span class="label">Kapasitas / Resolusi</span>
                            <span class="val"><?= htmlspecialchars($currentInst['capacity_range'] ?: '-') ?> (<?= htmlspecialchars($currentInst['resolution'] ?: '-') ?>)</span>
                        </div>
                        <div class="ws-spec-box">
                            <span class="label">Teknisi Bertugas</span>
                            <span class="val"><?= htmlspecialchars($currentInst['technician_name'] ?: '-') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Section 1: Kondisi Lingkungan -->
                <div>
                    <div style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.5rem; margin-bottom: 0.875rem;">
                        <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-primary);">
                            1. Kondisi Lingkungan Kalibrasi
                        </span>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-sm-4">
                            <div class="ws-env-card">
                                <label>Tgl Pengujian Kalibrasi <span style="color: var(--brand-primary);">*</span></label>
                                <input 
                                    type="date" 
                                    name="calibration_date" 
                                    required 
                                    <?= $isReadOnly ? 'disabled' : '' ?> 
                                    value="<?= htmlspecialchars((string)($currentInst['calibration_date'] ?: date('Y-m-d'))) ?>" 
                                    class="ent-input font-medium"
                                    style="height: 38px; width: 100%; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                                >
                            </div>
                        </div>

                        <div class="col-12 col-sm-4">
                            <div class="ws-env-card">
                                <label>Suhu Ruangan Lab / Site (°C)</label>
                                <div class="d-flex align-items-center gap-1.5">
                                    <input 
                                        type="number" 
                                        step="0.1" 
                                        name="temperature" 
                                        <?= $isReadOnly ? 'disabled' : '' ?> 
                                        value="<?= htmlspecialchars((string)($currentWs['temperature'] ?? 20.0)) ?>" 
                                        class="ent-input text-center font-mono fw-bold"
                                        style="width: 75px; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                                    >
                                    <span style="color: var(--text-muted); font-size: 0.8125rem;">±</span>
                                    <input 
                                        type="number" 
                                        step="0.1" 
                                        name="temperature_uncertainty" 
                                        <?= $isReadOnly ? 'disabled' : '' ?> 
                                        value="<?= htmlspecialchars((string)($currentWs['temperature_uncertainty'] ?? 1.0)) ?>" 
                                        class="ent-input text-center font-mono"
                                        style="width: 65px; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                                    >
                                    <span style="color: var(--text-muted); font-size: 0.75rem; font-family: monospace;">°C</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-4">
                            <div class="ws-env-card">
                                <label>Kelembaban Relatif (% RH)</label>
                                <div class="d-flex align-items-center gap-1.5">
                                    <input 
                                        type="number" 
                                        step="0.1" 
                                        name="humidity" 
                                        <?= $isReadOnly ? 'disabled' : '' ?> 
                                        value="<?= htmlspecialchars((string)($currentWs['humidity'] ?? 55.0)) ?>" 
                                        class="ent-input text-center font-mono fw-bold"
                                        style="width: 75px; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                                    >
                                    <span style="color: var(--text-muted); font-size: 0.8125rem;">±</span>
                                    <input 
                                        type="number" 
                                        step="0.1" 
                                        name="humidity_uncertainty" 
                                        <?= $isReadOnly ? 'disabled' : '' ?> 
                                        value="<?= htmlspecialchars((string)($currentWs['humidity_uncertainty'] ?? 5.0)) ?>" 
                                        class="ent-input text-center font-mono"
                                        style="width: 65px; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                                    >
                                    <span style="color: var(--text-muted); font-size: 0.75rem; font-family: monospace;">% RH</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Standar Acuan -->
                <div>
                    <div style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.5rem; margin-bottom: 0.875rem;">
                        <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-primary);">
                            2. Standar Acuan Tertelusur (Calibrator)
                        </span>
                    </div>

                    <div class="d-flex flex-column gap-3">
                        <div>
                            <label class="form-label mb-1" style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">
                                Nama Alat Standar Acuan & SN <span style="color: var(--brand-primary);">*</span>
                            </label>
                            <input 
                                type="text" 
                                name="standard_calibrator" 
                                <?= $isReadOnly ? 'disabled' : '' ?> 
                                required 
                                value="<?= htmlspecialchars((string)($currentWs['standard_calibrator'] ?? 'Dead Weight Tester Fluke P3000 Series SN: DWT-4412')) ?>" 
                                class="ent-input"
                                style="width: 100%; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                            >
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label class="form-label mb-1" style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">No. Sertifikat Standar</label>
                                <input 
                                    type="text" 
                                    name="standard_cert_no" 
                                    <?= $isReadOnly ? 'disabled' : '' ?> 
                                    value="<?= htmlspecialchars((string)($currentWs['standard_cert_no'] ?? 'CERT-KAN-2025-042')) ?>" 
                                    class="ent-input font-mono"
                                    style="width: 100%; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                                >
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label mb-1" style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Masa Berlaku Kalibrasi Standar</label>
                                <input 
                                    type="date" 
                                    name="standard_valid_until" 
                                    <?= $isReadOnly ? 'disabled' : '' ?> 
                                    value="<?= htmlspecialchars((string)($currentWs['standard_valid_until'] ?? date('Y-m-d', strtotime('+1 year')))) ?>" 
                                    class="ent-input"
                                    style="width: 100%; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                                >
                            </div>
                        </div>

                        <div>
                            <label class="form-label mb-1" style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Metode Kalibrasi (Instruksi Kerja)</label>
                            <input 
                                type="text" 
                                name="calibration_method" 
                                <?= $isReadOnly ? 'disabled' : '' ?> 
                                value="<?= htmlspecialchars((string)($currentWs['calibration_method'] ?? 'Instruksi Kerja Kalibrasi IK-KAL-01 (EURAMET/JIS)')) ?>" 
                                class="ent-input"
                                style="width: 100%; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                            >
                        </div>
                    </div>
                </div>

                <!-- Section 3: Tabel Pembacaan Ukur -->
                <div>
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2" style="border-bottom: 1px solid var(--border-subtle); padding-bottom: 0.5rem; margin-bottom: 0.875rem;">
                        <div class="d-flex align-items-center gap-2">
                            <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-primary);">
                                3. Tabel Rekapitulasi Data Mentah
                            </span>
                            <span style="font-family: monospace; font-size: 11px; color: var(--text-muted);">(Koreksi = Standar - Rata-rata)</span>
                        </div>

                        <?php if (!$isReadOnly && hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                            <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                <button type="button" onclick="autoFillWorksheetDemo('<?= $currentInst['scope_code'] ?>')" class="btn-surface" style="font-size: 11px; padding: 4px 10px; height: 30px;">
                                    <i class="ph-bold ph-magic-wand"></i>
                                    <span>Contoh Ukur</span>
                                </button>
                                <button type="button" onclick="addWorksheetRow()" class="btn-surface" style="font-size: 11px; padding: 4px 10px; height: 30px;">
                                    <i class="ph-bold ph-plus"></i>
                                    <span>Tambah Titik</span>
                                </button>
                                <button type="button" onclick="removeWorksheetRow()" class="btn-surface text-danger" style="font-size: 11px; padding: 4px 10px; height: 30px;">
                                    <i class="ph-bold ph-trash"></i>
                                    <span>Hapus Baris</span>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive" style="border: 1px solid var(--border-subtle); border-radius: 0.5rem; overflow: hidden;">
                        <table id="worksheet-table" class="ws-table w-100" style="margin-bottom: 0;">
                            <thead>
                                <tr>
                                    <th>Titik Nominal</th>
                                    <th class="text-end">Nilai Standar</th>
                                    <th class="text-end">Run 1</th>
                                    <th class="text-end">Run 2</th>
                                    <th class="text-end">Run 3</th>
                                    <th class="text-end">Rata-rata (X̄)</th>
                                    <th class="text-end">Koreksi</th>
                                    <th class="text-end">U95 (k=2)</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono" style="font-size: 12px;">
                                <?php foreach ($readings as $idx => $r): ?>
                                    <tr class="reading-calc-row">
                                        <td>
                                            <input type="text" name="points[]" <?= $isReadOnly ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['point']) ?>" class="ent-input" style="width: 95px; height: 32px; font-size: 11px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>">
                                        </td>
                                        <td class="text-end">
                                            <input type="number" step="any" name="standards[]" <?= $isReadOnly ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['standard']) ?>" class="std-val ent-input text-end" style="width: 85px; height: 32px; font-size: 11px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>">
                                        </td>
                                        <td class="text-end">
                                            <input type="number" step="any" name="run1[]" <?= $isReadOnly ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['run1']) ?>" class="r1-val ent-input text-end" style="width: 75px; height: 32px; font-size: 11px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>">
                                        </td>
                                        <td class="text-end">
                                            <input type="number" step="any" name="run2[]" <?= $isReadOnly ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['run2']) ?>" class="r2-val ent-input text-end" style="width: 75px; height: 32px; font-size: 11px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>">
                                        </td>
                                        <td class="text-end">
                                            <input type="number" step="any" name="run3[]" <?= $isReadOnly ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['run3']) ?>" class="r3-val ent-input text-end" style="width: 75px; height: 32px; font-size: 11px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>">
                                        </td>
                                        <td class="text-end">
                                            <input type="text" readonly value="<?= number_format((float)$r['mean'], 4, '.', '') ?>" class="mean-val ent-input text-end fw-semibold" style="width: 80px; height: 32px; font-size: 11px; background-color: var(--surface-subtle); color: var(--text-primary); cursor: not-allowed;">
                                        </td>
                                        <td class="text-end">
                                            <input type="text" readonly value="<?= ($r['correction'] >= 0 ? '+' : '') . number_format((float)$r['correction'], 4, '.', '') ?>" class="corr-val ent-input text-end fw-bold" style="width: 80px; height: 32px; font-size: 11px; background-color: var(--surface-subtle); color: <?= $r['correction'] >= 0 ? '#10B981' : '#EF4444' ?>; cursor: not-allowed;">
                                        </td>
                                        <td class="text-end">
                                            <input type="number" step="any" name="uncertainties[]" <?= $isReadOnly ? 'disabled' : '' ?> value="<?= htmlspecialchars((string)$r['uncertainty']) ?>" class="ent-input text-end" style="width: 75px; height: 32px; font-size: 11px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Section 4: Catatan & Visual -->
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label mb-1" style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Pemeriksaan Visual / Kondisi Fisik</label>
                        <input 
                            type="text" 
                            name="visual_inspection" 
                            <?= $isReadOnly ? 'disabled' : '' ?> 
                            value="<?= htmlspecialchars((string)($currentWs['visual_inspection'] ?? 'Fisik bersih, display jernih, berfungsi normal')) ?>" 
                            class="ent-input"
                            style="width: 100%; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                        >
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label mb-1" style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Catatan Tambahan Teknisi</label>
                        <input 
                            type="text" 
                            name="technician_notes" 
                            <?= $isReadOnly ? 'disabled' : '' ?> 
                            value="<?= htmlspecialchars((string)($currentWs['technician_notes'] ?? 'Pengambilan data lancar, siap diterbitkan sertifikat.')) ?>" 
                            class="ent-input"
                            style="width: 100%; height: 38px; <?= $isReadOnly ? 'opacity: 0.6; cursor: not-allowed;' : '' ?>"
                        >
                    </div>
                </div>

                <!-- Action Toolbar -->
                <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-3 pt-3" style="border-top: 1px solid var(--border-subtle);">
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted" style="font-size: 0.75rem;">Status Alur Saat Ini:</span>
                        <?= renderStatusBadge($currentInst['status']) ?>
                    </div>

                    <div class="d-flex align-items-center gap-2 w-100 w-sm-auto justify-content-end">
                        <?php if ($isCertified): ?>
                            <a href="print_certificate.php?cert=<?= urlencode((string)($currentInst['certificate_number'] ?? '')) ?>&instrument_id=<?= (int)$currentInst['id'] ?>" target="_blank" class="btn-brand-primary" style="height: 38px; padding: 0 16px; font-size: 12px;">
                                <i class="ph-bold ph-printer"></i>
                                <span>Cetak Sertifikat Resmi</span>
                            </a>
                        <?php elseif (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                            <button type="submit" class="btn-surface" style="height: 38px; padding: 0 16px; font-size: 12px;">
                                <i class="ph-bold ph-floppy-disk"></i>
                                <span>Simpan Draf</span>
                            </button>

                            <button type="submit" name="submit_to_certificate" value="1" class="btn-brand-primary" style="height: 38px; padding: 0 16px; font-size: 12px;">
                                <i class="ph-bold ph-paper-plane-right"></i>
                                <span>Serahkan ke Bagian Sertifikat &rarr;</span>
                            </button>
                        <?php else: ?>
                            <?php if ($currentInst['status'] === 'DATA_SUBMITTED' && hasRole('CERT_ADMIN')): ?>
                                <a href="certificates.php?instrument_id=<?= (int)$currentInst['id'] ?>" class="btn-brand-primary" style="height: 38px; padding: 0 16px; font-size: 12px;">
                                    <i class="ph-bold ph-certificate"></i>
                                    <span>Terbitkan Sertifikat Resmi &rarr;</span>
                                </a>
                            <?php else: ?>
                                <span class="badge-status neutral">
                                    <i class="ph-bold ph-lock-key"></i>
                                    <span>Mode Tinjau (Read-Only)</span>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </form>

        <?php endif; ?>
    </div>

</div>

<script>
    // Client-side Queue Search & Status Filter
    window.currentQueueTab = 'all';

    function filterQueue(statusFilter = null) {
        if (statusFilter !== null) {
            window.currentQueueTab = statusFilter;
            document.querySelectorAll('.ws-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === statusFilter);
            });
        }
        
        const activeTab = window.currentQueueTab || 'all';
        const searchVal = (document.getElementById('queue-search')?.value || '').toLowerCase().trim();
        const items = document.querySelectorAll('.ws-queue-item');

        let visibleCount = 0;
        items.forEach(item => {
            const itemStatus = item.dataset.status || '';
            const searchData = item.dataset.search || (item.innerText || '').toLowerCase();
            
            let matchesTab = true;
            if (activeTab === 'pending') {
                matchesTab = (itemStatus === 'ASSIGNED' || itemStatus === 'IN_PROGRESS');
            } else if (activeTab === 'certified') {
                matchesTab = (itemStatus === 'CERTIFIED' || itemStatus === 'DATA_SUBMITTED');
            }

            const matchesSearch = !searchVal || searchData.includes(searchVal);

            if (matchesTab && matchesSearch) {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        const countBadge = document.getElementById('queue-visible-count');
        if (countBadge) {
            countBadge.textContent = visibleCount;
        }

        const emptyNotice = document.getElementById('queue-empty-search');
        if (emptyNotice) {
            emptyNotice.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

