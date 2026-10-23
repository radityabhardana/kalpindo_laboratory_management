<?php

declare(strict_types=1);

$pageTitle = 'Penerbitan & Arsip Sertifikat';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

// Proteksi Hak Akses: Hanya Bagian Sertifikat dan Master Admin yang berhak mengakses halaman ini
requireRole(['SUPER_ADMIN', 'CERT_ADMIN'], 'index.php');

$db = getDbConnection();
$scopes = getScopeList();

// Endpoint AJAX untuk live preview generator nomor (mendukung KAN dan Non-KAN awalan N)
if (isset($_GET['ajax_preview'])) {
    header('Content-Type: application/json');
    $sc = strtoupper(trim($_GET['scope'] ?? 'P'));
    if ($sc === 'S') $sc = 'T';
    $dt = $_GET['date'] ?? date('Y-m-d');
    $kan = isset($_GET['is_kan']) ? ((int)$_GET['is_kan'] === 1) : true;
    $res = generateCertificateNumber($db, $sc, $dt, '00', $kan);
    echo json_encode(['certificate_number' => $res['certificate_number']]);
    exit;
}

// Handle Form Submission: Buat Nomor & Terbitkan Sertifikat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. Generate & Issue New Certificate
    if ($_POST['action'] === 'generate_certificate') {
        requireRole(['SUPER_ADMIN', 'CERT_ADMIN'], 'certificates.php');
        try {
            $instrumentId = (int)($_POST['instrument_id'] ?? 0);
            $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
            $validMonths = (int)($_POST['valid_months'] ?? 12);
            $technicalManager = trim($_POST['technical_manager'] ?? 'Ir. Hendra Wijaya, M.T.');

            $stmtInst = $db->prepare("SELECT * FROM instruments WHERE id = ?");
            $stmtInst->execute([$instrumentId]);
            $inst = $stmtInst->fetch();

            if (!$inst) {
                throw new Exception('Data alat tidak ditemukan.');
            }

            // Validasi apakah instrumen sudah memiliki sertifikat terbit
            $stmtCheckExisting = $db->prepare("SELECT certificate_number FROM certificates WHERE instrument_id = ?");
            $stmtCheckExisting->execute([$instrumentId]);
            $existingCertNo = $stmtCheckExisting->fetchColumn();
            if ($existingCertNo) {
                throw new Exception("Instrumen ini sudah memiliki sertifikat terbit ({$existingCertNo}).");
            }

            // Validasi apakah lembar kerja teknisi sudah tersedia
            $stmtCheckWs = $db->prepare("SELECT COUNT(*) FROM worksheets WHERE instrument_id = ?");
            $stmtCheckWs->execute([$instrumentId]);
            if ((int)$stmtCheckWs->fetchColumn() === 0) {
                throw new Exception("Instrumen belum memiliki lembar kerja (worksheet). Teknisi harus menyelesaikan pengujian sebelum sertifikat dapat diterbitkan.");
            }

            // Tentukan status akreditasi KAN vs Non-KAN
            $isKan = isset($_POST['is_kan']) ? (int)$_POST['is_kan'] : (int)($inst['is_kan'] ?? 1);

            $validUntil = date('Y-m-d', strtotime("+{$validMonths} months", strtotime($issueDate)));

            $scopeCode = $inst['scope_code'];
            if ($scopeCode === 'S') $scopeCode = 'T';
            $genResult = generateCertificateNumber($db, $scopeCode, $issueDate, '00', (bool)$isKan);
            $certNumber = $genResult['certificate_number'];

            $db->beginTransaction();

            $stmtCert = $db->prepare("
                INSERT INTO certificates (
                    instrument_id, certificate_number, scope_code, is_kan,
                    year_prefix, month_prefix, sequence_number, revision_number,
                    issue_date, valid_until, technical_manager, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ISSUED')
            ");
            $stmtCert->execute([
                $instrumentId, $certNumber, $scopeCode, $isKan,
                $genResult['year_prefix'], $genResult['month_prefix'],
                $genResult['sequence_number'], $genResult['revision_number'],
                $issueDate, $validUntil, $technicalManager
            ]);

            $stmtUpdInst = $db->prepare("UPDATE instruments SET status = 'CERTIFIED', is_kan = ? WHERE id = ?");
            $stmtUpdInst->execute([$isKan, $instrumentId]);
            
            $orderId = (int)$inst['order_id'];
            $stmtUncompleted = $db->prepare("SELECT COUNT(*) FROM instruments WHERE order_id = ? AND status != 'CERTIFIED'");
            $stmtUncompleted->execute([$orderId]);
            $uncompletedCount = (int)$stmtUncompleted->fetchColumn();
            if ($uncompletedCount === 0) {
                $stmtUpdOrder = $db->prepare("UPDATE orders SET status = 'COMPLETED' WHERE id = ?");
                $stmtUpdOrder->execute([$orderId]);
            }

            $db->commit();

            $kanText = $isKan ? 'Akreditasi KAN' : 'Non-KAN (Awalan N)';
            setFlash('success', "Sertifikat Nomor <strong>{$certNumber}</strong> ({$kanText}) berhasil diterbitkan dan masuk ke Arsip Sertifikat Resmi.");
            header("Location: certificates.php?issued=" . urlencode($certNumber) . "#archive-section");
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', 'Gagal menerbitkan sertifikat: ' . $e->getMessage());
        }
    }

    // 2. Buat Revisi Sertifikat (-01, -02)
    if ($_POST['action'] === 'create_revision') {
        requireRole(['SUPER_ADMIN', 'CERT_ADMIN'], 'certificates.php');
        try {
            $oldCertId = (int)($_POST['certificate_id'] ?? 0);
            $revNotes = trim($_POST['revision_notes'] ?? '');

            $stmtOld = $db->prepare("SELECT * FROM certificates WHERE id = ?");
            $stmtOld->execute([$oldCertId]);
            $oldCert = $stmtOld->fetch();

            if (!$oldCert) {
                throw new Exception('Sertifikat asal tidak ditemukan.');
            }

            $currentRevInt = (int)$oldCert['revision_number'];
            $nextRevStr = str_pad((string)($currentRevInt + 1), 2, '0', STR_PAD_LEFT);

            $baseCertNumber = substr($oldCert['certificate_number'], 0, -3);
            $newCertNumber = "{$baseCertNumber}-{$nextRevStr}";

            $stmtRev = $db->prepare("
                UPDATE certificates SET
                    certificate_number = ?,
                    revision_number = ?,
                    status = 'REVISED',
                    revision_notes = ?
                WHERE id = ?
            ");
            $stmtRev->execute([$newCertNumber, $nextRevStr, $revNotes, $oldCertId]);

            setFlash('success', "Nomor Sertifikat berhasil direvisi menjadi <strong>{$newCertNumber}</strong>.");
            header("Location: certificates.php?issued=" . urlencode($newCertNumber) . "#archive-section");
            exit;

        } catch (Exception $e) {
            setFlash('error', 'Gagal revisi sertifikat: ' . $e->getMessage());
        }
    }
}

// 1. Antrean berkas masuk dari teknisi (status = DATA_SUBMITTED)
$incomingQueue = $db->query("
    SELECT 
        i.*,
        o.order_number,
        o.customer_name,
        o.customer_address,
        o.customer_contact,
        o.order_date,
        o.service_type,
        w.id as ws_id,
        w.submitted_at,
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
    FROM instruments i
    JOIN orders o ON i.order_id = o.id
    JOIN worksheets w ON w.instrument_id = i.id
    WHERE i.status = 'DATA_SUBMITTED'
    ORDER BY w.submitted_at ASC
")->fetchAll();

// Otomatisasi pembukaan modal jika navigasi berasal dari Dashboard (?instrument_id=...)
$autoTargetInst = null;
$autoTargetInstId = isset($_GET['instrument_id']) ? (int)$_GET['instrument_id'] : 0;
if ($autoTargetInstId > 0) {
    foreach ($incomingQueue as $iq) {
        if ((int)$iq['id'] === $autoTargetInstId) {
            $isIqKan = ((int)($iq['is_kan'] ?? 1) === 1);
            $pred = generateCertificateNumber($db, $iq['scope_code'], date('Y-m-d'), '00', $isIqKan);
            $autoTargetInst = [
                'id' => (int)$iq['id'],
                'name' => $iq['name'],
                'customer_name' => $iq['customer_name'],
                'scope_code' => $iq['scope_code'],
                'is_kan' => $isIqKan ? 1 : 0,
                'preview' => $pred['certificate_number']
            ];
            break;
        }
    }
}

// 2. Filter & Sortir Arsip Sertifikat Kalibrasi Resmi Terbit
$searchQuery = trim($_GET['search'] ?? '');
$scopeFilter = trim($_GET['scope'] ?? '');
$kanFilter = trim($_GET['kan'] ?? '');
$sortOption = trim($_GET['sort'] ?? 'newest');
$newlyIssued = trim($_GET['issued'] ?? '');

// Tab Aktif: 'queue' (Antrean Penerbitan) atau 'archive' (Database Arsip)
$activeTab = $_GET['tab'] ?? '';
if (!$activeTab) {
    if ($searchQuery !== '' || $scopeFilter !== '' || $kanFilter !== '' || $sortOption !== 'newest' || $newlyIssued !== '') {
        $activeTab = 'archive';
    } elseif ($autoTargetInstId > 0) {
        $activeTab = 'queue';
    } elseif (count($incomingQueue) > 0) {
        $activeTab = 'queue';
    } else {
        $activeTab = 'archive';
    }
}

$sqlIssued = "
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
        i.scope_code as inst_scope_code,
        o.id as order_id,
        o.order_number,
        o.customer_name,
        o.customer_address,
        o.customer_contact,
        o.order_date,
        o.service_type,
        w.id as ws_id,
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
        w.technician_notes,
        w.submitted_at as ws_submitted_at
    FROM certificates c
    JOIN instruments i ON c.instrument_id = i.id
    JOIN orders o ON i.order_id = o.id
    LEFT JOIN worksheets w ON w.instrument_id = i.id
    WHERE 1=1
";
$paramsIssued = [];

if ($searchQuery !== '') {
    $sqlIssued .= " AND (c.certificate_number LIKE ? OR i.name LIKE ? OR i.serial_number LIKE ? OR o.customer_name LIKE ? OR o.order_number LIKE ?)";
    $like = "%{$searchQuery}%";
    $paramsIssued[] = $like;
    $paramsIssued[] = $like;
    $paramsIssued[] = $like;
    $paramsIssued[] = $like;
    $paramsIssued[] = $like;
}

if ($scopeFilter !== '') {
    $sqlIssued .= " AND c.scope_code = ?";
    $paramsIssued[] = $scopeFilter;
}

if ($kanFilter === '1') {
    $sqlIssued .= " AND (c.is_kan = 1 AND c.certificate_number NOT LIKE 'N%')";
} elseif ($kanFilter === '0') {
    $sqlIssued .= " AND (c.is_kan = 0 OR c.certificate_number LIKE 'N%')";
}

if ($sortOption === 'oldest') {
    $sqlIssued .= " ORDER BY c.id ASC";
} elseif ($sortOption === 'cert_no') {
    $sqlIssued .= " ORDER BY c.certificate_number ASC";
} elseif ($sortOption === 'customer') {
    $sqlIssued .= " ORDER BY o.customer_name ASC";
} else {
    $sqlIssued .= " ORDER BY c.id DESC";
}

$stmtIssued = $db->prepare($sqlIssued);
$stmtIssued->execute($paramsIssued);
$issuedCertificates = $stmtIssued->fetchAll();

$totalIssuedCount = (int)$db->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
$totalKanCount = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE (is_kan = 1 OR is_kan IS NULL) AND certificate_number NOT LIKE 'N%'")->fetchColumn();
$totalNonKanCount = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE is_kan = 0 OR certificate_number LIKE 'N%'")->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<!-- 1. PAGE HEADER (Strong Context & Clear Primary Intent) -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="px-2 py-0.5 rounded text-[11px] font-mono font-semibold bg-red-100 text-red-800 border border-red-200 dark:bg-red-950/50 dark:text-red-300 dark:border-red-800/60">
                MODUL SERTIFIKASI
            </span>
            <span class="text-xs text-slate-400">·</span>
            <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">ISO/IEC 17025 (LK-088-IDN)</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 tracking-tight">Penerbitan & Arsip Sertifikat</h1>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-3xl">
            Kelola verifikasi data mentah teknisi, pembentukan nomor sertifikat standar ISO/IEC 17025, penandatanganan manajer teknis, serta pangkalan data arsip resmi sertifikat terbit.
        </p>
    </div>

    <div class="flex items-center gap-2 shrink-0">
        <a href="certificates.php?tab=<?= urlencode($activeTab) ?>" class="btn-surface text-xs">
            <i class="ph-bold ph-arrows-clockwise text-slate-400"></i>
            <span>Refresh Data</span>
        </a>
    </div>
</div>

<!-- 2. OPERATIONAL SUMMARY (4 Structured KPI Cards - Interactive) -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    
    <!-- KPI 1: Total Issued (Click to open Archive) -->
    <div class="kpi-widget accent-success cursor-pointer hover:shadow-md transition-shadow" onclick="switchCertTab('archive')" title="Klik untuk membuka Database Arsip Sertifikat">
        <div class="flex items-center justify-between">
            <span class="kpi-label">Sertifikat Terbit</span>
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
        </div>
        <div class="kpi-value text-emerald-700 dark:text-emerald-400"><?= $totalIssuedCount ?></div>
        <div class="kpi-subtext">
            <i class="ph-bold ph-check-circle text-emerald-600"></i>
            <span>Dokumen sah & terarsip</span>
        </div>
    </div>

    <!-- KPI 2: Waiting Queue (Click to open Queue) -->
    <div class="kpi-widget <?= count($incomingQueue) > 0 ? 'accent-primary' : 'accent-warning' ?> cursor-pointer hover:shadow-md transition-shadow" onclick="switchCertTab('queue')" title="Klik untuk membuka Antrean Penerbitan">
        <div class="flex items-center justify-between">
            <span class="kpi-label">Menunggu Nomor</span>
            <span class="w-2 h-2 rounded-full <?= count($incomingQueue) > 0 ? 'bg-[#C81E26] animate-pulse' : 'bg-slate-300' ?>"></span>
        </div>
        <div class="kpi-value <?= count($incomingQueue) > 0 ? 'text-[#C81E26] dark:text-red-400' : 'text-slate-700 dark:text-slate-200' ?>">
            <?= count($incomingQueue) ?>
        </div>
        <div class="kpi-subtext">
            <i class="ph-bold ph-hourglass text-amber-600"></i>
            <span>Dari lembar kerja teknisi</span>
        </div>
    </div>

    <!-- KPI 3: KAN Accredited -->
    <div class="kpi-widget accent-primary">
        <div class="flex items-center justify-between">
            <span class="kpi-label">Akreditasi KAN</span>
            <span class="pill-kan text-[9px]">ISO 17025</span>
        </div>
        <div class="kpi-value text-slate-900 dark:text-slate-100"><?= $totalKanCount ?></div>
        <div class="kpi-subtext">
            <i class="ph-bold ph-shield-check text-red-600"></i>
            <span>Sertifikat formal KAN</span>
        </div>
    </div>

    <!-- KPI 4: Non-KAN / Traceable -->
    <div class="kpi-widget accent-info">
        <div class="flex items-center justify-between">
            <span class="kpi-label">Non-KAN (N)</span>
            <span class="pill-non-kan text-[9px]">Tertelusur</span>
        </div>
        <div class="kpi-value text-slate-900 dark:text-slate-100"><?= $totalNonKanCount ?></div>
        <div class="kpi-subtext">
            <i class="ph-bold ph-scales text-sky-600"></i>
            <span>Standar tertelusur nasional</span>
        </div>
    </div>

</div>

<!-- 3. TAB NAVIGATION (Antrean Penerbitan Baru vs Database Arsip) -->
<div class="cert-tab-bar">
    <nav class="cert-tab-nav">
        <button 
            type="button" 
            onclick="switchCertTab('queue')" 
            id="tab-btn-queue" 
            class="cert-tab-link <?= $activeTab === 'queue' ? 'active' : '' ?>"
        >
            <i class="ph-bold ph-tray"></i>
            <span>Antrean Penerbitan Baru</span>
            <span class="badge-status <?= count($incomingQueue) > 0 ? 'danger' : 'neutral' ?>" style="font-size: 10px; padding: 1px 6px;">
                <?= count($incomingQueue) ?>
            </span>
        </button>

        <button 
            type="button" 
            onclick="switchCertTab('archive')" 
            id="tab-btn-archive" 
            class="cert-tab-link <?= $activeTab === 'archive' ? 'active' : '' ?>"
        >
            <i class="ph-bold ph-archive"></i>
            <span>Database Arsip Sertifikat Terbit</span>
            <span class="badge-status success" style="font-size: 10px; padding: 1px 6px;">
                <?= $totalIssuedCount ?>
            </span>
        </button>
    </nav>
</div>

<!-- TAB PANE 1: ANTREAN PENERBITAN BARU -->
<div id="pane-queue" class="<?= $activeTab === 'queue' ? '' : 'hidden' ?>">
<div id="incoming-queue-section" class="ent-card mb-7 scroll-mt-20">
    <div class="ent-card-header">
        <div class="flex items-center gap-2.5">
            <div class="w-2.5 h-2.5 rounded-full <?= count($incomingQueue) > 0 ? 'bg-[#C81E26] animate-pulse' : 'bg-slate-400' ?>"></div>
            <div>
                <h2 class="text-xs font-bold text-slate-900 dark:text-slate-100 uppercase tracking-wider">
                    Antrean Berkas Masuk Teknisi
                </h2>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Lembar kerja selesai uji yang menunggu penetapan nomor sertifikat resmi.</p>
            </div>
        </div>
        <span class="badge-status <?= count($incomingQueue) > 0 ? 'warning' : 'neutral' ?>">
            <span class="badge-dot"></span>
            <span><?= count($incomingQueue) ?> Berkas Menunggu</span>
        </span>
    </div>

    <?php if (empty($incomingQueue)): ?>
        <div class="p-8 text-center">
            <div class="w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 flex items-center justify-center mx-auto mb-3 border border-emerald-200 dark:border-emerald-800">
                <i class="ph-bold ph-check text-xl"></i>
            </div>
            <h4 class="text-xs font-bold text-slate-900 dark:text-slate-100">Semua Berkas Teknisi Selesai Diterbitkan</h4>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 max-w-md mx-auto">
                Tidak ada antrean lembar kerja teknisi saat ini. Berkas baru akan muncul otomatis ketika teknisi menyerahkan data pengukuran.
            </p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="ent-table min-w-[1000px]">
                <thead>
                    <tr>
                        <th class="w-[280px]">Alat & Pelanggan</th>
                        <th class="w-[170px]">Ruang Lingkup & Akreditasi</th>
                        <th class="w-[160px]">Teknisi & Waktu Masuk</th>
                        <th class="w-[140px]">Kondisi Lingkungan</th>
                        <th>Standar Acuan Kalibrator</th>
                        <th class="text-right w-[280px]">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incomingQueue as $item): ?>
                        <?php 
                            $sc = $scopes[$item['scope_code']] ?? ['name' => $item['scope_code']];
                            $isItemKan = ((int)($item['is_kan'] ?? 1) === 1);
                            $predicted = generateCertificateNumber($db, $item['scope_code'], date('Y-m-d'), '00', $isItemKan);
                            $itemReviewData = [
                                'instrument_id' => (int)$item['id'],
                                'order_id' => (int)$item['order_id'],
                                'order_number' => (string)$item['order_number'],
                                'customer_name' => (string)$item['customer_name'],
                                'customer_address' => (string)($item['customer_address'] ?? '-'),
                                'customer_contact' => (string)($item['customer_contact'] ?? '-'),
                                'order_date' => (string)($item['order_date'] ?? '-'),
                                'service_type' => (string)($item['service_type'] ?? 'IN_LAB'),
                                'name' => (string)$item['name'],
                                'brand' => (string)($item['brand'] ?? '-'),
                                'model_type' => (string)($item['model_type'] ?? '-'),
                                'serial_number' => (string)($item['serial_number'] ?? '-'),
                                'capacity_range' => (string)($item['capacity_range'] ?? '-'),
                                'resolution' => (string)($item['resolution'] ?? '-'),
                                'scope_code' => (string)$item['scope_code'],
                                'is_kan' => $isItemKan,
                                'instrument_status' => (string)$item['status'],
                                'ws_id' => (int)($item['ws_id'] ?? 0),
                                'technician_name' => (string)($item['technician_name'] ?? '-'),
                                'calibration_date' => (string)($item['calibration_date'] ?? '-'),
                                'submitted_at' => (string)($item['submitted_at'] ?? '-'),
                                'temperature' => (string)($item['temperature'] ?? '-'),
                                'temperature_uncertainty' => (string)($item['temperature_uncertainty'] ?? '0.5'),
                                'humidity' => (string)($item['humidity'] ?? '-'),
                                'humidity_uncertainty' => (string)($item['humidity_uncertainty'] ?? '3.0'),
                                'standard_calibrator' => (string)($item['standard_calibrator'] ?? '-'),
                                'standard_cert_no' => (string)($item['standard_cert_no'] ?? '-'),
                                'standard_valid_until' => (string)($item['standard_valid_until'] ?? '-'),
                                'calibration_method' => (string)($item['calibration_method'] ?? '-'),
                                'visual_inspection' => (string)($item['visual_inspection'] ?? '-'),
                                'technician_notes' => (string)($item['technician_notes'] ?? '-'),
                                'readings' => json_decode((string)($item['readings_json'] ?? '[]'), true) ?: [],
                                'certificate_number' => '',
                                'predicted_cert' => $predicted['certificate_number'] ?? ''
                            ];
                        ?>
                        <tr>
                            <td>
                                <div class="cell-primary"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="cell-secondary">
                                    <?= htmlspecialchars($item['brand'] ?: '-') ?> · <?= htmlspecialchars($item['model_type'] ?: '-') ?>
                                </div>
                                <div class="cell-meta">
                                    SN: <?= htmlspecialchars($item['serial_number']) ?> · <span class="text-slate-600 dark:text-slate-400 font-medium"><?= htmlspecialchars($item['customer_name']) ?></span>
                                </div>
                            </td>

                            <td class="whitespace-nowrap">
                                <span class="font-mono text-xs font-semibold text-slate-900 dark:text-slate-100">
                                    [<?= htmlspecialchars($item['scope_code']) ?>] <?= htmlspecialchars(explode(' ', $sc['name'])[0]) ?>
                                </span>
                                <div class="mt-1">
                                    <?= renderAccreditationBadge($isItemKan) ?>
                                </div>
                            </td>

                            <td class="whitespace-nowrap">
                                <div class="text-xs font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($item['technician_name'] ?: '-') ?></div>
                                <div class="cell-meta text-slate-400">
                                    <i class="ph-bold ph-clock text-[10px]"></i> <?= htmlspecialchars($item['submitted_at']) ?>
                                </div>
                            </td>

                            <td class="font-mono text-xs text-slate-700 dark:text-slate-300 whitespace-nowrap">
                                <div><span class="text-slate-400">T:</span> <strong><?= $item['temperature'] ?></strong> °C</div>
                                <div><span class="text-slate-400">RH:</span> <strong><?= $item['humidity'] ?></strong> %</div>
                            </td>

                            <td class="text-slate-600 dark:text-slate-300 max-w-[220px] truncate" title="<?= htmlspecialchars($item['standard_calibrator']) ?>">
                                <span class="font-medium"><?= htmlspecialchars($item['standard_calibrator']) ?></span>
                            </td>

                            <td class="text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-1.5 justify-end">
                                    <button type="button" onclick='openReviewModal(<?= json_encode($itemReviewData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)' class="btn-surface btn-compact" title="Tinjau Detail Data SPK & Hasil Pengujian Teknisi">
                                        <i class="ph-bold ph-eye text-slate-500"></i>
                                        <span>Tinjau Data</span>
                                    </button>

                                    <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                        <button onclick="openGenerateModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>', '<?= htmlspecialchars(addslashes($item['customer_name'])) ?>', '<?= $item['scope_code'] ?>', <?= $isItemKan ? 1 : 0 ?>, '<?= $predicted['certificate_number'] ?>')" class="btn-brand-primary btn-compact">
                                            <i class="ph-bold ph-certificate text-xs"></i>
                                            <span>Terbitkan</span>
                                        </button>
                                    <?php else: ?>
                                        <span class="badge-status neutral">Mode Tinjau</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</div>

<!-- TAB PANE 2: DATABASE ARSIP SERTIFIKAT TERBIT -->
<div id="pane-archive" class="<?= $activeTab === 'archive' ? '' : 'hidden' ?>">
<!-- 4. CERTIFICATE DATABASE / ARCHIVE (Arsip Sertifikat Kalibrasi Resmi Terbit) -->
<div id="archive-section" class="ent-card scroll-mt-20">
    
    <!-- Section Header -->
    <div class="ent-card-header flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                <h2 class="text-xs font-bold text-slate-900 dark:text-slate-100 uppercase tracking-wider">
                    Arsip Sertifikat Kalibrasi Resmi Terbit
                </h2>
            </div>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                Database resmi sertifikat terbit standar ISO/IEC 17025 yang telah diverifikasi dan terintegrasi QR code validasi.
            </p>
        </div>
        <div class="text-xs text-slate-600 dark:text-slate-400 font-medium font-mono bg-slate-100 dark:bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-200/80 dark:border-slate-700">
            Total: <strong><?= $totalIssuedCount ?></strong> dokumen (<?= $totalKanCount ?> KAN · <?= $totalNonKanCount ?> Non-KAN)
        </div>
    </div>

    <!-- Integrated Operational Filter Bar -->
    <div class="p-3.5 border-b border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40">
        <form action="certificates.php" method="GET" class="flex flex-wrap items-center justify-between gap-3 text-xs">
            <input type="hidden" name="tab" value="archive">
            
            <div class="flex flex-wrap items-center gap-2.5 flex-1 min-w-[280px]">
                <!-- Search Input -->
                <div class="relative flex-1 min-w-[220px]">
                    <i class="ph-bold ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Cari No. Sertifikat, Alat, Pelanggan, No. Seri..." class="ent-input pl-8">
                </div>

                <!-- Filter Ruang Lingkup -->
                <select name="scope" class="ent-select w-auto">
                    <option value="">Semua Ruang Lingkup</option>
                    <?php foreach ($scopes as $code => $scInfo): ?>
                        <option value="<?= $code ?>" <?= $scopeFilter === $code ? 'selected' : '' ?>>
                            [<?= $code ?>] <?= htmlspecialchars($scInfo['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Filter Status Akreditasi -->
                <select name="kan" class="ent-select w-auto">
                    <option value="">Semua Akreditasi</option>
                    <option value="1" <?= $kanFilter === '1' ? 'selected' : '' ?>>Akreditasi KAN (ISO 17025)</option>
                    <option value="0" <?= $kanFilter === '0' ? 'selected' : '' ?>>Non-KAN (Awalan N)</option>
                </select>

                <!-- Sortir -->
                <select name="sort" class="ent-select w-auto">
                    <option value="newest" <?= $sortOption === 'newest' ? 'selected' : '' ?>>Terbaru</option>
                    <option value="oldest" <?= $sortOption === 'oldest' ? 'selected' : '' ?>>Terlama</option>
                    <option value="cert_no" <?= $sortOption === 'cert_no' ? 'selected' : '' ?>>No. Sertifikat (A-Z)</option>
                    <option value="customer" <?= $sortOption === 'customer' ? 'selected' : '' ?>>Pelanggan (A-Z)</option>
                </select>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <button type="submit" class="btn-brand-primary">
                    <i class="ph-bold ph-faders"></i>
                    <span>Terapkan</span>
                </button>
                <?php if ($searchQuery !== '' || $scopeFilter !== '' || $kanFilter !== '' || $sortOption !== 'newest'): ?>
                    <a href="certificates.php?tab=archive" class="btn-surface">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Structured Data Table (Scannable, Primary vs Secondary Hierarchy) -->
    <div class="overflow-x-auto">
        <table class="ent-table min-w-[1100px]">
            <thead>
                <tr>
                    <th class="w-[240px]">Nomor Sertifikat</th>
                    <th class="w-[250px]">Instrumen & Identifikasi</th>
                    <th class="w-[220px]">Pelanggan & SPK</th>
                    <th class="w-[180px]">Masa Berlaku</th>
                    <th class="w-[160px]">Penandatangan</th>
                    <th class="w-[100px]">Revisi</th>
                    <th class="text-right w-[220px]">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($issuedCertificates)): ?>
                    <tr>
                        <td colspan="7" class="py-12 text-center text-slate-400">
                            <i class="ph-bold ph-folder-open text-3xl mb-2 inline-block text-slate-300 dark:text-slate-600"></i>
                            <p class="text-xs font-medium text-slate-600 dark:text-slate-400">
                                <?= ($searchQuery !== '' || $scopeFilter !== '' || $kanFilter !== '') ? 'Tidak ada arsip sertifikat yang sesuai dengan filter pencarian.' : 'Belum ada arsip sertifikat yang diterbitkan.' ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($issuedCertificates as $idx => $cert): ?>
                    <?php 
                        $scope = $scopes[$cert['scope_code']] ?? ['name' => $cert['scope_code']];
                        $isJustIssued = ($newlyIssued && $cert['certificate_number'] === $newlyIssued);
                        $isCertKan = (isset($cert['is_kan']) && (int)$cert['is_kan'] === 1 && substr($cert['certificate_number'], 0, 1) !== 'N');
                        $menuId = "action-menu-cert-" . $cert['id'];
                        $certReviewData = [
                            'instrument_id' => (int)$cert['instrument_id'],
                            'order_id' => (int)$cert['order_id'],
                            'order_number' => (string)$cert['order_number'],
                            'customer_name' => (string)$cert['customer_name'],
                            'customer_address' => (string)($cert['customer_address'] ?? '-'),
                            'customer_contact' => (string)($cert['customer_contact'] ?? '-'),
                            'order_date' => (string)($cert['order_date'] ?? '-'),
                            'service_type' => (string)($cert['service_type'] ?? 'IN_LAB'),
                            'name' => (string)$cert['instrument_name'],
                            'brand' => (string)($cert['brand'] ?? '-'),
                            'model_type' => (string)($cert['model_type'] ?? '-'),
                            'serial_number' => (string)($cert['serial_number'] ?? '-'),
                            'capacity_range' => (string)($cert['capacity_range'] ?? '-'),
                            'resolution' => (string)($cert['resolution'] ?? '-'),
                            'scope_code' => (string)$cert['scope_code'],
                            'is_kan' => $isCertKan,
                            'instrument_status' => 'CERTIFIED',
                            'ws_id' => (int)($cert['ws_id'] ?? 0),
                            'technician_name' => (string)($cert['technician_name'] ?? '-'),
                            'calibration_date' => (string)($cert['calibration_date'] ?? '-'),
                            'submitted_at' => (string)($cert['ws_submitted_at'] ?? '-'),
                            'temperature' => (string)($cert['temperature'] ?? '-'),
                            'temperature_uncertainty' => (string)($cert['temperature_uncertainty'] ?? '0.5'),
                            'humidity' => (string)($cert['humidity'] ?? '-'),
                            'humidity_uncertainty' => (string)($cert['humidity_uncertainty'] ?? '3.0'),
                            'standard_calibrator' => (string)($cert['standard_calibrator'] ?? '-'),
                            'standard_cert_no' => (string)($cert['standard_cert_no'] ?? '-'),
                            'standard_valid_until' => (string)($cert['standard_valid_until'] ?? '-'),
                            'calibration_method' => (string)($cert['calibration_method'] ?? '-'),
                            'visual_inspection' => (string)($cert['visual_inspection'] ?? '-'),
                            'technician_notes' => (string)($cert['technician_notes'] ?? '-'),
                            'readings' => json_decode((string)($cert['readings_json'] ?? '[]'), true) ?: [],
                            'certificate_number' => (string)$cert['certificate_number'],
                            'issue_date' => (string)$cert['issue_date'],
                            'valid_until' => (string)$cert['valid_until'],
                            'technical_manager' => (string)$cert['technical_manager'],
                            'revision_number' => (string)$cert['revision_number'],
                            'predicted_cert' => ''
                        ];
                    ?>
                    <tr class="<?= $isJustIssued ? 'row-highlight' : '' ?>">
                        
                        <!-- Col 1: Nomor Sertifikat (Primary Bold Mono) + Akreditasi Pill -->
                        <td>
                            <div class="font-mono text-xs font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                                <?= htmlspecialchars($cert['certificate_number']) ?>
                            </div>
                            <div class="mt-1 flex items-center gap-1.5">
                                <?= renderAccreditationBadge($isCertKan) ?>
                                <span class="font-mono text-[10px] text-slate-400">[<?= htmlspecialchars($cert['scope_code']) ?>]</span>
                            </div>
                        </td>

                        <!-- Col 2: Alat & Spesifikasi (High Contrast Name, Subtle SN) -->
                        <td>
                            <div class="cell-primary"><?= htmlspecialchars($cert['instrument_name']) ?></div>
                            <div class="cell-secondary"><?= htmlspecialchars($cert['brand'] ?: '-') ?> · <?= htmlspecialchars($cert['model_type'] ?: '-') ?></div>
                            <div class="cell-meta">SN: <?= htmlspecialchars($cert['serial_number']) ?></div>
                        </td>

                        <!-- Col 3: Pelanggan & SPK -->
                        <td>
                            <div class="text-xs font-semibold text-slate-900 dark:text-slate-200"><?= htmlspecialchars($cert['customer_name']) ?></div>
                            <div class="cell-meta font-medium text-slate-500 dark:text-slate-400 mt-0.5">
                                SPK: <span class="text-slate-700 dark:text-slate-300"><?= htmlspecialchars($cert['order_number']) ?></span>
                            </div>
                        </td>

                        <!-- Col 4: Periode Berlaku (Issue & Valid Until) -->
                        <td class="font-mono text-xs whitespace-nowrap">
                            <div class="text-slate-800 dark:text-slate-200 font-semibold"><?= formatIndonesianDate($cert['issue_date']) ?></div>
                            <div class="text-[11px] text-slate-400 mt-0.5">
                                Exp: <span class="text-slate-600 dark:text-slate-300"><?= formatIndonesianDate($cert['valid_until']) ?></span>
                            </div>
                        </td>

                        <!-- Col 5: Penandatangan -->
                        <td>
                            <div class="text-xs font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($cert['technical_manager']) ?></div>
                            <div class="text-[10px] text-slate-400">Manajer Teknis Lab</div>
                        </td>

                        <!-- Col 6: Revisi -->
                        <td class="whitespace-nowrap">
                            <?php if ($cert['revision_number'] === '00'): ?>
                                <span class="px-2 py-0.5 rounded text-[11px] font-mono font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200/80 dark:border-slate-700">
                                    Rev-00
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded text-[11px] font-mono font-bold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800">
                                    Rev-<?= htmlspecialchars($cert['revision_number']) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Col 7: ACTION BUTTONS ([Tinjau] + [Buat PDF] + [•••] Contextual Menu) -->
                        <td class="text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1.5 justify-end">
                                
                                <!-- Tinjau SPK & Worksheet -->
                                <button type="button" onclick='openReviewModal(<?= json_encode($certReviewData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)' class="btn-surface btn-compact" title="Tinjau Detail Data SPK & Hasil Pengujian Teknisi">
                                    <i class="ph-bold ph-eye text-slate-500"></i>
                                    <span>Tinjau</span>
                                </button>

                                <!-- Primary Action: Buat PDF -->
                                <a href="print_certificate.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" class="btn-brand-primary btn-compact" title="Buka dan Cetak Dokumen Resmi PDF">
                                    <i class="ph-bold ph-file-pdf text-xs"></i>
                                    <span>Buat PDF</span>
                                </a>

                                <!-- Contextual Action Menu: [•••] -->
                                <div class="action-menu-container">
                                    <button type="button" onclick="toggleActionMenu('<?= $menuId ?>', event)" class="btn-icon" title="Menu Opsi Lainnya" aria-label="Menu Opsi">
                                        <i class="ph-bold ph-dots-three-vertical text-sm"></i>
                                    </button>
                                    
                                    <div id="<?= $menuId ?>" class="action-menu-dropdown">
                                        <button type="button" onclick='openReviewModal(<?= json_encode($certReviewData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>); closeAllActionMenus();' class="action-menu-item">
                                            <i class="ph-bold ph-clipboard-text text-slate-500"></i>
                                            <span>Tinjau SPK & Worksheet</span>
                                        </button>
                                        <a href="worksheet.php?instrument_id=<?= (int)$cert['instrument_id'] ?>" class="action-menu-item">
                                            <i class="ph-bold ph-wrench text-slate-500"></i>
                                            <span>Buka Lembar Kerja Penuh</span>
                                        </a>
                                        <a href="orders.php?search=<?= urlencode($cert['order_number']) ?>" class="action-menu-item">
                                            <i class="ph-bold ph-file-text text-slate-500"></i>
                                            <span>Buka Berkas SPK</span>
                                        </a>

                                        <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                            <div class="action-menu-divider"></div>
                                            <button type="button" onclick="openRevisionModal(<?= $cert['id'] ?>, '<?= htmlspecialchars(addslashes($cert['certificate_number'])) ?>'); closeAllActionMenus();" class="action-menu-item">
                                                <i class="ph-bold ph-pencil-simple text-slate-500"></i>
                                                <span>Ajukan Revisi (-01)</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Table Footer Count -->
    <div class="ent-card-footer flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-slate-500">
        <span>Menampilkan <strong><?= count($issuedCertificates) ?></strong> dari total <?= $totalIssuedCount ?> sertifikat kalibrasi</span>
        <span class="font-mono text-[11px] text-slate-400">Sistem Informasi Laboratorium ISO/IEC 17025</span>
    </div>

</div>
</div>

<!-- MODAL 1: Generator Nomor Sertifikat Otomatis -->
<div id="generate-cert-modal" class="fixed inset-0 z-modal bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="ent-modal rounded-xl w-full max-w-lg p-6 shadow-2xl text-xs">
        
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3 mb-5">
            <div>
                <h3 class="text-base font-bold text-slate-900 dark:text-slate-100 tracking-tight">Generator Nomor Sertifikat</h3>
                <p class="text-slate-500 dark:text-slate-400 text-[11px] mt-0.5">Penerbitan nomor resmi kalibrasi standar ISO/IEC 17025.</p>
            </div>
            <button onclick="closeModal('generate-cert-modal')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 p-1 rounded-md transition-colors">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <form action="certificates.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="generate_certificate">
            <input type="hidden" id="modal-inst-id" name="instrument_id" value="">

            <div class="bg-slate-50 dark:bg-slate-800/60 p-3 rounded-lg border border-slate-200 dark:border-slate-700 space-y-1.5 text-[11px]">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400">Nama Alat:</span>
                    <strong id="modal-inst-name" class="text-slate-900 dark:text-slate-100 font-semibold"></strong>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400">Pelanggan:</span>
                    <span id="modal-customer-name" class="text-slate-700 dark:text-slate-300 font-medium"></span>
                </div>
            </div>

            <!-- Preview Nomor -->
            <div class="bg-slate-50 dark:bg-slate-800/80 p-4 rounded-lg border border-slate-200 dark:border-slate-700 text-center transition-all">
                <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider block mb-1">
                    Preview Nomor Sertifikat
                </span>
                <div id="modal-cert-preview" class="font-mono text-2xl font-extrabold text-[#C81E26] dark:text-red-400 tracking-wider transition-opacity duration-150">
                    2605P0012-00
                </div>
                <p id="modal-cert-desc" class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                    Format: Tahun (26) + Bulan + Scope + No. Urut + Revisi (-00)
                </p>
            </div>

            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 dark:text-slate-300 mb-1">Status Akreditasi <span class="text-rose-500">*</span></label>
                        <select id="modal-is-kan" name="is_kan" onchange="refreshModalCertPreview()" class="ent-select font-medium">
                            <option value="1">Akreditasi KAN (ISO/IEC 17025)</option>
                            <option value="0">Non-KAN (Awalan N)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 dark:text-slate-300 mb-1">Tanggal Terbit <span class="text-rose-500">*</span></label>
                        <input type="date" id="modal-issue-date" name="issue_date" required value="<?= date('Y-m-d') ?>" onchange="refreshModalCertPreview()" class="ent-input">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 dark:text-slate-300 mb-1">Masa Berlaku</label>
                        <select name="valid_months" class="ent-select">
                            <option value="12">12 Bulan (1 Tahun)</option>
                            <option value="6">6 Bulan</option>
                            <option value="24">24 Bulan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 dark:text-slate-300 mb-1">Manajer Teknis</label>
                        <input type="text" name="technical_manager" value="Ir. Hendra Wijaya, M.T." class="ent-input">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="closeModal('generate-cert-modal')" class="btn-surface">Batal</button>
                <button type="submit" class="btn-brand-primary">
                    <i class="ph-bold ph-certificate"></i>
                    <span>Terbitkan Sertifikat Resmi</span>
                </button>
            </div>
        </form>

    </div>
</div>

<!-- MODAL 2: Revisi Sertifikat -->
<div id="revision-cert-modal" class="fixed inset-0 z-modal bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="ent-modal rounded-xl w-full max-w-md p-6 shadow-2xl text-xs">
        
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3 mb-4">
            <div>
                <h3 class="text-base font-bold text-slate-900 dark:text-slate-100 tracking-tight">Revisi / Amandemen Sertifikat</h3>
                <p class="text-slate-500 dark:text-slate-400 text-[11px] mt-0.5">Sesuai klausul amandemen sertifikat ISO/IEC 17025.</p>
            </div>
            <button onclick="closeModal('revision-cert-modal')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 p-1 rounded-md transition-colors">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <form action="certificates.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_revision">
            <input type="hidden" id="rev-cert-id" name="certificate_id" value="">

            <div class="bg-slate-50 dark:bg-slate-800/60 p-3 rounded-lg border border-slate-200 dark:border-slate-700">
                <span class="text-slate-500 dark:text-slate-400 text-[11px]">Sertifikat saat ini:</span>
                <p id="rev-cert-num" class="font-mono text-sm font-bold text-slate-900 dark:text-slate-100 mt-0.5"></p>
            </div>

            <div>
                <label class="block font-medium text-slate-700 dark:text-slate-300 mb-1">Alasan / Catatan Revisi <span class="text-rose-500">*</span></label>
                <textarea name="revision_notes" required rows="3" placeholder="Contoh: Perbaikan kesalahan penulisan nama atau alamat pelanggan." class="ent-input"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="closeModal('revision-cert-modal')" class="btn-surface">Batal</button>
                <button type="submit" class="btn-brand-primary">
                    <i class="ph-bold ph-pencil-simple"></i>
                    <span>Terapkan Revisi</span>
                </button>
            </div>
        </form>

    </div>
</div>

<!-- MODAL 3: Tinjau Lengkap Data SPK & Lembar Kerja Teknisi (ISO/IEC 17025 Reviewer Modal) -->
<div id="review-data-modal" class="fixed inset-0 z-modal bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-3 sm:p-4" style="overflow-y: auto;">
    <div class="ent-modal review-modal-card rounded-xl shadow-2xl text-xs" style="max-height: 86vh; display: flex; flex-direction: column; width: 100%; max-width: 860px; overflow: hidden;">
        
        <!-- Modal Header (Fixed at top) -->
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 px-4 py-2.5 shrink-0 bg-slate-50 dark:bg-slate-900/80">
            <div>
                <div class="flex items-center gap-2 mb-0.5">
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                        MODE TINJAU PENGURUS SERTIFIKAT
                    </span>
                    <span class="text-xs text-slate-400">·</span>
                    <span class="text-xs text-slate-500 font-mono">ISO/IEC 17025 Data Verification</span>
                </div>
                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100 tracking-tight">Tinjau Detail Data SPK & Lembar Kerja Teknisi</h3>
            </div>
            <button onclick="closeModal('review-data-modal')" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 p-1 rounded-md transition-colors" title="Tutup">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>

        <!-- Modal Body (Smoothly Scrollable & Compact) -->
        <div class="review-modal-body p-3.5 sm:p-4 space-y-3 flex-1" style="flex: 1 1 auto; min-height: 0; overflow-y: auto;">
            
            <!-- Instrument Summary Banner -->
            <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/80 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span id="review-scope-badge" class="font-mono text-xs font-bold px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-200"></span>
                        <span id="review-kan-badge"></span>
                        <span id="review-service-badge"></span>
                    </div>
                    <h4 id="review-inst-name" class="text-sm font-bold text-slate-900 dark:text-slate-100"></h4>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mb-0">
                        Merek / Tipe: <strong id="review-brand-model" class="text-slate-800 dark:text-slate-200"></strong> · 
                        No. Seri: <strong id="review-serial-number" class="font-mono text-slate-900 dark:text-slate-100"></strong>
                    </p>
                </div>
                <div class="text-left md:text-right shrink-0 border-t md:border-t-0 pt-2 md:pt-0 border-slate-100 dark:border-slate-700">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-0.5">Status Alur Sertifikasi</span>
                    <div id="review-status-pill"></div>
                    <div id="review-cert-no-box" class="mt-0.5 font-mono text-xs font-bold text-[#C81E26] dark:text-red-400"></div>
                </div>
            </div>

            <!-- Bagian 1: Data Registrasi SPK & Pelanggan -->
            <div class="ent-card overflow-hidden">
                <div class="px-3.5 py-2 bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                    <div class="flex items-center gap-2 font-bold text-xs text-slate-800 dark:text-slate-200">
                        <i class="ph-bold ph-clipboard-text text-sky-600"></i>
                        <span>1. Data Registrasi SPK & Informasi Pelanggan</span>
                    </div>
                    <span id="review-order-number-badge" class="font-mono text-[11px] font-semibold text-slate-600 dark:text-slate-400"></span>
                </div>
                <div class="p-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Nama Perusahaan / Pelanggan</span>
                        <div id="review-customer-name" class="text-xs font-semibold text-slate-900 dark:text-slate-100"></div>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Narahubung & Kontak</span>
                        <div id="review-customer-contact" class="text-xs text-slate-800 dark:text-slate-200"></div>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Tanggal Registrasi SPK</span>
                        <div id="review-order-date" class="font-mono text-xs text-slate-800 dark:text-slate-200"></div>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Alamat Lengkap Pelanggan (Lokasi Kalibrasi)</span>
                        <div id="review-customer-address" class="text-xs text-slate-700 dark:text-slate-300"></div>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Rentang Kapasitas Ukur</span>
                        <div id="review-capacity-range" class="font-mono text-xs font-medium text-slate-800 dark:text-slate-200"></div>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Resolusi / Daya Baca</span>
                        <div id="review-resolution" class="font-mono text-xs font-medium text-slate-800 dark:text-slate-200"></div>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Metode Penyerahan</span>
                        <div id="review-service-desc" class="text-xs text-slate-700 dark:text-slate-300 font-medium"></div>
                    </div>
                </div>
            </div>

            <!-- Bagian 2: Parameter Pengujian & Standar Teknisi -->
            <div class="ent-card overflow-hidden">
                <div class="px-3.5 py-2 bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                    <div class="flex items-center gap-2 font-bold text-xs text-slate-800 dark:text-slate-200">
                        <i class="ph-bold ph-wrench text-amber-600"></i>
                        <span>2. Parameter Teknis & Alat Standar Acuan</span>
                    </div>
                    <span id="review-tech-badge" class="text-[11px] text-slate-500"></span>
                </div>
                <div class="p-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2.5">
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Teknisi Penguji</span>
                        <div id="review-technician-name" class="text-xs font-semibold text-slate-900 dark:text-slate-100"></div>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Tanggal Pelaksanaan Kalibrasi</span>
                        <div id="review-calibration-date" class="font-mono text-xs text-slate-800 dark:text-slate-200"></div>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Waktu Submit Lembar Kerja</span>
                        <div id="review-submitted-at" class="font-mono text-xs text-slate-800 dark:text-slate-200"></div>
                    </div>

                    <!-- Kondisi Lingkungan -->
                    <div class="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 sm:col-span-1">
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Suhu Ruangan Lab</span>
                        <div class="font-mono text-xs font-bold text-slate-900 dark:text-slate-100">
                            <span id="review-temp"></span> °C <span class="text-slate-400 font-normal text-[11px]">± <span id="review-temp-u"></span> °C</span>
                        </div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 sm:col-span-1">
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Kelembaban Relatif (RH)</span>
                        <div class="font-mono text-xs font-bold text-slate-900 dark:text-slate-100">
                            <span id="review-humidity"></span> % <span class="text-slate-400 font-normal text-[11px]">± <span id="review-humidity-u"></span> %</span>
                        </div>
                    </div>
                    <div class="p-2.5 rounded-lg bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 sm:col-span-2 lg:col-span-1">
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Metode & Instruksi Kerja</span>
                        <div id="review-method" class="text-xs font-semibold text-slate-800 dark:text-slate-200 truncate"></div>
                    </div>

                    <!-- Alat Standar Kalibrator -->
                    <div class="sm:col-span-2 lg:col-span-2">
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Alat Standar Acuan (Calibrator)</span>
                        <div id="review-calibrator" class="text-xs font-semibold text-slate-900 dark:text-slate-100"></div>
                        <div class="text-[11px] text-slate-500 mt-0.5">
                            No. Sertifikat: <strong id="review-calibrator-cert" class="font-mono text-slate-700 dark:text-slate-300"></strong> · 
                            Masa Berlaku: <strong id="review-calibrator-valid" class="font-mono text-slate-700 dark:text-slate-300"></strong>
                        </div>
                    </div>
                    <div>
                        <span class="block text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-0.5">Inspeksi Visual Fisik</span>
                        <div id="review-visual" class="text-xs text-slate-800 dark:text-slate-200 font-medium"></div>
                    </div>

                    <div id="review-notes-container" class="sm:col-span-2 lg:col-span-3 hidden">
                        <div class="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/50 text-[11px] text-amber-900 dark:text-amber-200">
                            <span class="font-bold flex items-center gap-1 mb-0.5"><i class="ph-bold ph-note"></i> Catatan Khusus Teknisi:</span>
                            <span id="review-notes"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bagian 3: Rekapitulasi Data Mentah Titik Ukur (Readings) -->
            <div class="ent-card overflow-hidden">
                <div class="px-3.5 py-2 bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                    <div class="flex items-center gap-2 font-bold text-xs text-slate-800 dark:text-slate-200">
                        <i class="ph-bold ph-table text-emerald-600"></i>
                        <span>3. Rekapitulasi Data Titik Ukur & Ketidakpastian (Readings)</span>
                    </div>
                    <span id="review-readings-count" class="text-[11px] text-slate-500 font-mono"></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="ent-table min-w-[650px]">
                        <thead>
                            <tr>
                                <th class="w-[130px] py-1.5 text-xs">Titik Uji / Nominal</th>
                                <th class="text-right w-[100px] py-1.5 text-xs">Nilai Standar</th>
                                <th class="text-right w-[75px] py-1.5 text-xs">Run 1</th>
                                <th class="text-right w-[75px] py-1.5 text-xs">Run 2</th>
                                <th class="text-right w-[75px] py-1.5 text-xs">Run 3</th>
                                <th class="text-right w-[95px] py-1.5 text-xs">Rata-rata</th>
                                <th class="text-right w-[95px] py-1.5 text-xs">Koreksi</th>
                                <th class="text-right w-[105px] py-1.5 text-xs">Ketidakpastian (U95)</th>
                            </tr>
                        </thead>
                        <tbody id="review-readings-tbody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Modal Footer Actions (Fixed at bottom) -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-2.5 border-t border-slate-200 dark:border-slate-800 px-4 py-2.5 shrink-0 bg-slate-50 dark:bg-slate-900/80">
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <a id="review-link-spk" href="#" class="btn-surface text-xs" style="height: 32px; padding: 0 10px;" title="Buka SPK di halaman Sales Order">
                    <i class="ph-bold ph-arrow-square-out text-slate-400"></i>
                    <span>Buka SPK</span>
                </a>
                <a id="review-link-ws" href="#" class="btn-surface text-xs" style="height: 32px; padding: 0 10px;" title="Buka Lembar Kerja Teknisi secara lengkap">
                    <i class="ph-bold ph-wrench text-slate-400"></i>
                    <span>Buka Worksheet Penuh</span>
                </a>
            </div>

            <div class="flex items-center gap-2 justify-end w-full sm:w-auto">
                <button type="button" onclick="closeModal('review-data-modal')" class="btn-surface" style="height: 32px; padding: 0 12px;">Tutup</button>
                <div id="review-dynamic-action"></div>
            </div>
        </div>

    </div>
</div>

<script>
let currentReviewData = null;

function openReviewModal(data) {
    if (!data) return;
    currentReviewData = data;
    
    // Header & Summary
    document.getElementById('review-inst-name').textContent = data.name || '-';
    document.getElementById('review-brand-model').textContent = (data.brand || '-') + ' · ' + (data.model_type || '-');
    document.getElementById('review-serial-number').textContent = data.serial_number || '-';
    
    document.getElementById('review-scope-badge').textContent = '[' + (data.scope_code || 'P') + ']';
    
    const kanBadgeEl = document.getElementById('review-kan-badge');
    if (data.is_kan) {
        kanBadgeEl.innerHTML = '<span class="pill-kan text-[10px]">Akreditasi KAN</span>';
    } else {
        kanBadgeEl.innerHTML = '<span class="pill-non-kan text-[10px]">Non-KAN (N)</span>';
    }

    const srvBadgeEl = document.getElementById('review-service-badge');
    if (data.service_type === 'ON_SITE') {
        srvBadgeEl.innerHTML = '<span class="badge-status warning" style="font-size: 10px; padding: 1px 6px;">On-Site (Lokasi Klien)</span>';
    } else {
        srvBadgeEl.innerHTML = '<span class="badge-status info" style="font-size: 10px; padding: 1px 6px;">In-Lab (Lab Kalpindo)</span>';
    }

    const statusPillEl = document.getElementById('review-status-pill');
    const certBoxEl = document.getElementById('review-cert-no-box');
    if (data.instrument_status === 'CERTIFIED' || data.certificate_number) {
        statusPillEl.innerHTML = '<span class="badge-status success"><span class="badge-dot"></span><span>Sertifikat Terbit Resmi</span></span>';
        certBoxEl.textContent = data.certificate_number;
    } else if (data.instrument_status === 'DATA_SUBMITTED') {
        statusPillEl.innerHTML = '<span class="badge-status warning"><span class="badge-dot"></span><span>Menunggu Nomor Sertifikat</span></span>';
        certBoxEl.textContent = data.predicted_cert ? ('Estimasi: ' + data.predicted_cert) : '';
    } else {
        statusPillEl.innerHTML = '<span class="badge-status info"><span class="badge-dot"></span><span>' + data.instrument_status + '</span></span>';
        certBoxEl.textContent = '';
    }

    // Bagian 1: Data SPK
    document.getElementById('review-order-number-badge').textContent = data.order_number ? ('SPK: ' + data.order_number) : '';
    document.getElementById('review-customer-name').textContent = data.customer_name || '-';
    document.getElementById('review-customer-contact').textContent = data.customer_contact || '-';
    document.getElementById('review-order-date').textContent = data.order_date || '-';
    document.getElementById('review-customer-address').textContent = data.customer_address || '-';
    document.getElementById('review-capacity-range').textContent = data.capacity_range || '-';
    document.getElementById('review-resolution').textContent = data.resolution || '-';
    document.getElementById('review-service-desc').textContent = data.service_type === 'ON_SITE' ? 'Kalibrasi langsung di fasilitas / pabrik pelanggan' : 'Alat dikirim & diuji di ruang kalibrasi PT Kalpindo';

    // Bagian 2: Parameter Teknisi
    document.getElementById('review-tech-badge').textContent = data.technician_name ? ('Oleh: ' + data.technician_name) : '';
    document.getElementById('review-technician-name').textContent = data.technician_name || '-';
    document.getElementById('review-calibration-date').textContent = data.calibration_date || '-';
    document.getElementById('review-submitted-at').textContent = data.submitted_at || '-';
    document.getElementById('review-temp').textContent = data.temperature || '-';
    document.getElementById('review-temp-u').textContent = data.temperature_uncertainty || '0.5';
    document.getElementById('review-humidity').textContent = data.humidity || '-';
    document.getElementById('review-humidity-u').textContent = data.humidity_uncertainty || '3.0';
    document.getElementById('review-method').textContent = data.calibration_method || 'IK-LAB-01 (Metode Perbandingan Langsung)';
    document.getElementById('review-calibrator').textContent = data.standard_calibrator || '-';
    document.getElementById('review-calibrator-cert').textContent = data.standard_cert_no || '-';
    document.getElementById('review-calibrator-valid').textContent = data.standard_valid_until || '-';
    document.getElementById('review-visual').textContent = data.visual_inspection || 'Fisik bersih, display normal';

    const notesContainer = document.getElementById('review-notes-container');
    const notesEl = document.getElementById('review-notes');
    if (data.technician_notes && data.technician_notes.trim() !== '') {
        notesEl.textContent = data.technician_notes;
        notesContainer.classList.remove('hidden');
    } else {
        notesContainer.classList.add('hidden');
    }

    // Bagian 3: Tabel Readings
    const tbody = document.getElementById('review-readings-tbody');
    const countEl = document.getElementById('review-readings-count');
    tbody.innerHTML = '';
    
    const readings = data.readings || [];
    countEl.textContent = readings.length + ' titik ukur';

    if (readings.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-6 text-slate-400">Belum ada data titik ukur yang tercatat dalam lembar kerja teknisi.</td></tr>';
    } else {
        readings.forEach((r, idx) => {
            const tr = document.createElement('tr');
            const pt = r.point || ('Titik ' + (idx + 1));
            const std = (r.standard !== undefined && r.standard !== null) ? Number(r.standard).toFixed(2) : '-';
            const r1 = (r.run1 !== undefined && r.run1 !== null) ? Number(r.run1).toFixed(2) : '-';
            const r2 = (r.run2 !== undefined && r.run2 !== null) ? Number(r.run2).toFixed(2) : '-';
            const r3 = (r.run3 !== undefined && r.run3 !== null) ? Number(r.run3).toFixed(2) : '-';
            const mean = (r.mean !== undefined && r.mean !== null) ? Number(r.mean).toFixed(4) : '-';
            const corr = (r.correction !== undefined && r.correction !== null) ? ((Number(r.correction) >= 0 ? '+' : '') + Number(r.correction).toFixed(4)) : '-';
            const unc = (r.uncertainty !== undefined && r.uncertainty !== null) ? ('± ' + Number(r.uncertainty).toFixed(4)) : '-';

            const corrColor = (r.correction && Number(r.correction) < 0) ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400';

            tr.innerHTML = `
                <td class="font-medium text-slate-900 dark:text-slate-100">${escapeHtml(pt)}</td>
                <td class="text-right font-mono text-slate-700 dark:text-slate-300">${std}</td>
                <td class="text-right font-mono text-slate-600 dark:text-slate-400">${r1}</td>
                <td class="text-right font-mono text-slate-600 dark:text-slate-400">${r2}</td>
                <td class="text-right font-mono text-slate-600 dark:text-slate-400">${r3}</td>
                <td class="text-right font-mono font-semibold text-slate-900 dark:text-slate-100">${mean}</td>
                <td class="text-right font-mono font-bold ${corrColor}">${corr}</td>
                <td class="text-right font-mono text-slate-700 dark:text-slate-300">${unc}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    // Links
    document.getElementById('review-link-spk').href = 'orders.php?search=' + encodeURIComponent(data.order_number || '');
    document.getElementById('review-link-ws').href = 'worksheet.php?instrument_id=' + (data.instrument_id || 0);

    // Dynamic Primary Action
    const dynamicActionContainer = document.getElementById('review-dynamic-action');
    dynamicActionContainer.innerHTML = '';
    
    if (data.certificate_number) {
        dynamicActionContainer.innerHTML = `
            <a href="print_certificate.php?cert=${encodeURIComponent(data.certificate_number)}" target="_blank" class="btn-brand-primary">
                <i class="ph-bold ph-file-pdf"></i>
                <span>Cetak PDF Resmi</span>
            </a>
        `;
    } else if (data.instrument_status === 'DATA_SUBMITTED') {
        dynamicActionContainer.innerHTML = `
            <button type="button" onclick="proceedToIssueFromReview()" class="btn-brand-primary">
                <i class="ph-bold ph-certificate"></i>
                <span>Lanjut Terbitkan Sertifikat &rarr;</span>
            </button>
        `;
    }

    openModal('review-data-modal');
}

function proceedToIssueFromReview() {
    if (!currentReviewData) return;
    const d = currentReviewData;
    closeModal('review-data-modal');
    openGenerateModal(d.instrument_id, d.name, d.customer_name, d.scope_code, d.is_kan ? 1 : 0, d.predicted_cert);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function switchCertTab(tabName) {
    const queuePane = document.getElementById('pane-queue');
    const archivePane = document.getElementById('pane-archive');
    const queueBtn = document.getElementById('tab-btn-queue');
    const archiveBtn = document.getElementById('tab-btn-archive');
    
    if (!queuePane || !archivePane) return;

    if (tabName === 'archive') {
        queuePane.classList.add('hidden');
        archivePane.classList.remove('hidden');

        if (archiveBtn) archiveBtn.classList.add('active');
        if (queueBtn) queueBtn.classList.remove('active');

        const url = new URL(window.location);
        url.searchParams.set('tab', 'archive');
        window.history.replaceState({}, '', url);
    } else {
        archivePane.classList.add('hidden');
        queuePane.classList.remove('hidden');

        if (queueBtn) queueBtn.classList.add('active');
        if (archiveBtn) archiveBtn.classList.remove('active');

        const url = new URL(window.location);
        url.searchParams.set('tab', 'queue');
        window.history.replaceState({}, '', url);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash === '#archive' || window.location.hash === '#archive-section') {
        switchCertTab('archive');
    }
});

function refreshModalCertPreview() {
    const isKan = document.getElementById('modal-is-kan').value;
    const dateVal = document.getElementById('modal-issue-date').value || '<?= date('Y-m-d') ?>';
    const previewEl = document.getElementById('modal-cert-preview');
    const descEl = document.getElementById('modal-cert-desc');
    
    previewEl.classList.add('opacity-40');
    fetch(`certificates.php?ajax_preview=1&scope=${encodeURIComponent(currentModalScopeCode)}&date=${encodeURIComponent(dateVal)}&is_kan=${isKan}`)
        .then(res => res.json())
        .then(data => {
            previewEl.textContent = data.certificate_number;
            previewEl.classList.remove('opacity-40');
            if (isKan === '1') {
                descEl.innerHTML = 'Format KAN: Tahun (26) + Bulan + Scope (' + currentModalScopeCode + ') + No. Urut + Revisi (-00)';
            } else {
                descEl.innerHTML = 'Format Non-KAN: Awalan N + Tahun (26) + Bulan + Scope (' + currentModalScopeCode + ') + No. Urut + Revisi (-00)';
            }
        })
        .catch(err => {
            previewEl.classList.remove('opacity-40');
        });
}

function openGenerateModal(instId, instName, customerName, scopeCode, isKan, previewNum) {
    currentModalScopeCode = scopeCode;
    document.getElementById('modal-inst-id').value = instId;
    document.getElementById('modal-inst-name').textContent = instName;
    document.getElementById('modal-customer-name').textContent = customerName;
    document.getElementById('modal-is-kan').value = (isKan !== undefined && isKan !== null) ? isKan : 1;
    document.getElementById('modal-cert-preview').textContent = previewNum;
    refreshModalCertPreview();
    openModal('generate-cert-modal');
}

function openRevisionModal(certId, certNumber) {
    document.getElementById('rev-cert-id').value = certId;
    document.getElementById('rev-cert-num').textContent = certNumber;
    openModal('revision-cert-modal');
}

<?php if (!empty($autoTargetInst)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openGenerateModal(
        <?= $autoTargetInst['id'] ?>,
        <?= json_encode($autoTargetInst['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($autoTargetInst['customer_name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($autoTargetInst['scope_code'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        <?= $autoTargetInst['is_kan'] ?>,
        <?= json_encode($autoTargetInst['preview'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    );
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
