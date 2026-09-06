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

<!-- 1. Top Enterprise Control Bar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-xl font-bold text-slate-900 tracking-tight">Monitoring Alur Kalibrasi</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            Pelacakan posisi berkas operasional kalibrasi, lembar kerja teknisi, dan penerbitan sertifikat resmi ISO/IEC 17025.
        </p>
    </div>

    <!-- Top Action Buttons -->
    <div class="flex items-center gap-2 shrink-0">
        <a href="index.php" class="px-3 py-1.5 rounded-lg bg-white border border-slate-200 text-xs font-medium text-slate-700 hover:bg-slate-50 transition-colors inline-flex items-center gap-1.5 shadow-subtle">
            <i class="ph-bold ph-arrows-clockwise text-slate-400"></i>
            <span>Refresh</span>
        </a>
        <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
            <a href="orders.php?action=create" class="px-3.5 py-1.5 rounded-lg bg-[#C81E26] hover:bg-[#B2151D] text-white text-xs font-semibold inline-flex items-center gap-1.5 transition-colors shadow-subtle whitespace-nowrap">
                <i class="ph-bold ph-plus text-xs"></i>
                <span>+ Buat SPK Baru</span>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Modern Enterprise Metric Strip (Clean, Flat, Calm) -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-subtle mb-6 overflow-hidden">
    <div class="grid grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
        
        <!-- Metric 1: Total Orders -->
        <div class="p-4 sm:p-5">
            <p class="text-xs font-medium text-slate-500">Total Work Order</p>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-2xl font-bold text-slate-900 tracking-tight font-mono"><?= $totalOrders ?></span>
                <span class="text-xs text-slate-400">SPK</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1.5 font-medium">
                <span><?= $inLabCount ?> In-Lab</span>
                <span class="text-slate-300 mx-1">·</span>
                <span><?= $onSiteCount ?> On-Site</span>
            </p>
        </div>

        <!-- Metric 2: In Progress -->
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-slate-500">Sedang Dikalibrasi</p>
                <span class="w-2 h-2 rounded-full bg-sky-500"></span>
            </div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-2xl font-bold text-slate-900 tracking-tight font-mono"><?= $inProgressCount ?></span>
                <span class="text-xs text-slate-400">Alat</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1.5">
                <?php if (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                    <a href="worksheet.php" class="text-sky-700 hover:text-sky-800 font-medium inline-flex items-center gap-1">
                        Buka Worksheet <i class="ph-bold ph-arrow-right text-[10px]"></i>
                    </a>
                <?php else: ?>
                    <span>Tahap pengujian teknisi</span>
                <?php endif; ?>
            </p>
        </div>

        <!-- Metric 3: Ready for Cert -->
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-slate-500">Menunggu Sertifikat</p>
                <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
            </div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-2xl font-bold text-slate-900 tracking-tight font-mono"><?= $readyForCertCount ?></span>
                <span class="text-xs text-slate-400">Data Masuk</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1.5">
                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                    <a href="certificates.php" class="text-indigo-700 hover:text-indigo-800 font-medium inline-flex items-center gap-1">
                        Proses Penerbitan <i class="ph-bold ph-arrow-right text-[10px]"></i>
                    </a>
                <?php else: ?>
                    <span>Siap dibuatkan nomor resmi</span>
                <?php endif; ?>
            </p>
        </div>

        <!-- Metric 4: Certified -->
        <div class="p-4 sm:p-5">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-slate-500">Sertifikat Terbit</p>
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            </div>
            <div class="flex items-baseline gap-2 mt-1">
                <span class="text-2xl font-bold text-slate-900 tracking-tight font-mono"><?= $certifiedCount ?></span>
                <span class="text-xs text-slate-400">Dokumen</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1.5 font-medium">
                <span><?= $certifiedKanCount ?> KAN</span>
                <span class="text-slate-300 mx-1">·</span>
                <span><?= $certifiedNonKanCount ?> Non-KAN</span>
            </p>
        </div>

    </div>
</div>

<!-- 3. Status Tabs (Modern Minimalist Filter Bar) -->
<div class="border-b border-slate-200 mb-4 flex items-center justify-between gap-4 overflow-x-auto">
    <nav class="flex items-center gap-1 sm:gap-2 -mb-px text-xs">
        <a href="index.php?filter=all<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" 
           class="py-2.5 px-3 border-b-2 font-medium whitespace-nowrap transition-colors <?= $filter === 'all' ? 'border-[#C81E26] text-slate-900 font-semibold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
            Semua Data (<?= $totalOrders ?>)
        </a>
        <a href="index.php?filter=pending_worksheet<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" 
           class="py-2.5 px-3 border-b-2 font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 <?= $filter === 'pending_worksheet' ? 'border-[#C81E26] text-slate-900 font-semibold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
            <span class="w-1.5 h-1.5 rounded-full bg-sky-500"></span>
            Dalam Pengujian (<?= $inProgressCount ?>)
        </a>
        <a href="index.php?filter=ready_for_cert<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" 
           class="py-2.5 px-3 border-b-2 font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 <?= $filter === 'ready_for_cert' ? 'border-[#C81E26] text-slate-900 font-semibold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
            Siap Sertifikat (<?= $readyForCertCount ?>)
        </a>
        <a href="index.php?filter=certified<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" 
           class="py-2.5 px-3 border-b-2 font-medium whitespace-nowrap transition-colors flex items-center gap-1.5 <?= $filter === 'certified' ? 'border-[#C81E26] text-slate-900 font-semibold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
            Sertifikat Terbit (<?= $certifiedCount ?>)
        </a>
    </nav>
</div>

<!-- 4. Table Container & Integrated Toolbar -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-subtle overflow-hidden">
    
    <!-- Table Controls Bar -->
    <div class="p-3.5 sm:p-4 border-b border-slate-200/80 bg-slate-50/50 flex flex-col md:flex-row md:items-center justify-between gap-3">
        
        <!-- Search & Filter Form -->
        <form action="index.php" method="GET" class="flex flex-wrap items-center gap-2 flex-1">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">

            <!-- Search Input -->
            <div class="relative flex-1 min-w-[200px] max-w-sm">
                <i class="ph-bold ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nomor order, alat, customer, SN..." 
                       class="w-full bg-white border border-slate-200 rounded-lg pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-slate-400 transition-colors">
            </div>

            <!-- Scope Filter Dropdown -->
            <select name="scope" onchange="this.form.submit()" class="bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs text-slate-700 focus:outline-none focus:border-slate-400 transition-colors">
                <option value="">Semua Ruang Lingkup</option>
                <?php foreach ($scopes as $code => $sc): ?>
                    <option value="<?= $code ?>" <?= $scopeFilter === $code ? 'selected' : '' ?>>
                        [<?= $code ?>] <?= htmlspecialchars(explode(' ', $sc['name'])[0]) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium transition-colors">
                Cari
            </button>

            <?php if ($search || $scopeFilter): ?>
                <a href="index.php?filter=<?= htmlspecialchars($filter) ?>" class="text-xs text-slate-500 hover:text-slate-800 px-2 py-1 transition-colors">
                    Reset
                </a>
            <?php endif; ?>
        </form>

        <!-- Count Indicator -->
        <div class="text-xs text-slate-500 shrink-0 font-medium">
            <span><?= count($instruments) ?> data ditemukan</span>
        </div>

    </div>

    <!-- Structured Data Table (Spacious, Crisp & Scannable) -->
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs min-w-[1200px]">
            <thead class="bg-slate-50/75 text-slate-500 font-semibold border-b border-slate-200 uppercase text-[10px] tracking-wider whitespace-nowrap">
                <tr>
                    <th class="py-3 px-4 w-[190px]">No. Order & Pelanggan</th>
                    <th class="py-3 px-4 w-[230px]">Nama Alat & Spesifikasi</th>
                    <th class="py-3 px-3 w-[120px]">Ruang Lingkup</th>
                    <th class="py-3 px-3 w-[90px]">Lokasi</th>
                    <th class="py-3 px-4 w-[140px]">Teknisi</th>
                    <th class="py-3 px-4 w-[160px]">Status Alur</th>
                    <th class="py-3 px-4 w-[160px]">No. Sertifikat</th>
                    <th class="py-3 px-4 text-right w-[150px]">Tindak Lanjut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($instruments)): ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-400">
                            <i class="ph-bold ph-folder-open text-3xl mb-2 inline-block text-slate-300"></i>
                            <p class="text-xs">Tidak ada data alat yang sesuai kriteria pencarian atau filter yang dipilih.</p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($instruments as $inst): ?>
                    <?php 
                        $scInfo = $scopes[$inst['scope_code']] ?? ['name' => $inst['scope_code']];
                        $isInstKan = (isset($inst['inst_is_kan']) && (int)$inst['inst_is_kan'] === 0) ? false : true;
                    ?>
                    <tr class="hover:bg-slate-50/60 transition-colors">
                        
                        <!-- Order & Pelanggan -->
                        <td class="py-3.5 px-4 align-middle whitespace-nowrap">
                            <span class="font-mono text-xs font-semibold text-slate-900 tracking-tight">
                                <?= htmlspecialchars($inst['order_number']) ?>
                            </span>
                            <p class="text-xs text-slate-600 mt-0.5 truncate max-w-[180px]" title="<?= htmlspecialchars($inst['customer_name']) ?>">
                                <?= htmlspecialchars($inst['customer_name']) ?>
                            </p>
                        </td>

                        <!-- Alat & Identifikasi -->
                        <td class="py-3.5 px-4 align-middle">
                            <p class="font-semibold text-slate-900 text-xs truncate max-w-[210px]" title="<?= htmlspecialchars($inst['instrument_name']) ?>">
                                <?= htmlspecialchars($inst['instrument_name']) ?>
                            </p>
                            <p class="text-[11px] text-slate-500 mt-0.5 truncate max-w-[210px]">
                                <?= htmlspecialchars($inst['brand']) ?> · <?= htmlspecialchars($inst['model_type']) ?>
                            </p>
                            <p class="font-mono text-[10px] text-slate-400 mt-0.5">SN: <?= htmlspecialchars($inst['serial_number']) ?></p>
                        </td>

                        <!-- Ruang Lingkup -->
                        <td class="py-3.5 px-3 align-middle whitespace-nowrap">
                            <p class="font-mono text-xs text-slate-800 font-medium">
                                [<?= htmlspecialchars($inst['scope_code']) ?>] <?= htmlspecialchars(explode(' ', $scInfo['name'])[0]) ?>
                            </p>
                            <p class="text-[10px] mt-0.5 font-medium <?= $isInstKan ? 'text-slate-500' : 'text-amber-700' ?>">
                                <?= $isInstKan ? 'Akreditasi KAN' : 'Non-KAN (Awalan N)' ?>
                            </p>
                        </td>

                        <!-- Lokasi Layanan -->
                        <td class="py-3.5 px-3 align-middle whitespace-nowrap">
                            <?= renderLocationBadge($inst['service_type']) ?>
                        </td>

                        <!-- Teknisi Pelaksana -->
                        <td class="py-3.5 px-4 align-middle whitespace-nowrap">
                            <span class="text-xs text-slate-700 font-medium"><?= htmlspecialchars($inst['technician_name'] ?: '-') ?></span>
                        </td>

                        <!-- Status Alur Kerja -->
                        <td class="py-3.5 px-4 align-middle whitespace-nowrap">
                            <?= renderStatusBadge($inst['instrument_status']) ?>
                        </td>

                        <!-- Nomor Sertifikat -->
                        <td class="py-3.5 px-4 align-middle whitespace-nowrap">
                            <?php if ($inst['certificate_number']): ?>
                                <span class="font-mono text-xs font-semibold text-slate-900">
                                    <?= htmlspecialchars($inst['certificate_number']) ?>
                                </span>
                                <?php $isCertKan = (substr($inst['certificate_number'], 0, 1) !== 'N'); ?>
                                <p class="text-[10px] text-slate-400 mt-0.5">
                                    <?= $isCertKan ? 'KAN LK-088' : 'Non-KAN' ?>
                                </p>
                            <?php else: ?>
                                <span class="text-slate-400 text-xs">-</span>
                            <?php endif; ?>
                        </td>

                        <!-- Aksi / Action Button -->
                        <td class="py-3.5 px-4 text-right align-middle whitespace-nowrap">
                            <?php if ($inst['instrument_status'] === 'DATA_SUBMITTED'): ?>
                                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                    <a href="certificates.php?instrument_id=<?= $inst['instrument_id'] ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-slate-900 hover:bg-slate-800 text-white inline-flex items-center gap-1 transition-colors">
                                        <span>Terbitkan Sertifikat</span>
                                    </a>
                                <?php else: ?>
                                    <span class="text-xs text-indigo-700 font-medium">Siap Diterbitkan</span>
                                <?php endif; ?>
                            <?php elseif ($inst['instrument_status'] === 'CERTIFIED' && $inst['certificate_number']): ?>
                                <div class="inline-flex items-center gap-1.5 justify-end">
                                    <a href="print_certificate.php?cert=<?= urlencode($inst['certificate_number']) ?>" target="_blank" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 inline-flex items-center gap-1 transition-colors shadow-subtle" title="Buka Dokumen PDF">
                                        <i class="ph-bold ph-printer text-slate-500"></i>
                                        <span>Cetak PDF</span>
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="inline-flex items-center gap-1.5 justify-end">
                                    <?php if (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                                        <a href="worksheet.php?instrument_id=<?= $inst['instrument_id'] ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 inline-flex items-center gap-1 transition-colors shadow-subtle">
                                            <span>Worksheet</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-500 font-medium">Dalam Pengujian</span>
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
    <div class="p-3.5 border-t border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-slate-500">
        <span>Menampilkan <strong><?= count($instruments) ?></strong> instrumen kalibrasi</span>
        <span class="font-mono text-[11px] text-slate-400">ISO/IEC 17025:2017 Laboratory Information System</span>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
