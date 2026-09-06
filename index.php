<?php
/**
 * Dashboard Operasional Utama - Enterprise Internal System
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Styled according to https://radityaproject.vercel.app/
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
        i.technician_name,
        i.status as instrument_status,
        i.calibration_date,
        o.order_number,
        o.customer_name,
        o.service_type,
        c.certificate_number,
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

if ($search) {
    $searchEscaped = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
    $query .= " AND (i.name LIKE " . $db->quote($searchEscaped) . " 
                 OR i.serial_number LIKE " . $db->quote($searchEscaped) . " 
                 OR o.customer_name LIKE " . $db->quote($searchEscaped) . " 
                 OR c.certificate_number LIKE " . $db->quote($searchEscaped) . ")";
}

$query .= " ORDER BY i.id DESC";
$instruments = $db->query($query)->fetchAll();
$scopes = getScopeList();

// Berkas yang menunggu sertifikat (untuk quick action di sidebar kanan)
$pendingCerts = $db->query("
    SELECT i.id, i.name, i.serial_number, i.scope_code, o.customer_name, w.submitted_at
    FROM instruments i
    JOIN orders o ON i.order_id = o.id
    JOIN worksheets w ON w.instrument_id = i.id
    WHERE i.status = 'DATA_SUBMITTED'
    ORDER BY w.submitted_at ASC
")->fetchAll();
?>

<!-- 1. Top Enterprise Control Bar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
            <span>Portal Karyawan</span>
            <span>/</span>
            <span>Operasional Kalibrasi</span>
            <span>/</span>
            <span class="text-slate-800 font-semibold">Monitoring Alur</span>
        </div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Dashboard Alur Kalibrasi & Sertifikasi</h1>
        <p class="text-xs text-gray-500 mt-1 flex items-center gap-2">
            <span>Selamat bertugas, <strong class="text-slate-800 font-bold"><?= htmlspecialchars($currentUser['full_name']) ?></strong></span>
            <span>•</span>
            <span class="inline-flex items-center gap-1 font-bold px-2 py-0.5 rounded text-[10px] border <?= $activeRoleInfo['badge_class'] ?>">
                <i class="ph-bold <?= $activeRoleInfo['icon'] ?> text-xs"></i>
                <?= htmlspecialchars($activeRoleInfo['name']) ?>
            </span>
        </p>
    </div>

    <!-- Toolbar Buttons -->
    <div class="flex items-center gap-2.5">
        <a href="index.php" class="px-3.5 py-2 rounded-lg bg-white border border-gray-200 text-xs font-semibold text-slate-700 hover:bg-gray-50 flex items-center gap-1.5 shadow-2xs transition-all">
            <i class="ph-bold ph-arrows-clockwise text-sm text-gray-500"></i>
            <span>Refresh</span>
        </a>
        <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
            <a href="orders.php?action=create" class="px-4 py-2 rounded-lg bg-[#C81E26] hover:bg-[#A8141B] text-white text-xs font-semibold flex items-center gap-1.5 shadow-sm transition-all whitespace-nowrap">
                <i class="ph-bold ph-plus text-sm"></i>
                <span>+ Buat Work Order Baru</span>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Structured Operational Metric Cards (High Data Density) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    
    <!-- Card 1: Total Orders -->
    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-2xs">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Total Work Order</span>
            <span class="w-8 h-8 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center text-base">
                <i class="ph-bold ph-clipboard-text"></i>
            </span>
        </div>
        <div class="flex items-baseline gap-2">
            <h3 class="text-2xl font-black text-slate-900"><?= $totalOrders ?></h3>
            <span class="text-xs text-gray-400 font-medium">Berkas SPK</span>
        </div>
        <div class="mt-3 pt-2.5 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500 font-medium">
            <span>In-Lab: <strong class="text-slate-800"><?= $inLabCount ?></strong></span>
            <span>•</span>
            <span>On-Site: <strong class="text-slate-800"><?= $onSiteCount ?></strong></span>
        </div>
    </div>

    <!-- Card 2: Teknisi Pengerjaan -->
    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-2xs">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Sedang Dikalibrasi</span>
            <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-base">
                <i class="ph-bold ph-wrench"></i>
            </span>
        </div>
        <div class="flex items-baseline gap-2">
            <h3 class="text-2xl font-black text-blue-600"><?= $inProgressCount ?></h3>
            <span class="text-xs text-gray-400 font-medium">Alat di Lab/Site</span>
        </div>
        <div class="mt-3 pt-2.5 border-t border-gray-100 text-xs text-blue-600 font-medium flex items-center justify-between">
            <span>Tahap 2: Pengujian</span>
            <a href="worksheet.php" class="hover:underline">Buka Worksheet &rarr;</a>
        </div>
    </div>

    <!-- Card 3: Priority Bagian Sertifikat -->
    <div class="bg-white p-5 rounded-2xl border-2 border-red-200 bg-red-50/20 shadow-2xs">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold text-[#C81E26] uppercase tracking-wider">Siap Buat Sertifikat</span>
            <span class="w-8 h-8 rounded-lg bg-red-100 text-[#C81E26] flex items-center justify-center text-base">
                <i class="ph-bold ph-bell-ringing"></i>
            </span>
        </div>
        <div class="flex items-baseline gap-2">
            <h3 class="text-2xl font-black text-[#C81E26]"><?= $readyForCertCount ?></h3>
            <span class="text-xs text-red-500 font-medium">Data Masuk</span>
        </div>
        <div class="mt-3 pt-2.5 border-t border-red-100 text-xs font-bold text-[#C81E26] flex items-center justify-between">
            <span>Tahap 4: Bagian Sertifikat</span>
            <a href="certificates.php" class="underline">Buat No. Sertifikat &rarr;</a>
        </div>
    </div>

    <!-- Card 4: Sertifikat Terbit -->
    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-2xs">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Sertifikat Selesai</span>
            <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-base">
                <i class="ph-bold ph-certificate"></i>
            </span>
        </div>
        <div class="flex items-baseline gap-2">
            <h3 class="text-2xl font-black text-emerald-600"><?= $certifiedCount ?></h3>
            <span class="text-xs text-gray-400 font-medium">Dokumen Terbit</span>
        </div>
        <div class="mt-3 pt-2.5 border-t border-gray-100 text-xs text-emerald-600 font-medium flex items-center justify-between">
            <span>ISO/IEC 17025 KAN</span>
            <span class="font-mono text-[11px]">Status: Sah</span>
        </div>
    </div>

</div>


<!-- Fast Status Filter Tabs -->
<div class="flex items-center gap-2 overflow-x-auto pb-1 mb-4 text-xs">
    <a href="index.php?filter=all<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" class="px-3.5 py-2 rounded-xl font-bold transition-all whitespace-nowrap <?= $filter === 'all' ? 'bg-slate-900 text-white shadow-2xs' : 'bg-white border border-gray-200 text-slate-600 hover:bg-gray-50' ?>">
        Semua Instrumen (<?= $totalOrders ?>)
    </a>
    <a href="index.php?filter=pending_worksheet<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" class="px-3.5 py-2 rounded-xl font-bold transition-all whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'pending_worksheet' ? 'bg-blue-600 text-white shadow-2xs' : 'bg-white border border-gray-200 text-slate-600 hover:bg-gray-50' ?>">
        <span class="w-2 h-2 rounded-full <?= $filter === 'pending_worksheet' ? 'bg-white' : 'bg-blue-600' ?>"></span>
        Dalam Pengujian (<?= $inProgressCount ?>)
    </a>
    <a href="index.php?filter=ready_for_cert<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" class="px-3.5 py-2 rounded-xl font-bold transition-all whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'ready_for_cert' ? 'bg-[#C81E26] text-white shadow-2xs' : 'bg-white border border-gray-200 text-slate-600 hover:bg-gray-50' ?>">
        <span class="w-2 h-2 rounded-full <?= $filter === 'ready_for_cert' ? 'bg-white' : 'bg-[#C81E26]' ?>"></span>
        Menunggu Sertifikat (<?= $readyForCertCount ?>)
    </a>
    <a href="index.php?filter=certified<?= $scopeFilter ? '&scope=' . urlencode($scopeFilter) : '' ?>" class="px-3.5 py-2 rounded-xl font-bold transition-all whitespace-nowrap flex items-center gap-1.5 <?= $filter === 'certified' ? 'bg-emerald-600 text-white shadow-2xs' : 'bg-white border border-gray-200 text-slate-600 hover:bg-gray-50' ?>">
        <span class="w-2 h-2 rounded-full <?= $filter === 'certified' ? 'bg-white' : 'bg-emerald-600' ?>"></span>
        Sertifikat Terbit (<?= $certifiedCount ?>)
    </a>
</div>


<!-- 5. Full-Width Main Tracking Table (Structured & High Breathing Room) -->
<div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 sm:p-6 mb-6">
    
    <!-- Table Header Controls: Search & Scope Filter -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4 pb-4 border-b border-gray-100">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-base font-bold text-slate-900">Monitoring Berkas & Status Alat</h2>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700 font-mono">
                    <?= count($instruments) ?> Instrumen
                </span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">Daftar instrumen aktif dan posisi alur kerja dari SPK, worksheet teknisi, hingga sertifikat terbit</p>
        </div>

        <!-- Search Input -->
        <form action="index.php" method="GET" class="flex items-center gap-2 shrink-0">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <?php if ($scopeFilter): ?>
                <input type="hidden" name="scope" value="<?= htmlspecialchars($scopeFilter) ?>">
            <?php endif; ?>
            <div class="relative">
                <i class="ph-bold ph-magnifying-glass absolute left-3 top-2.5 text-gray-400 text-xs"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari alat / SN / customer..." class="bg-gray-50 border border-gray-300 rounded-lg pl-8 pr-3 py-1.5 text-xs text-slate-800 w-52 sm:w-64 focus:outline-none focus:border-[#C81E26]">
            </div>
            <?php if ($search || $scopeFilter): ?>
                <a href="index.php?filter=<?= htmlspecialchars($filter) ?>" class="text-xs text-gray-400 hover:text-gray-700 px-1 py-1" title="Reset Filter">&times; Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Scope Filter Pills -->
    <div class="flex items-center gap-1.5 overflow-x-auto pb-3 mb-3 text-xs">
        <span class="text-gray-400 text-[11px] font-semibold whitespace-nowrap mr-1">Lingkup KAN:</span>
        <a href="index.php?filter=<?= htmlspecialchars($filter) ?>" class="px-2.5 py-1 rounded-lg whitespace-nowrap font-medium transition-all <?= empty($scopeFilter) ? 'bg-slate-900 text-white font-bold shadow-2xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">Semua Ruang Lingkup</a>
        <?php foreach ($scopes as $code => $sc): ?>
            <a href="index.php?filter=<?= htmlspecialchars($filter) ?>&scope=<?= $code ?>" class="px-2.5 py-1 rounded-lg whitespace-nowrap font-medium transition-all <?= $scopeFilter === $code ? 'bg-[#C81E26] text-white font-bold shadow-2xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                [<?= $code ?>] <?= htmlspecialchars(explode(' ', $sc['name'])[0]) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Structured Data Table (Spacious & Clean) -->
    <div class="overflow-x-auto rounded-xl border border-gray-200">
        <table class="w-full text-left text-xs min-w-[980px]">
            <thead class="bg-gray-50 text-gray-600 font-semibold border-b border-gray-200 uppercase text-[10px] tracking-wider">
                <tr>
                    <th class="py-3 px-3.5 w-[210px]">No. Order & Pelanggan</th>
                    <th class="py-3 px-3.5 w-[240px]">Nama Alat & Spesifikasi</th>
                    <th class="py-3 px-2.5 w-[110px]">Ruang Lingkup</th>
                    <th class="py-3 px-2.5 w-[95px]">Lokasi</th>
                    <th class="py-3 px-3.5 w-[140px]">Teknisi</th>
                    <th class="py-3 px-3.5 w-[160px]">Status Alur</th>
                    <th class="py-3 px-3.5 w-[160px]">No. Sertifikat</th>
                    <th class="py-3 px-3.5 text-right w-[150px]">Aksi / Tindak Lanjut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($instruments)): ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-gray-400">
                            <i class="ph-bold ph-folder-open text-3xl mb-2 inline-block text-gray-300"></i>
                            <p>Tidak ada data alat yang sesuai kriteria pencarian atau filter yang dipilih.</p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($instruments as $inst): ?>
                    <?php 
                        $scInfo = $scopes[$inst['scope_code']] ?? ['name' => $inst['scope_code'], 'badge_class' => 'bg-gray-100 text-gray-700'];
                    ?>
                    <tr class="hover:bg-slate-50/90 transition-colors">
                        <!-- Order & Pelanggan -->
                        <td class="py-3 px-3.5 align-middle">
                            <span class="font-mono text-[11px] font-bold text-slate-900 bg-gray-100 px-2 py-0.5 rounded border border-gray-200">
                                <?= htmlspecialchars($inst['order_number']) ?>
                            </span>
                            <p class="font-semibold text-slate-800 mt-1 truncate max-w-[190px]" title="<?= htmlspecialchars($inst['customer_name']) ?>">
                                <?= htmlspecialchars($inst['customer_name']) ?>
                            </p>
                        </td>

                        <!-- Alat & Identifikasi -->
                        <td class="py-3 px-3.5 align-middle">
                            <p class="font-bold text-slate-900 text-xs"><?= htmlspecialchars($inst['instrument_name']) ?></p>
                            <p class="text-[11px] text-gray-500 mt-0.5"><?= htmlspecialchars($inst['brand']) ?> • <?= htmlspecialchars($inst['model_type']) ?></p>
                            <p class="font-mono text-[10px] text-gray-400 mt-0.5">SN: <?= htmlspecialchars($inst['serial_number']) ?></p>
                        </td>

                        <!-- Ruang Lingkup -->
                        <td class="py-3 px-2.5 align-middle">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border <?= $scInfo['badge_class'] ?> whitespace-nowrap">
                                [<?= htmlspecialchars($inst['scope_code']) ?>] <?= htmlspecialchars(explode(' ', $scInfo['name'])[0]) ?>
                            </span>
                        </td>

                        <!-- Lokasi Layanan -->
                        <td class="py-3 px-2.5 align-middle">
                            <?= renderLocationBadge($inst['service_type']) ?>
                        </td>

                        <!-- Teknisi Pelaksana -->
                        <td class="py-3 px-3.5 align-middle">
                            <div class="flex items-center gap-1.5 text-slate-700">
                                <i class="ph-bold ph-user text-gray-400 text-xs"></i>
                                <span class="font-medium whitespace-nowrap"><?= htmlspecialchars($inst['technician_name'] ?: '-') ?></span>
                            </div>
                        </td>

                        <!-- Status Alur Kerja -->
                        <td class="py-3 px-3.5 align-middle">
                            <?= renderStatusBadge($inst['instrument_status']) ?>
                        </td>

                        <!-- Nomor Sertifikat -->
                        <td class="py-3 px-3.5 align-middle">
                            <?php if ($inst['certificate_number']): ?>
                                <span class="font-mono text-[11px] font-bold text-[#C81E26] bg-red-50 px-2 py-0.5 rounded border border-red-200 whitespace-nowrap">
                                    <?= htmlspecialchars($inst['certificate_number']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-gray-400 italic text-[11px] whitespace-nowrap">Belum terbit</span>
                            <?php endif; ?>
                        </td>

                        <!-- Aksi / Action Button -->
                        <td class="py-3 px-3.5 text-right align-middle whitespace-nowrap">
                            <?php if ($inst['instrument_status'] === 'DATA_SUBMITTED'): ?>
                                <a href="certificates.php?instrument_id=<?= $inst['instrument_id'] ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-[#C81E26] hover:bg-[#A8141B] text-white shadow-2xs inline-flex items-center gap-1.5 whitespace-nowrap transition-all">
                                    <i class="ph-bold ph-plus-circle"></i>
                                    <span>Buat No. Sertifikat</span>
                                </a>
                            <?php elseif ($inst['instrument_status'] === 'CERTIFIED' && $inst['certificate_number']): ?>
                                <a href="print_certificate.php?cert=<?= urlencode($inst['certificate_number']) ?>" target="_blank" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-gray-300 text-slate-700 hover:bg-gray-50 shadow-2xs inline-flex items-center gap-1.5 whitespace-nowrap transition-all">
                                    <i class="ph-bold ph-printer text-[#C81E26]"></i>
                                    <span>Cetak A4</span>
                                </a>
                            <?php else: ?>
                                <a href="worksheet.php?instrument_id=<?= $inst['instrument_id'] ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 hover:bg-gray-200 text-slate-700 inline-flex items-center gap-1.5 whitespace-nowrap transition-all">
                                    <i class="ph-bold ph-pencil-simple"></i>
                                    <span>Worksheet</span>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Footer of Table -->
    <div class="mt-4 pt-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-gray-500">
        <span>Menampilkan <strong><?= count($instruments) ?></strong> data instrumen kalibrasi</span>
        <span class="font-mono text-[11px]">Sistem Kalibrasi Terintegrasi ISO/IEC 17025:2017</span>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
