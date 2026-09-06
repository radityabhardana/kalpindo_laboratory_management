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

<!-- Header Control Bar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-xl font-bold text-slate-900 tracking-tight">Administrasi & Penerbitan Sertifikat</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            Verifikasi data mentah teknisi, pembentukan nomor sertifikat standar ISO/IEC 17025, dan pengarsipan resmi.
        </p>
    </div>
</div>

<!-- SECTION 1: Antrean Berkas Masuk dari Teknisi (Menunggu Penomoran) -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-subtle overflow-hidden mb-6">
    <div class="p-3.5 sm:p-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-slate-900"></span>
            <h2 class="text-xs font-semibold text-slate-800 uppercase tracking-wider">Antrean Berkas Masuk Teknisi</h2>
        </div>
        <span class="text-xs text-slate-500 font-medium"><?= count($incomingQueue) ?> berkas menunggu nomor</span>
    </div>

    <?php if (empty($incomingQueue)): ?>
        <div class="p-8 text-center text-slate-400 text-xs">
            Semua lembar kerja teknisi telah selesai diverifikasi dan diterbitkan nomor sertifikatnya.
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs min-w-[960px]">
                <thead class="bg-slate-50/75 text-slate-500 font-semibold border-b border-slate-200 uppercase text-[10px] tracking-wider whitespace-nowrap">
                    <tr>
                        <th class="py-3 px-4">Alat & Pelanggan</th>
                        <th class="py-3 px-3">Ruang Lingkup</th>
                        <th class="py-3 px-3">Teknisi & Masuk</th>
                        <th class="py-3 px-3">Suhu & RH</th>
                        <th class="py-3 px-4">Standar Acuan</th>
                        <th class="py-3 px-4 text-right">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($incomingQueue as $item): ?>
                        <?php 
                            $sc = $scopes[$item['scope_code']] ?? ['name' => $item['scope_code']];
                            $isItemKan = ((int)($item['is_kan'] ?? 1) === 1);
                            $predicted = generateCertificateNumber($db, $item['scope_code'], date('Y-m-d'), '00', $isItemKan);
                        ?>
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="py-3.5 px-4 align-middle">
                                <p class="font-semibold text-slate-900 text-xs"><?= htmlspecialchars($item['name']) ?></p>
                                <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($item['brand'] ?: '-') ?> <?= htmlspecialchars($item['model_type'] ?: '') ?></p>
                                <p class="font-mono text-[10px] text-slate-400 mt-0.5">SN: <?= htmlspecialchars($item['serial_number']) ?> • <?= htmlspecialchars($item['customer_name']) ?></p>
                            </td>

                            <td class="py-3.5 px-3 align-middle whitespace-nowrap">
                                <span class="font-mono text-xs text-slate-900 font-medium">
                                    [<?= htmlspecialchars($item['scope_code']) ?>] <?= htmlspecialchars(explode(' ', $sc['name'])[0]) ?>
                                </span>
                                <p class="text-[11px] <?= $isItemKan ? 'text-slate-500' : 'text-amber-700 font-semibold' ?> mt-0.5">
                                    <?= $isItemKan ? 'Akreditasi KAN' : 'Non-KAN (Awalan N)' ?>
                                </p>
                            </td>

                            <td class="py-3.5 px-3 align-middle">
                                <p class="font-medium text-slate-800 text-xs"><?= htmlspecialchars($item['technician_name'] ?: '-') ?></p>
                                <p class="text-slate-400 font-mono text-[10px] mt-0.5"><?= htmlspecialchars($item['submitted_at']) ?></p>
                            </td>

                            <td class="py-3.5 px-3 align-middle font-mono text-slate-700 whitespace-nowrap">
                                <span><?= $item['temperature'] ?> °C</span> • <span><?= $item['humidity'] ?> %RH</span>
                            </td>

                            <td class="py-3.5 px-4 align-middle text-slate-600 max-w-[220px] truncate" title="<?= htmlspecialchars($item['standard_calibrator']) ?>">
                                <?= htmlspecialchars($item['standard_calibrator']) ?>
                            </td>

                            <td class="py-3.5 px-4 text-right whitespace-nowrap align-middle">
                                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                    <button onclick="openGenerateModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>', '<?= htmlspecialchars(addslashes($item['customer_name'])) ?>', '<?= $item['scope_code'] ?>', <?= $isItemKan ? 1 : 0 ?>, '<?= $predicted['certificate_number'] ?>')" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-3 py-1.5 rounded-lg font-semibold text-xs shadow-subtle inline-flex items-center gap-1.5 transition-colors">
                                        <i class="ph-bold ph-plus-circle"></i>
                                        <span>Terbitkan (<?= $predicted['certificate_number'] ?>)</span>
                                    </button>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded bg-slate-100 text-slate-500 font-medium text-xs border border-slate-200">
                                        Mode Tinjau
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- SECTION 2: Daftar Sertifikat yang Telah Diterbitkan -->
<div id="archive-section" class="bg-white rounded-xl border border-slate-200/80 shadow-subtle overflow-hidden scroll-mt-20">
    <div class="p-3.5 sm:p-4 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                <h2 class="text-xs font-semibold text-slate-800 uppercase tracking-wider">Arsip Sertifikat Kalibrasi Resmi Terbit</h2>
            </div>
            <p class="text-[11px] text-slate-500 mt-0.5">Database sertifikat terbit standar ISO/IEC 17025 siap cetak dan terintegrasi QR code.</p>
        </div>
        <div class="text-xs text-slate-500 font-medium">
            Total: <?= $totalIssuedCount ?> berkas (<?= $totalKanCount ?> KAN • <?= $totalNonKanCount ?> Non-KAN)
        </div>
    </div>

    <!-- Toolbar: Filter, Cari, & Sortir Arsip -->
    <div class="p-3.5 border-b border-slate-100 bg-white">
        <form action="certificates.php" method="GET" class="flex flex-wrap items-center justify-between gap-3 text-xs">
            <input type="hidden" name="section" value="archive">
            
            <div class="flex flex-wrap items-center gap-2.5 flex-1 min-w-[280px]">
                <!-- Search -->
                <div class="relative flex-1 min-w-[200px]">
                    <i class="ph-bold ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Cari No. Sertifikat, Alat, Pelanggan..." class="w-full bg-white border border-slate-300 rounded-lg pl-8 pr-3 py-1.5 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>

                <!-- Filter Ruang Lingkup -->
                <select name="scope" class="bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 focus:outline-none focus:border-slate-800 transition-colors">
                    <option value="">Semua Lingkup</option>
                    <?php foreach ($scopes as $code => $scInfo): ?>
                        <option value="<?= $code ?>" <?= $scopeFilter === $code ? 'selected' : '' ?>>
                            [<?= $code ?>] <?= htmlspecialchars($scInfo['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Filter Status Akreditasi -->
                <select name="kan" class="bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 font-medium focus:outline-none focus:border-slate-800 transition-colors">
                    <option value="">Semua Akreditasi</option>
                    <option value="1" <?= $kanFilter === '1' ? 'selected' : '' ?>>Akreditasi KAN</option>
                    <option value="0" <?= $kanFilter === '0' ? 'selected' : '' ?>>Non-KAN (Awalan N)</option>
                </select>

                <!-- Sortir -->
                <select name="sort" class="bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 focus:outline-none focus:border-slate-800 transition-colors">
                    <option value="newest" <?= $sortOption === 'newest' ? 'selected' : '' ?>>Terbaru</option>
                    <option value="oldest" <?= $sortOption === 'oldest' ? 'selected' : '' ?>>Terlama</option>
                    <option value="cert_no" <?= $sortOption === 'cert_no' ? 'selected' : '' ?>>No. Sertifikat (A-Z)</option>
                    <option value="customer" <?= $sortOption === 'customer' ? 'selected' : '' ?>>Pelanggan (A-Z)</option>
                </select>
            </div>

            <div class="flex items-center gap-1.5">
                <button type="submit" class="px-3.5 py-1.5 rounded-lg bg-slate-900 text-white font-semibold hover:bg-slate-800 transition-colors inline-flex items-center gap-1.5 shadow-subtle">
                    <i class="ph-bold ph-faders"></i>
                    <span>Terapkan</span>
                </button>
                <?php if ($searchQuery !== '' || $scopeFilter !== '' || $kanFilter !== '' || $sortOption !== 'newest'): ?>
                    <a href="certificates.php#archive-section" class="px-2.5 py-1.5 rounded-lg bg-white border border-slate-300 text-slate-600 hover:bg-slate-50 font-medium transition-colors">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs min-w-[1080px]">
            <thead class="bg-slate-50/75 text-slate-500 font-semibold border-b border-slate-200 uppercase text-[10px] tracking-wider whitespace-nowrap">
                <tr>
                    <th class="py-3 px-4 w-[240px]">Nomor Sertifikat</th>
                    <th class="py-3 px-4">Alat & No. Seri</th>
                    <th class="py-3 px-4">Pelanggan</th>
                    <th class="py-3 px-3">Tgl Terbit & Kedaluwarsa</th>
                    <th class="py-3 px-3">Penandatangan</th>
                    <th class="py-3 px-3">Revisi</th>
                    <th class="py-3 px-4 text-right w-[180px]">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($issuedCertificates)): ?>
                    <tr>
                        <td colspan="7" class="py-10 text-center text-slate-400">
                            <?= ($searchQuery !== '' || $scopeFilter !== '' || $kanFilter !== '') ? 'Tidak ada sertifikat yang cocok dengan filter pencarian.' : 'Belum ada arsip sertifikat yang diterbitkan.' ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($issuedCertificates as $cert): ?>
                    <?php 
                        $scope = $scopes[$cert['scope_code']] ?? ['name' => $cert['scope_code']];
                        $isJustIssued = ($newlyIssued && $cert['certificate_number'] === $newlyIssued);
                        $isCertKan = (isset($cert['is_kan']) && (int)$cert['is_kan'] === 1 && substr($cert['certificate_number'], 0, 1) !== 'N');
                    ?>
                    <tr class="transition-colors <?= $isJustIssued ? 'bg-slate-50/90 border-l-3 border-l-slate-900' : 'hover:bg-slate-50/60' ?>">
                        <td class="py-3.5 px-4 align-middle">
                            <span class="font-mono text-xs font-semibold text-slate-900 tracking-tight">
                                <?= htmlspecialchars($cert['certificate_number']) ?>
                            </span>
                            <div class="text-[11px] text-slate-500 mt-0.5">
                                <?= $isCertKan ? 'KAN LK-088-IDN' : 'Non-KAN (Tertelusur)' ?> • [<?= htmlspecialchars($cert['scope_code']) ?>]
                            </div>
                        </td>

                        <td class="py-3.5 px-4 align-middle">
                            <p class="font-semibold text-slate-900 text-xs"><?= htmlspecialchars($cert['instrument_name']) ?></p>
                            <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($cert['brand'] ?: '-') ?> <?= htmlspecialchars($cert['model_type'] ?: '') ?></p>
                            <p class="font-mono text-[10px] text-slate-400 mt-0.5">SN: <?= htmlspecialchars($cert['serial_number']) ?></p>
                        </td>

                        <td class="py-3.5 px-4 align-middle">
                            <p class="font-semibold text-slate-800 text-xs"><?= htmlspecialchars($cert['customer_name']) ?></p>
                            <p class="font-mono text-[10px] text-slate-400 mt-0.5">SPK: <?= htmlspecialchars($cert['order_number']) ?></p>
                        </td>

                        <td class="py-3.5 px-3 align-middle font-mono whitespace-nowrap">
                            <p class="text-slate-800 text-xs"><?= formatIndonesianDate($cert['issue_date']) ?></p>
                            <p class="text-slate-400 text-[10px] mt-0.5">Exp: <?= formatIndonesianDate($cert['valid_until']) ?></p>
                        </td>

                        <td class="py-3.5 px-3 align-middle">
                            <p class="font-medium text-slate-800 text-xs"><?= htmlspecialchars($cert['technical_manager']) ?></p>
                            <span class="text-[10px] text-slate-400">Manajer Teknis</span>
                        </td>

                        <td class="py-3.5 px-3 align-middle whitespace-nowrap">
                            <?php if ($cert['revision_number'] === '00'): ?>
                                <span class="font-mono text-xs text-slate-600 font-medium">Rev-00</span>
                            <?php else: ?>
                                <span class="font-mono text-xs text-slate-900 font-semibold">Rev-<?= htmlspecialchars($cert['revision_number']) ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="py-3.5 px-4 text-right whitespace-nowrap align-middle">
                            <div class="flex items-center justify-end gap-1.5">
                                <a href="print_certificate.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-[#C81E26] hover:bg-[#B2151D] text-white shadow-subtle inline-flex items-center gap-1.5 transition-colors whitespace-nowrap" title="Buka Dokumen Resmi & Cetak / Buat PDF">
                                    <i class="ph-bold ph-file-pdf text-xs"></i>
                                    <span>Buat PDF</span>
                                </a>

                                <a href="verify.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" title="Cek Halaman Verifikasi QR Code" class="p-1.5 rounded-lg border border-slate-200 text-slate-500 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                                    <i class="ph-bold ph-qr-code text-sm"></i>
                                </a>

                                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                    <button onclick="openRevisionModal(<?= $cert['id'] ?>, '<?= htmlspecialchars(addslashes($cert['certificate_number'])) ?>')" title="Ajukan Revisi Nomor Sertifikat" class="p-1.5 rounded-lg border border-slate-200 text-slate-500 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                                        <i class="ph-bold ph-pencil-simple text-sm"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL 1: Generator Nomor Sertifikat Otomatis -->
<div id="generate-cert-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl border border-slate-200 w-full max-w-lg p-6 shadow-xl text-xs">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-5">
            <div>
                <h3 class="text-base font-bold text-slate-900 tracking-tight">Generator Nomor Sertifikat</h3>
                <p class="text-slate-500 text-[11px] mt-0.5">Penerbitan nomor resmi kalibrasi standar ISO/IEC 17025.</p>
            </div>
            <button onclick="closeModal('generate-cert-modal')" class="text-slate-400 hover:text-slate-700 p-1 rounded-md transition-colors">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <form action="certificates.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="generate_certificate">
            <input type="hidden" id="modal-inst-id" name="instrument_id" value="">

            <div class="bg-slate-50 p-3 rounded-lg border border-slate-200 space-y-1 text-[11px]">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">Nama Alat:</span>
                    <strong id="modal-inst-name" class="text-slate-900 font-semibold"></strong>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">Pelanggan:</span>
                    <span id="modal-customer-name" class="text-slate-700 font-medium"></span>
                </div>
            </div>

            <!-- Preview Nomor -->
            <div class="bg-slate-50 p-4 rounded-lg border border-slate-200 text-center transition-all">
                <span class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider block mb-1">
                    Preview Nomor Sertifikat
                </span>
                <div id="modal-cert-preview" class="font-mono text-xl font-bold text-slate-900 tracking-wider transition-opacity duration-150">
                    2605P0012-00
                </div>
                <p id="modal-cert-desc" class="text-[11px] text-slate-500 mt-1">
                    Format: Tahun (26) + Bulan + Scope + No. Urut + Revisi (-00)
                </p>
            </div>

            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Status Akreditasi <span class="text-rose-500">*</span></label>
                        <select id="modal-is-kan" name="is_kan" onchange="refreshModalCertPreview()" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:outline-none focus:border-slate-800 transition-colors">
                            <option value="1">Akreditasi KAN (ISO/IEC 17025)</option>
                            <option value="0">Non-KAN (Awalan N)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Tanggal Terbit <span class="text-rose-500">*</span></label>
                        <input type="date" id="modal-issue-date" name="issue_date" required value="<?= date('Y-m-d') ?>" onchange="refreshModalCertPreview()" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Masa Berlaku</label>
                        <select name="valid_months" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                            <option value="12">12 Bulan (1 Tahun)</option>
                            <option value="6">6 Bulan</option>
                            <option value="24">24 Bulan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Manajer Teknis</label>
                        <input type="text" name="technical_manager" value="Ir. Hendra Wijaya, M.T." class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModal('generate-cert-modal')" class="px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">Batal</button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-4 py-2 rounded-lg font-semibold shadow-subtle transition-colors">
                    Terbitkan Sertifikat Resmi &rarr;
                </button>
            </div>
        </form>

    </div>
</div>

<!-- MODAL 2: Revisi Sertifikat -->
<div id="revision-cert-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl border border-slate-200 w-full max-w-md p-6 shadow-xl text-xs">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
            <div>
                <h3 class="text-base font-bold text-slate-900 tracking-tight">Revisi / Amandemen Sertifikat</h3>
                <p class="text-slate-500 text-[11px] mt-0.5">Sesuai klausul amandemen sertifikat ISO/IEC 17025.</p>
            </div>
            <button onclick="closeModal('revision-cert-modal')" class="text-slate-400 hover:text-slate-700 p-1 rounded-md transition-colors">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <form action="certificates.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_revision">
            <input type="hidden" id="rev-cert-id" name="certificate_id" value="">

            <div class="bg-slate-50 p-3 rounded-lg border border-slate-200">
                <span class="text-slate-500 text-[11px]">Sertifikat saat ini:</span>
                <p id="rev-cert-num" class="font-mono text-sm font-bold text-slate-900 mt-0.5"></p>
            </div>

            <div>
                <label class="block font-medium text-slate-700 mb-1">Alasan / Catatan Revisi <span class="text-rose-500">*</span></label>
                <textarea name="revision_notes" required rows="3" placeholder="Contoh: Perbaikan kesalahan penulisan nama atau alamat pelanggan." class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModal('revision-cert-modal')" class="px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">Batal</button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-4 py-2 rounded-lg font-semibold shadow-subtle transition-colors">Terapkan Revisi</button>
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
