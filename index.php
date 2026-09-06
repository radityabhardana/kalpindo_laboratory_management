<?php
/**
 * Dashboard Operasional Utama - Modern Enterprise System
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Designed according to Enterprise SaaS Standards
 */

declare(strict_types=1);

$pageTitle = 'Dashboard Operasional';
require_once __DIR__ . '/includes/header.php';

$db = getDbConnection();

// Hitung metrik statistik
$totalOrders = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$inLabCount = (int)$db->query("SELECT COUNT(*) FROM orders WHERE service_type = 'IN_LAB'")->fetchColumn();
$onSiteCount = (int)$db->query("SELECT COUNT(*) FROM orders WHERE service_type = 'ON_SITE'")->fetchColumn();

$inProgressCount = (int)$db->query("SELECT COUNT(*) FROM instruments WHERE status IN ('ASSIGNED', 'IN_PROGRESS')")->fetchColumn();
$readyForCertCount = (int)$db->query("SELECT COUNT(*) FROM instruments WHERE status = 'DATA_SUBMITTED'")->fetchColumn();
$certifiedCount = (int)$db->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
$certifiedKanCount = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE (is_kan = 1 OR is_kan IS NULL) AND certificate_number NOT LIKE 'N%'")->fetchColumn();
$certifiedNonKanCount = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE is_kan = 0 OR certificate_number LIKE 'N%'")->fetchColumn();

// Filter & Search
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$scopeFilter = trim($_GET['scope'] ?? '');

$query = "
    SELECT 
        i.id as instrument_id,
        i.name as instrument_name,
        i.brand,
        i.model_type,
        i.serial_number,
        i.scope_code,
        i.is_kan as inst_is_kan,
        i.technician_name,
        i.status as instrument_status,
        i.calibration_date,
        o.order_number,
        o.customer_name,
        o.service_type,
        c.certificate_number,
        c.is_kan as cert_is_kan,
        c.issue_date,
        w.id as worksheet_id,
        w.submitted_at
    FROM instruments i
    JOIN orders o ON i.order_id = o.id
    LEFT JOIN worksheets w ON w.instrument_id = i.id
    LEFT JOIN certificates c ON c.instrument_id = i.id
    WHERE 1=1
";

if ($filter === 'pending_worksheet') {
    $query .= " AND i.status IN ('ASSIGNED', 'IN_PROGRESS')";
} elseif ($filter === 'ready_for_cert') {
    $query .= " AND i.status = 'DATA_SUBMITTED'";
} elseif ($filter === 'certified') {
    $query .= " AND i.status = 'CERTIFIED'";
}

if ($scopeFilter) {
    $query .= " AND i.scope_code = " . $db->quote($scopeFilter);
}

// BUG FIX: Tambahkan o.order_number ke filter pencarian
if ($search) {
    $searchEscaped = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
    $query .= " AND (i.name LIKE " . $db->quote($searchEscaped) . " 
                 OR i.serial_number LIKE " . $db->quote($searchEscaped) . " 
                 OR o.customer_name LIKE " . $db->quote($searchEscaped) . " 
                 OR o.order_number LIKE " . $db->quote($searchEscaped) . "
                 OR c.certificate_number LIKE " . $db->quote($searchEscaped) . ")";
}

$query .= " ORDER BY i.id DESC";
$instruments = $db->query($query)->fetchAll();
$scopes = getScopeList();
?>

<!-- 1. Top Enterprise Header Bar -->
<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="px-2 py-0.5 rounded text-[11px] font-mono font-semibold bg-slate-200/80 text-slate-800 border border-slate-300 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700">
                OVERVIEW
            </span>
            <span class="text-xs text-slate-400">·</span>
            <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">Sistem Alur Kerja Laboratorium</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 tracking-tight">Monitoring Alur Kalibrasi</h1>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-3xl">
            Pelacakan posisi berkas operasional kalibrasi, lembar kerja teknisi, dan penerbitan sertifikat resmi ISO/IEC 17025.
        </p>
    </div>

    <!-- Top Action Buttons -->
    <div class="flex items-center gap-2 shrink-0">
        <a href="index.php" class="btn-surface">
            <i class="ph-bold ph-arrows-clockwise text-slate-400"></i>
            <span>Refresh</span>
        </a>
        <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
            <a href="orders.php?action=create" class="btn-brand-primary">
                <i class="ph-bold ph-plus text-xs"></i>
                <span>+ Buat SPK Baru</span>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Operational Summary (4 Structured KPI Cards) -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    
    <!-- Metric 1: Total Orders -->
    <div class="kpi-widget accent-primary">
        <div class="flex items-center justify-between">
            <span class="kpi-label">Total Work Order</span>
            <i class="ph-bold ph-clipboard-text text-base text-slate-400"></i>
        </div>
        <div class="kpi-value text-slate-900 dark:text-slate-100"><?= $totalOrders ?></div>
        <div class="kpi-subtext font-medium">
            <span><?= $inLabCount ?> In-Lab</span>
            <span class="text-slate-300 dark:text-slate-600">·</span>
            <span><?= $onSiteCount ?> On-Site</span>
        </div>
    </div>

    <!-- Metric 2: In Progress -->
    <div class="kpi-widget accent-info">
        <div class="flex items-center justify-between">
            <span class="kpi-label">Sedang Dikalibrasi</span>
            <span class="w-2 h-2 rounded-full bg-sky-500 animate-pulse"></span>
        </div>
        <div class="kpi-value text-sky-700 dark:text-sky-400"><?= $inProgressCount ?></div>
        <div class="kpi-subtext">
            <?php if (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                <a href="worksheet.php" class="text-sky-700 dark:text-sky-400 hover:underline font-semibold inline-flex items-center gap-1">
                    Buka Worksheet <i class="ph-bold ph-arrow-right text-[10px]"></i>
                </a>
            <?php else: ?>
                <span>Tahap pengujian teknisi</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metric 3: Ready for Cert -->
    <div class="kpi-widget accent-purple">
        <div class="flex items-center justify-between">
            <span class="kpi-label">Menunggu Sertifikat</span>
            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
        </div>
        <div class="kpi-value text-indigo-700 dark:text-indigo-400"><?= $readyForCertCount ?></div>
        <div class="kpi-subtext">
            <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                <a href="certificates.php" class="text-indigo-700 dark:text-indigo-400 hover:underline font-semibold inline-flex items-center gap-1">
                    Proses Penerbitan <i class="ph-bold ph-arrow-right text-[10px]"></i>
                </a>
            <?php else: ?>
                <span>Siap dibuatkan nomor resmi</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metric 4: Certified -->
    <div class="kpi-widget accent-success">
        <div class="flex items-center justify-between">
            <span class="kpi-label">Sertifikat Terbit</span>
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
        </div>
        <div class="kpi-value text-emerald-700 dark:text-emerald-400"><?= $certifiedCount ?></div>
        <div class="kpi-subtext font-medium">
            <span><?= $certifiedKanCount ?> KAN</span>
            <span class="text-slate-300 dark:text-slate-600">·</span>
            <span><?= $certifiedNonKanCount ?> Non-KAN</span>
        </div>
    </div>

</div>

<!-- 3. Status Filter Tabs -->
<div class="border-b border-slate-200 dark:border-slate-800 mb-5 flex items-center justify-between gap-4 overflow-x-auto">
    <nav class="flex items-center gap-2 -mb-px text-xs">
        <a href="index.php?filter=all<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" 
           class="py-2.5 px-3.5 border-b-2 font-medium whitespace-nowrap transition-colors <?= $filter === 'all' ? 'border-[#C81E26] text-[#C81E26] font-bold dark:border-red-500 dark:text-red-400' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300 dark:text-slate-400 dark:hover:text-slate-200' ?>">
            Semua Data (<?= $totalOrders ?>)
        </a>
        <a href="index.php?filter=pending_worksheet<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" 
           class="py-2.5 px-3.5 border-b-2 font-medium whitespace-nowrap transition-colors flex items-center gap-2 <?= $filter === 'pending_worksheet' ? 'border-[#C81E26] text-[#C81E26] font-bold dark:border-red-500 dark:text-red-400' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300 dark:text-slate-400 dark:hover:text-slate-200' ?>">
            <span class="w-2 h-2 rounded-full bg-sky-500"></span>
            Dalam Pengujian (<?= $inProgressCount ?>)
        </a>
        <a href="index.php?filter=ready_for_cert<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" 
           class="py-2.5 px-3.5 border-b-2 font-medium whitespace-nowrap transition-colors flex items-center gap-2 <?= $filter === 'ready_for_cert' ? 'border-[#C81E26] text-[#C81E26] font-bold dark:border-red-500 dark:text-red-400' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300 dark:text-slate-400 dark:hover:text-slate-200' ?>">
            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
            Siap Sertifikat (<?= $readyForCertCount ?>)
        </a>
        <a href="index.php?filter=certified<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" 
           class="py-2.5 px-3.5 border-b-2 font-medium whitespace-nowrap transition-colors flex items-center gap-2 <?= $filter === 'certified' ? 'border-[#C81E26] text-[#C81E26] font-bold dark:border-red-500 dark:text-red-400' : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300 dark:text-slate-400 dark:hover:text-slate-200' ?>">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            Sertifikat Terbit (<?= $certifiedCount ?>)
        </a>
    </nav>
</div>

<!-- 4. Table Container & Integrated Toolbar -->
<div class="ent-card overflow-hidden">
    
    <!-- Table Controls Bar -->
    <div class="p-3.5 sm:p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40 flex flex-col md:flex-row md:items-center justify-between gap-3">
        
        <!-- Search & Filter Form -->
        <form action="index.php" method="GET" class="flex flex-wrap items-center gap-2.5 flex-1">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">

            <!-- Search Input -->
            <div class="relative flex-1 min-w-[220px] max-w-md">
                <i class="ph-bold ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari No. Order, Alat, Customer, No. Seri, No. Sertifikat..." class="ent-input pl-8">
            </div>

            <!-- Scope Filter Dropdown -->
            <select name="scope" onchange="this.form.submit()" class="ent-select w-auto">
                <option value="">Semua Ruang Lingkup</option>
                <?php foreach ($scopes as $code => $sc): ?>
                    <option value="<?= $code ?>" <?= $scopeFilter === $code ? 'selected' : '' ?>>
                        [<?= $code ?>] <?= htmlspecialchars(explode(' ', $sc['name'])[0]) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-brand-primary">
                Cari
            </button>

            <?php if ($search || $scopeFilter): ?>
                <a href="index.php?filter=<?= htmlspecialchars($filter) ?>" class="btn-surface">
                    Reset
                </a>
            <?php endif; ?>
        </form>

        <!-- Count Indicator -->
        <div class="text-xs text-slate-500 dark:text-slate-400 shrink-0 font-medium">
            <span><?= count($instruments) ?> data ditemukan</span>
        </div>

    </div>

    <!-- Structured Data Table -->
    <div class="overflow-x-auto">
        <table class="ent-table min-w-[1150px]">
            <thead>
                <tr>
                    <th class="w-[190px]">No. Order & Pelanggan</th>
                    <th class="w-[240px]">Nama Alat & Spesifikasi</th>
                    <th class="w-[140px]">Ruang Lingkup</th>
                    <th class="w-[90px]">Layanan</th>
                    <th class="w-[130px]">Teknisi</th>
                    <th class="w-[160px]">Status Alur</th>
                    <th class="w-[170px]">No. Sertifikat</th>
                    <th class="text-right w-[150px]">Tindak Lanjut</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($instruments)): ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-400">
                            <i class="ph-bold ph-folder-open text-3xl mb-2 inline-block text-slate-300 dark:text-slate-600"></i>
                            <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Tidak ada data alat yang sesuai kriteria pencarian atau filter yang dipilih.</p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($instruments as $inst): ?>
                    <?php 
                        $scInfo = $scopes[$inst['scope_code']] ?? ['name' => $inst['scope_code']];
                        $isInstKan = (isset($inst['inst_is_kan']) && (int)$inst['inst_is_kan'] === 0) ? false : true;
                        $menuIdInst = "action-menu-inst-" . $inst['instrument_id'];
                    ?>
                    <tr>
                        
                        <!-- Order & Pelanggan -->
                        <td class="whitespace-nowrap">
                            <div class="font-mono text-xs font-bold text-slate-900 dark:text-slate-100 tracking-tight">
                                <?= htmlspecialchars($inst['order_number']) ?>
                            </div>
                            <div class="cell-secondary truncate max-w-[180px]" title="<?= htmlspecialchars($inst['customer_name']) ?>">
                                <?= htmlspecialchars($inst['customer_name']) ?>
                            </div>
                        </td>

                        <!-- Alat & Identifikasi -->
                        <td>
                            <div class="cell-primary truncate max-w-[220px]" title="<?= htmlspecialchars($inst['instrument_name']) ?>">
                                <?= htmlspecialchars($inst['instrument_name']) ?>
                            </div>
                            <div class="cell-secondary truncate max-w-[220px]">
                                <?= htmlspecialchars($inst['brand']) ?> · <?= htmlspecialchars($inst['model_type']) ?>
                            </div>
                            <div class="cell-meta">SN: <?= htmlspecialchars($inst['serial_number']) ?></div>
                        </td>

                        <!-- Ruang Lingkup & Akreditasi -->
                        <td class="whitespace-nowrap">
                            <div class="font-mono text-xs font-semibold text-slate-800 dark:text-slate-200">
                                [<?= htmlspecialchars($inst['scope_code']) ?>] <?= htmlspecialchars(explode(' ', $scInfo['name'])[0]) ?>
                            </div>
                            <div class="mt-1">
                                <?= renderAccreditationBadge($isInstKan) ?>
                            </div>
                        </td>

                        <!-- Lokasi Layanan -->
                        <td class="whitespace-nowrap">
                            <?= renderLocationBadge($inst['service_type']) ?>
                        </td>

                        <!-- Teknisi Pelaksana -->
                        <td class="whitespace-nowrap">
                            <span class="text-xs font-medium text-slate-800 dark:text-slate-200"><?= htmlspecialchars($inst['technician_name'] ?: '-') ?></span>
                        </td>

                        <!-- Status Alur Kerja -->
                        <td class="whitespace-nowrap">
                            <?= renderStatusBadge($inst['instrument_status']) ?>
                        </td>

                        <!-- Nomor Sertifikat -->
                        <td class="whitespace-nowrap">
                            <?php if ($inst['certificate_number']): ?>
                                <span class="font-mono text-xs font-bold text-slate-900 dark:text-slate-100">
                                    <?= htmlspecialchars($inst['certificate_number']) ?>
                                </span>
                                <?php $isCertKan = (substr($inst['certificate_number'], 0, 1) !== 'N'); ?>
                                <div class="mt-0.5">
                                    <?= renderAccreditationBadge($isCertKan) ?>
                                </div>
                            <?php else: ?>
                                <span class="text-slate-400 text-xs font-mono">-</span>
                            <?php endif; ?>
                        </td>

                        <!-- Aksi / Action Button -->
                        <td class="text-right whitespace-nowrap">
                            <?php if ($inst['instrument_status'] === 'DATA_SUBMITTED'): ?>
                                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                    <a href="certificates.php?instrument_id=<?= $inst['instrument_id'] ?>" class="btn-brand-primary">
                                        <i class="ph-bold ph-certificate text-xs"></i>
                                        <span>Terbitkan</span>
                                    </a>
                                <?php else: ?>
                                    <span class="badge-status purple">Siap Terbit</span>
                                <?php endif; ?>
                            <?php elseif ($inst['instrument_status'] === 'CERTIFIED' && $inst['certificate_number']): ?>
                                <div class="inline-flex items-center gap-1.5 justify-end">
                                    <a href="print_certificate.php?cert=<?= urlencode($inst['certificate_number']) ?>" target="_blank" class="btn-surface text-xs" title="Buka Dokumen PDF">
                                        <i class="ph-bold ph-file-pdf text-[#C81E26]"></i>
                                        <span>Cetak PDF</span>
                                    </a>

                                    <div class="action-menu-container">
                                        <button type="button" onclick="toggleActionMenu('<?= $menuIdInst ?>', event)" class="btn-icon" title="Opsi" aria-label="Menu Opsi">
                                            <i class="ph-bold ph-dots-three-vertical text-sm"></i>
                                        </button>
                                        <div id="<?= $menuIdInst ?>" class="action-menu-dropdown">
                                            <a href="verify.php?cert=<?= urlencode($inst['certificate_number']) ?>" target="_blank" class="action-menu-item">
                                                <i class="ph-bold ph-qr-code text-slate-500"></i>
                                                <span>Verifikasi QR Code</span>
                                            </a>
                                            <a href="certificates.php?search=<?= urlencode($inst['certificate_number']) ?>#archive-section" class="action-menu-item">
                                                <i class="ph-bold ph-folder-open text-slate-500"></i>
                                                <span>Lihat di Arsip</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="inline-flex items-center gap-1.5 justify-end">
                                    <?php if (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                                        <a href="worksheet.php?instrument_id=<?= $inst['instrument_id'] ?>" class="btn-surface text-xs">
                                            <i class="ph-bold ph-wrench text-slate-500"></i>
                                            <span>Worksheet</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge-status info">Tahap Lab</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Table Footer Summary -->
    <div class="ent-card-footer flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-slate-500">
        <span>Menampilkan <strong><?= count($instruments) ?></strong> instrumen kalibrasi</span>
        <span class="font-mono text-[11px] text-slate-400">ISO/IEC 17025:2017 Laboratory Information System</span>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
