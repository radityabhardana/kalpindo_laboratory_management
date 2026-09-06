<?php
/**
 * Modul Bagian Sertifikat (Certificate Administration)
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Clean Modern Enterprise Operational Module
 */

declare(strict_types=1);

$pageTitle = 'Bagian Pengurus Sertifikat';
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

            $db->exec("UPDATE instruments SET status = 'CERTIFIED', is_kan = {$isKan} WHERE id = {$instrumentId}");
            
            $orderId = (int)$inst['order_id'];
            $uncompletedCount = (int)$db->query("SELECT COUNT(*) FROM instruments WHERE order_id = {$orderId} AND status != 'CERTIFIED'")->fetchColumn();
            if ($uncompletedCount === 0) {
                $db->exec("UPDATE orders SET status = 'COMPLETED' WHERE id = {$orderId}");
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
        o.service_type,
        w.submitted_at,
        w.temperature,
        w.humidity,
        w.standard_calibrator
    FROM instruments i
    JOIN orders o ON i.order_id = o.id
    JOIN worksheets w ON w.instrument_id = i.id
    WHERE i.status = 'DATA_SUBMITTED'
    ORDER BY w.submitted_at ASC
")->fetchAll();

// 2. Filter & Sortir Arsip Sertifikat Kalibrasi Resmi Terbit
$searchQuery = trim($_GET['search'] ?? '');
$scopeFilter = trim($_GET['scope'] ?? '');
$kanFilter = trim($_GET['kan'] ?? '');
$sortOption = trim($_GET['sort'] ?? 'newest');
$newlyIssued = trim($_GET['issued'] ?? '');

$sqlIssued = "
    SELECT 
        c.*,
        i.name as instrument_name,
        i.brand,
        i.model_type,
        i.serial_number,
        o.order_number,
        o.customer_name,
        o.service_type
    FROM certificates c
    JOIN instruments i ON c.instrument_id = i.id
    JOIN orders o ON i.order_id = o.id
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
                MODUL PENERBITAN
            </span>
            <span class="text-xs text-slate-400">·</span>
            <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">ISO/IEC 17025 (LK-088-IDN)</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 tracking-tight">Administrasi & Penerbitan Sertifikat</h1>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-3xl">
            Kelola verifikasi data mentah teknisi, pembentukan nomor sertifikat standar ISO/IEC 17025, penandatanganan manajer teknis, dan pengarsipan resmi.
        </p>
    </div>

    <div class="flex items-center gap-2 shrink-0">
        <a href="#incoming-queue-section" class="btn-surface text-xs">
            <i class="ph-bold ph-tray text-amber-500"></i>
            <span>Antrean Berkas (<?= count($incomingQueue) ?>)</span>
        </a>
        <a href="certificates.php" class="btn-surface text-xs">
            <i class="ph-bold ph-arrows-clockwise text-slate-400"></i>
            <span>Refresh Data</span>
        </a>
    </div>
</div>

<!-- 2. OPERATIONAL SUMMARY (4 Structured KPI Cards) -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-7">
    
    <!-- KPI 1: Total Issued -->
    <div class="kpi-widget accent-success">
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

    <!-- KPI 2: Waiting Queue -->
    <div class="kpi-widget <?= count($incomingQueue) > 0 ? 'accent-primary' : 'accent-warning' ?>">
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

<!-- 3. WORKFLOW QUEUE (Antrean Berkas Masuk Teknisi) -->
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
                        <th class="text-right w-[200px]">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incomingQueue as $item): ?>
                        <?php 
                            $sc = $scopes[$item['scope_code']] ?? ['name' => $item['scope_code']];
                            $isItemKan = ((int)($item['is_kan'] ?? 1) === 1);
                            $predicted = generateCertificateNumber($db, $item['scope_code'], date('Y-m-d'), '00', $isItemKan);
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
                                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                    <button onclick="openGenerateModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>', '<?= htmlspecialchars(addslashes($item['customer_name'])) ?>', '<?= $item['scope_code'] ?>', <?= $isItemKan ? 1 : 0 ?>, '<?= $predicted['certificate_number'] ?>')" class="btn-brand-primary">
                                        <i class="ph-bold ph-certificate text-xs"></i>
                                        <span>Terbitkan (<?= $predicted['certificate_number'] ?>)</span>
                                    </button>
                                <?php else: ?>
                                    <span class="badge-status neutral">Mode Tinjau</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

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
            <input type="hidden" name="section" value="archive">
            
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
                    <a href="certificates.php#archive-section" class="btn-surface">
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
                    <th class="text-right w-[150px]">Aksi</th>
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
                        $menuId = "action-menu-" . $cert['id'];
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

                        <!-- Col 7: ACTION BUTTONS ([Buat PDF] + [•••] Contextual Menu) -->
                        <td class="text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1.5 justify-end">
                                
                                <!-- Primary Action: Buat PDF -->
                                <a href="print_certificate.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" class="btn-brand-primary" title="Buka dan Cetak Dokumen Resmi PDF">
                                    <i class="ph-bold ph-file-pdf text-xs"></i>
                                    <span>Buat PDF</span>
                                </a>

                                <!-- Contextual Action Menu: [•••] -->
                                <div class="action-menu-container">
                                    <button type="button" onclick="toggleActionMenu('<?= $menuId ?>', event)" class="btn-icon" title="Menu Opsi Lainnya" aria-label="Menu Opsi">
                                        <i class="ph-bold ph-dots-three-vertical text-sm"></i>
                                    </button>
                                    
                                    <div id="<?= $menuId ?>" class="action-menu-dropdown">
                                        <a href="verify.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" class="action-menu-item">
                                            <i class="ph-bold ph-qr-code text-slate-500"></i>
                                            <span>Verifikasi QR Code</span>
                                        </a>

                                        <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                            <div class="action-menu-divider"></div>
                                            <button type="button" onclick="openRevisionModal(<?= $cert['id'] ?>, '<?= htmlspecialchars(addslashes($cert['certificate_number'])) ?>')" class="action-menu-item">
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

<!-- MODAL 1: Generator Nomor Sertifikat Otomatis -->
<div id="generate-cert-modal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white dark:bg-[#111827] rounded-xl border border-slate-200 dark:border-slate-700 w-full max-w-lg p-6 shadow-2xl text-xs">
        
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
<div id="revision-cert-modal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white dark:bg-[#111827] rounded-xl border border-slate-200 dark:border-slate-700 w-full max-w-md p-6 shadow-2xl text-xs">
        
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

<script>
let currentModalScopeCode = 'P';

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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
