<?php
/**
 * Modul Sales & Work Order Kalibrasi
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Structured Enterprise Operational Module
 */

declare(strict_types=1);

$pageTitle = '1. Sales Order Kalibrasi';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$db = getDbConnection();
$scopes = getScopeList();

// Ambil daftar akun teknisi resmi yang terdaftar di database sistem (role = 'TECHNICIAN')
$technicians = $db->query("
    SELECT full_name, department, username 
    FROM users 
    WHERE role = 'TECHNICIAN' 
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. Simpan Order Baru (Create)
    if ($_POST['action'] === 'save_order') {
        requireRole(['SUPER_ADMIN', 'SALES'], 'orders.php');
        try {
            $customerName = trim($_POST['customer_name'] ?? '');
            $customerAddress = trim($_POST['customer_address'] ?? '');
            $customerContact = trim($_POST['customer_contact'] ?? '');
            $serviceType = in_array($_POST['service_type'] ?? '', ['IN_LAB', 'ON_SITE']) ? $_POST['service_type'] : 'IN_LAB';
            $orderDate = $_POST['order_date'] ?? date('Y-m-d');
            $isKan = isset($_POST['is_kan']) ? (int)$_POST['is_kan'] : 1;

            $instName = trim($_POST['instrument_name'] ?? '');
            $scopeCode = strtoupper(trim($_POST['scope_code'] ?? 'P'));
            if ($scopeCode === 'S') {
                $scopeCode = 'T'; // Normalize legacy Suhu code
            }
            $brand = trim($_POST['brand'] ?? '');
            $modelType = trim($_POST['model_type'] ?? '');
            $serialNumber = trim($_POST['serial_number'] ?? '');
            $capacityRange = trim($_POST['capacity_range'] ?? '');
            $resolution = trim($_POST['resolution'] ?? '');
            $technicianName = trim($_POST['technician_name'] ?? '');

            if (!$customerName || !$instName || !$serialNumber) {
                throw new Exception('Mohon lengkapi Nama Pelanggan, Nama Alat, dan Nomor Seri Alat.');
            }

            $yearMonth = date('ym', strtotime($orderDate));
            $stmtLast = $db->prepare("SELECT order_number FROM orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1");
            $stmtLast->execute(["ORD-{$yearMonth}-%"]);
            $lastOrder = $stmtLast->fetchColumn();
            $nextSeqInt = 1;
            if ($lastOrder && preg_match('/ORD-\d{4}-(\d{3})/', (string)$lastOrder, $m)) {
                $nextSeqInt = (int)$m[1] + 1;
            }
            $nextOrderSeq = str_pad((string)$nextSeqInt, 3, '0', STR_PAD_LEFT);
            $orderNumber = "ORD-{$yearMonth}-{$nextOrderSeq}";

            $db->beginTransaction();

            $stmtOrder = $db->prepare("
                INSERT INTO orders (order_number, customer_name, customer_address, customer_contact, order_date, service_type, status)
                VALUES (?, ?, ?, ?, ?, ?, 'PENDING')
            ");
            $stmtOrder->execute([$orderNumber, $customerName, $customerAddress, $customerContact, $orderDate, $serviceType]);
            $orderId = (int)$db->lastInsertId();

            $stmtInst = $db->prepare("
                INSERT INTO instruments (order_id, name, scope_code, is_kan, brand, model_type, serial_number, capacity_range, resolution, technician_name, calibration_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ASSIGNED')
            ");
            $stmtInst->execute([$orderId, $instName, $scopeCode, $isKan, $brand, $modelType, $serialNumber, $capacityRange, $resolution, $technicianName, $orderDate]);

            $db->commit();

            setFlash('success', "Work Order baru <strong>{$orderNumber}</strong> berhasil dibuat dan ditugaskan ke teknisi {$technicianName}!");
            header('Location: orders.php');
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', $e->getMessage());
        }
    }

    // 2. Update / Edit Order & Instrumen
    if ($_POST['action'] === 'update_order') {
        requireRole(['SUPER_ADMIN', 'SALES'], 'orders.php');
        try {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $instrumentId = (int)($_POST['instrument_id'] ?? 0);

            $customerName = trim($_POST['customer_name'] ?? '');
            $customerAddress = trim($_POST['customer_address'] ?? '');
            $customerContact = trim($_POST['customer_contact'] ?? '');
            $serviceType = in_array($_POST['service_type'] ?? '', ['IN_LAB', 'ON_SITE']) ? $_POST['service_type'] : 'IN_LAB';
            $orderDate = $_POST['order_date'] ?? date('Y-m-d');

            $instName = trim($_POST['instrument_name'] ?? '');
            $scopeCode = strtoupper(trim($_POST['scope_code'] ?? 'P'));
            if ($scopeCode === 'S') {
                $scopeCode = 'T';
            }
            $isKan = isset($_POST['is_kan']) ? (int)$_POST['is_kan'] : 1;
            $brand = trim($_POST['brand'] ?? '');
            $modelType = trim($_POST['model_type'] ?? '');
            $serialNumber = trim($_POST['serial_number'] ?? '');
            $capacityRange = trim($_POST['capacity_range'] ?? '');
            $resolution = trim($_POST['resolution'] ?? '');
            $technicianName = trim($_POST['technician_name'] ?? '');

            if ($orderId <= 0 || !$customerName || !$instName || !$serialNumber) {
                throw new Exception('Mohon lengkapi Nama Pelanggan, Nama Alat, dan Nomor Seri Alat.');
            }

            // Pastikan data order ada
            $stmtOrder = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmtOrder->execute([$orderId]);
            $existingOrder = $stmtOrder->fetch();
            if (!$existingOrder) {
                throw new Exception('Data SPK tidak ditemukan.');
            }

            // Cari ID instrumen jika belum ada
            if ($instrumentId <= 0) {
                $stmtFindInst = $db->prepare("SELECT id FROM instruments WHERE order_id = ? LIMIT 1");
                $stmtFindInst->execute([$orderId]);
                $instrumentId = (int)$stmtFindInst->fetchColumn();
            }

            // Ambil status sertifikasi instrumen
            $stmtInst = $db->prepare("
                SELECT i.*, c.certificate_number 
                FROM instruments i 
                LEFT JOIN certificates c ON c.instrument_id = i.id 
                WHERE i.id = ?
            ");
            $stmtInst->execute([$instrumentId]);
            $existingInst = $stmtInst->fetch();

            if (!$existingInst) {
                throw new Exception('Data Instrumen untuk SPK ini tidak ditemukan.');
            }

            $isCertified = ($existingInst['status'] === 'CERTIFIED' || !empty($existingInst['certificate_number']));

            // Bila sudah bersertifikat, jaga nomor sertifikat agar tetap konsisten (tidak boleh ubah scope / kan tanpa revisi resmi)
            $finalScopeCode = $isCertified ? $existingInst['scope_code'] : $scopeCode;
            $finalIsKan = $isCertified ? (int)$existingInst['is_kan'] : $isKan;

            $db->beginTransaction();

            // Update tabel orders
            $stmtUpdateOrder = $db->prepare("
                UPDATE orders 
                SET customer_name = ?, customer_address = ?, customer_contact = ?, service_type = ?, order_date = ?
                WHERE id = ?
            ");
            $stmtUpdateOrder->execute([$customerName, $customerAddress, $customerContact, $serviceType, $orderDate, $orderId]);

            // Update tabel instruments
            $stmtUpdateInst = $db->prepare("
                UPDATE instruments 
                SET name = ?, scope_code = ?, is_kan = ?, brand = ?, model_type = ?, serial_number = ?, 
                    capacity_range = ?, resolution = ?, technician_name = ?, calibration_date = ?
                WHERE id = ?
            ");
            $stmtUpdateInst->execute([
                $instName, $finalScopeCode, $finalIsKan, $brand, $modelType, $serialNumber,
                $capacityRange, $resolution, $technicianName, $orderDate, $instrumentId
            ]);

            $db->commit();

            $certNotice = $isCertified ? ' (Ruang lingkup & akreditasi KAN dipertahankan karena sertifikat resmi sudah terbit)' : '';
            setFlash('success', "Data SPK <strong>{$existingOrder['order_number']}</strong> ({$instName}) berhasil diperbarui!{$certNotice}");
            header('Location: orders.php');
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', 'Gagal memperbarui SPK: ' . $e->getMessage());
        }
    }

    // 3. Batalkan & Hapus Order (Delete)
    if ($_POST['action'] === 'delete_order') {
        requireRole(['SUPER_ADMIN', 'SALES'], 'orders.php');
        try {
            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                throw new Exception('ID SPK tidak valid.');
            }

            $stmtOrder = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmtOrder->execute([$orderId]);
            $order = $stmtOrder->fetch();
            if (!$order) {
                throw new Exception('Data SPK tidak ditemukan.');
            }

            // Pastikan belum ada sertifikat terbit
            $stmtCheckCert = $db->prepare("
                SELECT COUNT(*) 
                FROM instruments i 
                LEFT JOIN certificates c ON c.instrument_id = i.id 
                WHERE i.order_id = ? AND (i.status = 'CERTIFIED' OR c.id IS NOT NULL)
            ");
            $stmtCheckCert->execute([$orderId]);
            if ((int)$stmtCheckCert->fetchColumn() > 0) {
                throw new Exception('SPK ini tidak dapat dihapus karena sertifikat resmi sudah diterbitkan.');
            }

            $db->beginTransaction();
            $db->exec("DELETE FROM worksheets WHERE instrument_id IN (SELECT id FROM instruments WHERE order_id = {$orderId})");
            $db->exec("DELETE FROM instruments WHERE order_id = {$orderId}");
            $db->exec("DELETE FROM orders WHERE id = {$orderId}");
            $db->commit();

            setFlash('success', "SPK <strong>{$order['order_number']}</strong> ({$order['customer_name']}) berhasil dibatalkan dan dihapus.");
            header('Location: orders.php');
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', 'Gagal menghapus SPK: ' . $e->getMessage());
        }
    }
}

// Fetch all orders with primary instrument and certificate details
$orders = $db->query("
    SELECT 
        o.*,
        i.id as instrument_id,
        i.name as instrument_name,
        i.scope_code,
        COALESCE(i.is_kan, 1) as instrument_is_kan,
        i.brand,
        i.model_type,
        i.serial_number,
        i.capacity_range,
        i.resolution,
        i.technician_name,
        i.calibration_date,
        i.status as instrument_status,
        c.id as certificate_id,
        c.certificate_number,
        COUNT(i.id) as total_instruments,
        GROUP_CONCAT(i.name, '||') as instrument_names,
        GROUP_CONCAT(i.scope_code, '||') as scope_codes,
        GROUP_CONCAT(COALESCE(i.is_kan, 1), '||') as is_kan_list
    FROM orders o
    LEFT JOIN instruments i ON i.order_id = o.id
    LEFT JOIN certificates c ON c.instrument_id = i.id
    GROUP BY o.id
    ORDER BY o.id DESC
")->fetchAll();

// Cek apakah ada request auto-open modal edit dari URL (misal dari dashboard index.php)
$autoEditOrderNumber = trim($_GET['edit_order'] ?? '');
$autoEditData = null;
if ($autoEditOrderNumber !== '') {
    foreach ($orders as $ord) {
        if ($ord['order_number'] === $autoEditOrderNumber) {
            $autoEditData = [
                'order_id' => (int)$ord['id'],
                'order_number' => $ord['order_number'],
                'order_date' => $ord['order_date'],
                'customer_name' => $ord['customer_name'],
                'customer_address' => $ord['customer_address'] ?? '',
                'customer_contact' => $ord['customer_contact'] ?? '',
                'service_type' => $ord['service_type'],
                'order_status' => $ord['status'],

                'instrument_id' => (int)($ord['instrument_id'] ?? 0),
                'instrument_name' => $ord['instrument_name'] ?? '',
                'scope_code' => $ord['scope_code'] ?? 'P',
                'is_kan' => (int)($ord['instrument_is_kan'] ?? 1),
                'brand' => $ord['brand'] ?? '',
                'model_type' => $ord['model_type'] ?? '',
                'serial_number' => $ord['serial_number'] ?? '',
                'capacity_range' => $ord['capacity_range'] ?? '',
                'resolution' => $ord['resolution'] ?? '',
                'technician_name' => $ord['technician_name'] ?? '',
                'instrument_status' => $ord['instrument_status'] ?? '',
                'certificate_number' => $ord['certificate_number'] ?? '',
                'is_certified' => ($ord['instrument_status'] === 'CERTIFIED' || !empty($ord['certificate_number']))
            ];
            break;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header Control Bar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-xl font-bold text-slate-900 tracking-tight">Surat Perintah Kerja (SPK)</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            Penerimaan permintaan kalibrasi pelanggan, pendaftaran spesifikasi instrumen, dan penugasan teknisi.
        </p>
    </div>
    <div>
        <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
            <button onclick="openModal('create-order-modal')" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-3.5 py-1.5 rounded-lg text-xs font-semibold inline-flex items-center gap-1.5 shadow-subtle transition-colors">
                <i class="ph-bold ph-plus text-xs"></i>
                <span>+ Buat SPK Baru</span>
            </button>
        <?php else: ?>
            <span class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-500 font-medium text-xs inline-flex items-center gap-1.5 border border-slate-200">
                <i class="ph-bold ph-lock-key text-slate-400"></i>
                <span>Mode Tinjau (Divisi Sales)</span>
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Orders Table Card -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-subtle overflow-hidden">
    
    <!-- Table Controls / Header -->
    <div class="p-3.5 sm:p-4 border-b border-slate-200/80 bg-slate-50/50 flex items-center justify-between">
        <h2 class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Daftar Berkas SPK Aktif</h2>
        <span class="text-xs text-slate-500 font-medium"><?= count($orders) ?> berkas terdaftar</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs min-w-[1180px]">
            <thead class="bg-slate-50/75 text-slate-500 font-semibold border-b border-slate-200 uppercase text-[10px] tracking-wider whitespace-nowrap">
                <tr>
                    <th class="py-3 px-4 w-[180px]">No. Order & Tanggal</th>
                    <th class="py-3 px-4 w-[230px]">Pelanggan & Kontak</th>
                    <th class="py-3 px-3 w-[110px]">Layanan</th>
                    <th class="py-3 px-4 w-[220px]">Instrumen / Alat</th>
                    <th class="py-3 px-3 w-[140px]">Ruang Lingkup</th>
                    <th class="py-3 px-4 w-[140px]">Status</th>
                    <th class="py-3 px-4 text-right w-[160px]">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="7" class="py-10 text-center text-slate-400 text-xs">Belum ada order kalibrasi yang terdaftar.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($orders as $o): 
                    $editData = [
                        'order_id' => (int)$o['id'],
                        'order_number' => $o['order_number'],
                        'order_date' => $o['order_date'],
                        'customer_name' => $o['customer_name'],
                        'customer_address' => $o['customer_address'] ?? '',
                        'customer_contact' => $o['customer_contact'] ?? '',
                        'service_type' => $o['service_type'],
                        'order_status' => $o['status'],

                        'instrument_id' => (int)($o['instrument_id'] ?? 0),
                        'instrument_name' => $o['instrument_name'] ?? '',
                        'scope_code' => $o['scope_code'] ?? 'P',
                        'is_kan' => (int)($o['instrument_is_kan'] ?? 1),
                        'brand' => $o['brand'] ?? '',
                        'model_type' => $o['model_type'] ?? '',
                        'serial_number' => $o['serial_number'] ?? '',
                        'capacity_range' => $o['capacity_range'] ?? '',
                        'resolution' => $o['resolution'] ?? '',
                        'technician_name' => $o['technician_name'] ?? '',
                        'instrument_status' => $o['instrument_status'] ?? '',
                        'certificate_number' => $o['certificate_number'] ?? '',
                        'is_certified' => ($o['instrument_status'] === 'CERTIFIED' || !empty($o['certificate_number']))
                    ];
                ?>
                    <tr class="hover:bg-slate-50/60 transition-colors">
                        
                        <!-- No. Order & Tanggal -->
                        <td class="py-3.5 px-4 whitespace-nowrap align-middle">
                            <span class="font-mono text-xs font-semibold text-slate-900 tracking-tight">
                                <?= htmlspecialchars($o['order_number']) ?>
                            </span>
                            <p class="text-[11px] text-slate-400 font-medium mt-0.5 whitespace-nowrap flex items-center gap-1">
                                <i class="ph-bold ph-calendar-blank text-slate-400 text-xs"></i>
                                <span><?= formatIndonesianDate($o['order_date']) ?></span>
                            </p>
                        </td>

                        <!-- Pelanggan & Kontak -->
                        <td class="py-3.5 px-4 align-middle">
                            <p class="font-semibold text-slate-900 text-xs truncate max-w-[210px]" title="<?= htmlspecialchars($o['customer_name']) ?>">
                                <?= htmlspecialchars($o['customer_name']) ?>
                            </p>
                            <p class="text-[11px] text-slate-500 mt-0.5 truncate max-w-[210px]" title="<?= htmlspecialchars($o['customer_address']) ?>">
                                <?= htmlspecialchars($o['customer_address'] ?: '-') ?>
                            </p>
                            <p class="text-[10px] text-slate-400 font-mono mt-0.5">
                                <?= htmlspecialchars($o['customer_contact'] ?: '-') ?>
                            </p>
                        </td>

                        <!-- Layanan -->
                        <td class="py-3.5 px-3 whitespace-nowrap align-middle">
                            <?= renderLocationBadge($o['service_type']) ?>
                        </td>

                        <!-- Daftar Alat -->
                        <td class="py-3.5 px-4 align-middle">
                            <?php 
                                $instNames = explode('||', (string)$o['instrument_names']);
                                $kanList = explode('||', (string)($o['is_kan_list'] ?? ''));
                                foreach (array_filter($instNames) as $idx => $name): 
                                    $isItemKan = ($kanList[$idx] ?? '1') === '1';
                            ?>
                                <div class="text-xs text-slate-800 font-medium flex items-center gap-1.5 py-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-slate-400 shrink-0"></span>
                                    <span class="truncate max-w-[150px]" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></span>
                                    <span class="text-[10px] <?= $isItemKan ? 'text-slate-400' : 'text-amber-700 font-semibold' ?>">
                                        (<?= $isItemKan ? 'KAN' : 'Non-KAN' ?>)
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </td>

                        <!-- Ruang Lingkup -->
                        <td class="py-3.5 px-3 whitespace-nowrap align-middle">
                            <?php 
                                $scCodes = array_unique(array_filter(explode('||', (string)$o['scope_codes'])));
                                foreach ($scCodes as $sc):
                                    $scInfo = $scopes[$sc] ?? ['name' => $sc];
                            ?>
                                <span class="font-mono text-xs text-slate-800 font-medium block">
                                    [<?= htmlspecialchars($sc) ?>] <?= htmlspecialchars(explode(' ', $scInfo['name'])[0]) ?>
                                </span>
                            <?php endforeach; ?>
                        </td>

                        <!-- Status Order -->
                        <td class="py-3.5 px-4 whitespace-nowrap align-middle">
                            <?= renderStatusBadge($o['status']) ?>
                        </td>

                        <!-- Aksi -->
                        <td class="py-3.5 px-4 text-right whitespace-nowrap align-middle">
                            <div class="inline-flex items-center justify-end gap-1.5">
                                <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
                                    <button type="button" 
                                            onclick='openEditOrderModal(<?= json_encode($editData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
                                            class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 inline-flex items-center gap-1 transition-colors shadow-subtle"
                                            title="Edit SPK & Data Instrumen">
                                        <i class="ph-bold ph-pencil-simple text-slate-400"></i>
                                        <span>Edit</span>
                                    </button>
                                    <?php if ($o['status'] !== 'COMPLETED' && ($o['instrument_status'] ?? '') !== 'CERTIFIED'): ?>
                                        <form action="orders.php" method="POST" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan & menghapus SPK <?= htmlspecialchars($o['order_number']) ?>?');">
                                            <input type="hidden" name="action" value="delete_order">
                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                            <button type="submit" class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Hapus SPK">
                                                <i class="ph-bold ph-trash text-sm"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if (hasRole(['SUPER_ADMIN', 'TECHNICIAN'])): ?>
                                    <a href="worksheet.php?order_id=<?= $o['id'] ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-slate-900 hover:bg-slate-800 text-white inline-flex items-center gap-1 transition-colors shadow-subtle">
                                        <span>Worksheet</span>
                                    </a>
                                <?php else: ?>
                                    <a href="index.php?search=<?= urlencode($o['order_number']) ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 inline-flex items-center gap-1 transition-colors shadow-subtle">
                                        <span>Alur &rarr;</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Table Footer Summary -->
    <div class="p-3.5 border-t border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-slate-500">
        <span>Menampilkan <strong><?= count($orders) ?></strong> berkas SPK kalibrasi</span>
        <span class="font-mono text-[11px] text-slate-400">Sistem Kalibrasi Terintegrasi ISO/IEC 17025:2017</span>
    </div>

</div>

<!-- Modal: Tambah Order Baru -->
<div id="create-order-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl border border-slate-200 w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6 shadow-xl text-xs">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-5">
            <div>
                <h3 class="text-base font-bold text-slate-900 tracking-tight">Input Work Order Kalibrasi Baru</h3>
                <p class="text-slate-500 text-[11px] mt-0.5">Registrasi data order pelanggan, spesifikasi alat, dan penugasan teknisi.</p>
            </div>
            <button type="button" onclick="closeModal('create-order-modal')" class="text-slate-400 hover:text-slate-700 p-1 rounded-md transition-colors">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <form id="create-order-form" action="orders.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="save_order">

            <!-- Quick Sample Autofill Strip -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-slate-50 px-3 py-2 rounded-lg border border-slate-200">
                <span class="text-[11px] font-medium text-slate-600 flex items-center gap-1.5">
                    <i class="ph-bold ph-lightning text-slate-400"></i>
                    Contoh Standar:
                </span>
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button type="button" onclick="fillSampleOrder('pressure')" class="px-2 py-1 rounded bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 font-medium text-[11px] transition-colors">
                        [P] Tekanan
                    </button>
                    <button type="button" onclick="fillSampleOrder('temperature')" class="px-2 py-1 rounded bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 font-medium text-[11px] transition-colors">
                        [T] Suhu
                    </button>
                    <button type="button" onclick="fillSampleOrder('mass')" class="px-2 py-1 rounded bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 font-medium text-[11px] transition-colors">
                        [M] Massa
                    </button>
                    <button type="button" onclick="fillSampleOrder('dimension')" class="px-2 py-1 rounded bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 font-medium text-[11px] transition-colors">
                        [D] Dimensi
                    </button>
                    <button type="button" onclick="fillSampleOrder('electric_nonkan')" class="px-2 py-1 rounded bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 font-medium text-[11px] transition-colors">
                        [E] Listrik (Non-KAN)
                    </button>
                </div>
            </div>

            <!-- Section 1: Customer Info -->
            <div class="space-y-3 pt-1">
                <div class="flex items-center gap-2 border-b border-slate-100 pb-2">
                    <span class="text-[11px] font-semibold text-slate-900 uppercase tracking-wider">A. Data Pelanggan</span>
                </div>
                
                <div>
                    <label class="block font-medium text-slate-700 mb-1">Nama Perusahaan / Pelanggan <span class="text-rose-500">*</span></label>
                    <input type="text" name="customer_name" required placeholder="Contoh: PT Astra Otoparts Tbk" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Tanggal SPK <span class="text-rose-500">*</span></label>
                        <input type="date" name="order_date" value="<?= date('Y-m-d') ?>" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Kontak Person / PIC</label>
                        <input type="text" name="customer_contact" placeholder="Contoh: Bpk. Bambang (0812-xxxx)" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Lokasi Layanan <span class="text-rose-500">*</span></label>
                        <select name="service_type" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                            <option value="IN_LAB">In-Lab (Laboratorium PT Kalpindo)</option>
                            <option value="ON_SITE">On-Site (Pabrik / Lokasi Klien)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-medium text-slate-700 mb-1">Alamat Pabrik / Site</label>
                    <textarea name="customer_address" rows="2" placeholder="Contoh: Kawasan Industri KIIC, Karawang Barat" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors"></textarea>
                </div>
            </div>

            <!-- Section 2: Instrument Info & Assignment -->
            <div class="space-y-3 pt-2">
                <div class="flex items-center gap-2 border-b border-slate-100 pb-2">
                    <span class="text-[11px] font-semibold text-slate-900 uppercase tracking-wider">B. Spesifikasi Instrumen & Penugasan</span>
                </div>
                
                <div>
                    <label class="block font-medium text-slate-700 mb-1">Nama Alat <span class="text-rose-500">*</span></label>
                    <input type="text" name="instrument_name" required placeholder="Contoh: Digital Pressure Gauge" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Ruang Lingkup (Scope) <span class="text-rose-500">*</span></label>
                        <select name="scope_code" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-slate-800 transition-colors">
                            <?php foreach ($scopes as $code => $sc): ?>
                                <option value="<?= $code ?>">[<?= $code ?>] <?= htmlspecialchars($sc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Status Akreditasi <span class="text-rose-500">*</span></label>
                        <select name="is_kan" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:outline-none focus:border-slate-800 transition-colors">
                            <option value="1">Akreditasi KAN (Standar ISO/IEC 17025)</option>
                            <option value="0">Non-KAN (Kalibrasi Tertelusur / Awalan N)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Merk / Brand</label>
                        <input type="text" name="brand" placeholder="Contoh: Fluke / Mitutoyo" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Model / Tipe</label>
                        <input type="text" name="model_type" placeholder="Contoh: 700G07" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Nomor Seri (SN) <span class="text-rose-500">*</span></label>
                        <input type="text" name="serial_number" required placeholder="Contoh: SN-829104" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Rentang Ukur (Range)</label>
                        <input type="text" name="capacity_range" placeholder="Contoh: 0 - 25 bar / 0 - 150 mm" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Resolusi</label>
                        <input type="text" name="resolution" placeholder="Contoh: 0.001 bar / 0.01 mm" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                </div>

                <div>
                    <label class="block font-medium text-slate-700 mb-1">Teknisi Penanggung Jawab <span class="text-rose-500">*</span></label>
                    <select name="technician_name" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                        <?php if (empty($technicians)): ?>
                            <option value="">-- Belum ada akun teknisi terdaftar di sistem --</option>
                        <?php else: ?>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= htmlspecialchars($t['full_name']) ?>">
                                    <?= htmlspecialchars($t['full_name']) ?> (<?= htmlspecialchars($t['department'] ?: 'Operasional Laboratorium') ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">Daftar staf teknisi laboratorium yang terdaftar di sistem.</p>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModal('create-order-modal')" class="px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">Batal</button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-4 py-2 rounded-lg font-semibold shadow-subtle transition-colors">
                    Simpan & Terbitkan SPK
                </button>
            </div>
        </form>

    </div>
</div>

<!-- Modal: Edit Surat Perintah Kerja (SPK) -->
<div id="edit-order-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl border border-slate-200 w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6 shadow-xl text-xs">
        
        <div class="flex items-center justify-between border-b border-slate-100 pb-3 mb-5">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-bold text-slate-900 tracking-tight">Edit Surat Perintah Kerja (SPK)</h3>
                    <span id="edit-order-badge" class="font-mono text-xs font-semibold text-slate-700 bg-slate-100 px-2 py-0.5 rounded border border-slate-200"></span>
                </div>
                <p class="text-slate-500 text-[11px] mt-0.5">Perbarui data pelanggan, informasi alat, atau penugasan teknisi.</p>
            </div>
            <button type="button" onclick="closeModal('edit-order-modal')" class="text-slate-400 hover:text-slate-700 p-1 rounded-md transition-colors">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <!-- Notice jika sertifikat sudah terbit -->
        <div id="edit-certified-warning" class="hidden bg-slate-50 border border-slate-200 text-slate-700 p-3 rounded-lg text-xs flex items-start gap-2.5 mb-4">
            <i class="ph-bold ph-info text-base text-slate-600 shrink-0 mt-0.5"></i>
            <div>
                <p class="font-semibold text-slate-900">Sertifikat Terbit: <span id="edit-cert-no" class="font-mono"></span></p>
                <p class="text-[11px] text-slate-500 mt-0.5">Sesuai standar ISO/IEC 17025, Ruang Lingkup dan Status KAN dikunci karena nomor sertifikat telah diterbitkan. Data pelanggan dan spesifikasi fisik alat tetap dapat diperbarui.</p>
            </div>
        </div>

        <form id="edit-order-form" action="orders.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="update_order">
            <input type="hidden" name="order_id" id="edit-order-id">
            <input type="hidden" name="instrument_id" id="edit-instrument-id">

            <!-- Section 1 -->
            <div class="space-y-3 pt-1">
                <div class="flex items-center gap-2 border-b border-slate-100 pb-2">
                    <span class="text-[11px] font-semibold text-slate-900 uppercase tracking-wider">A. Data Pelanggan</span>
                </div>
                
                <div>
                    <label class="block font-medium text-slate-700 mb-1">Nama Perusahaan / Pelanggan <span class="text-rose-500">*</span></label>
                    <input type="text" name="customer_name" id="edit-customer-name" required placeholder="Contoh: PT Astra Otoparts Tbk" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Tanggal SPK <span class="text-rose-500">*</span></label>
                        <input type="date" name="order_date" id="edit-order-date" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Kontak Person / PIC</label>
                        <input type="text" name="customer_contact" id="edit-customer-contact" placeholder="Contoh: Bpk. Bambang (0812-xxxx)" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Lokasi Layanan <span class="text-rose-500">*</span></label>
                        <select name="service_type" id="edit-service-type" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                            <option value="IN_LAB">In-Lab (Laboratorium PT Kalpindo)</option>
                            <option value="ON_SITE">On-Site (Pabrik / Lokasi Klien)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-medium text-slate-700 mb-1">Alamat Pabrik / Site</label>
                    <textarea name="customer_address" id="edit-customer-address" rows="2" placeholder="Contoh: Kawasan Industri KIIC, Karawang Barat" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors"></textarea>
                </div>
            </div>

            <!-- Section 2 -->
            <div class="space-y-3 pt-2">
                <div class="flex items-center gap-2 border-b border-slate-100 pb-2">
                    <span class="text-[11px] font-semibold text-slate-900 uppercase tracking-wider">B. Spesifikasi Instrumen & Penugasan</span>
                </div>
                
                <div>
                    <label class="block font-medium text-slate-700 mb-1">Nama Alat <span class="text-rose-500">*</span></label>
                    <input type="text" name="instrument_name" id="edit-instrument-name" required placeholder="Contoh: Digital Pressure Gauge" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Ruang Lingkup (Scope) <span class="text-rose-500">*</span></label>
                        <select name="scope_code" id="edit-scope-code" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-slate-800 transition-colors">
                            <?php foreach ($scopes as $code => $sc): ?>
                                <option value="<?= $code ?>">[<?= $code ?>] <?= htmlspecialchars($sc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Status Akreditasi <span class="text-rose-500">*</span></label>
                        <select name="is_kan" id="edit-is-kan" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-medium focus:outline-none focus:border-slate-800 transition-colors">
                            <option value="1">Akreditasi KAN (Standar ISO/IEC 17025)</option>
                            <option value="0">Non-KAN (Kalibrasi Tertelusur / Awalan N)</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Merk / Brand</label>
                        <input type="text" name="brand" id="edit-brand" placeholder="Contoh: Fluke / Mitutoyo" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Model / Tipe</label>
                        <input type="text" name="model_type" id="edit-model-type" placeholder="Contoh: 700G07" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Nomor Seri (SN) <span class="text-rose-500">*</span></label>
                        <input type="text" name="serial_number" id="edit-serial-number" required placeholder="Contoh: SN-829104" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Rentang Ukur (Range)</label>
                        <input type="text" name="capacity_range" id="edit-capacity-range" placeholder="Contoh: 0 - 25 bar / 0 - 150 mm" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                    <div>
                        <label class="block font-medium text-slate-700 mb-1">Resolusi</label>
                        <input type="text" name="resolution" id="edit-resolution" placeholder="Contoh: 0.001 bar / 0.01 mm" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                </div>

                <div>
                    <label class="block font-medium text-slate-700 mb-1">Teknisi Penanggung Jawab <span class="text-rose-500">*</span></label>
                    <select name="technician_name" id="edit-technician-name" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                        <?php if (empty($technicians)): ?>
                            <option value="">-- Belum ada akun teknisi terdaftar di sistem --</option>
                        <?php else: ?>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= htmlspecialchars($t['full_name']) ?>">
                                    <?= htmlspecialchars($t['full_name']) ?> (<?= htmlspecialchars($t['department'] ?: 'Operasional Laboratorium') ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">Daftar staf teknisi laboratorium yang terdaftar di sistem.</p>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModal('edit-order-modal')" class="px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">Batal</button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-4 py-2 rounded-lg font-semibold shadow-subtle inline-flex items-center gap-1.5 transition-colors">
                    <i class="ph-bold ph-floppy-disk"></i>
                    <span>Simpan Perubahan SPK</span>
                </button>
            </div>
        </form>

    </div>
</div>

<script>
function openEditOrderModal(data) {
    if (!data) return;
    
    document.getElementById('edit-order-id').value = data.order_id || '';
    document.getElementById('edit-instrument-id').value = data.instrument_id || '';
    document.getElementById('edit-order-badge').textContent = data.order_number || '';
    document.getElementById('edit-customer-name').value = data.customer_name || '';
    document.getElementById('edit-customer-contact').value = data.customer_contact || '';
    document.getElementById('edit-customer-address').value = data.customer_address || '';
    document.getElementById('edit-service-type').value = data.service_type || 'IN_LAB';
    document.getElementById('edit-order-date').value = data.order_date || '';

    document.getElementById('edit-instrument-name').value = data.instrument_name || '';
    document.getElementById('edit-scope-code').value = data.scope_code || 'P';
    document.getElementById('edit-is-kan').value = (data.is_kan === 0 || data.is_kan === '0') ? '0' : '1';
    document.getElementById('edit-brand').value = data.brand || '';
    document.getElementById('edit-model-type').value = data.model_type || '';
    document.getElementById('edit-serial-number').value = data.serial_number || '';
    document.getElementById('edit-capacity-range').value = data.capacity_range || '';
    document.getElementById('edit-resolution').value = data.resolution || '';
    
    const techSelect = document.getElementById('edit-technician-name');
    if (techSelect) {
        // Hapus opsi histori data lama yang mungkin ditambahkan sebelumnya
        const legacyOption = techSelect.querySelector('option[data-legacy="1"]');
        if (legacyOption) {
            legacyOption.remove();
        }

        if (data.technician_name) {
            let found = false;
            for (let i = 0; i < techSelect.options.length; i++) {
                if (techSelect.options[i].value === data.technician_name) {
                    techSelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
            // Jika teknisi pada data lama belum terdaftar di users, tampilkan sementara dengan penanda
            if (!found && data.technician_name.trim() !== '') {
                const legacyOpt = document.createElement('option');
                legacyOpt.value = data.technician_name;
                legacyOpt.textContent = data.technician_name + ' (Riwayat Penugasan Sebelum Ada Akun)';
                legacyOpt.setAttribute('data-legacy', '1');
                techSelect.appendChild(legacyOpt);
                techSelect.value = data.technician_name;
            }
        }
    }

    const warningBox = document.getElementById('edit-certified-warning');
    const certNoSpan = document.getElementById('edit-cert-no');
    const scopeSelect = document.getElementById('edit-scope-code');
    const kanSelect = document.getElementById('edit-is-kan');

    if (data.is_certified) {
        if (warningBox) warningBox.classList.remove('hidden');
        if (certNoSpan) certNoSpan.textContent = data.certificate_number || 'Sertifikat Terbit';
        if (scopeSelect) {
            scopeSelect.setAttribute('disabled', 'disabled');
            scopeSelect.classList.add('bg-gray-100', 'cursor-not-allowed', 'opacity-75');
        }
        if (kanSelect) {
            kanSelect.setAttribute('disabled', 'disabled');
            kanSelect.classList.add('bg-gray-100', 'cursor-not-allowed', 'opacity-75');
        }
    } else {
        if (warningBox) warningBox.classList.add('hidden');
        if (scopeSelect) {
            scopeSelect.removeAttribute('disabled');
            scopeSelect.classList.remove('bg-gray-100', 'cursor-not-allowed', 'opacity-75');
        }
        if (kanSelect) {
            kanSelect.removeAttribute('disabled');
            kanSelect.classList.remove('bg-gray-100', 'cursor-not-allowed', 'opacity-75');
        }
    }

    openModal('edit-order-modal');
}

function fillSampleOrder(type) {
    const form = document.getElementById('create-order-form');
    if (!form) return;

    // Ambil akun teknisi terdaftar pertama di dropdown
    const registeredTech = form.elements['technician_name']?.options[0]?.value || '';

    if (type === 'pressure') {
        form.elements['customer_name'].value = 'PT Pertamina Hulu Rokan';
        form.elements['customer_contact'].value = 'Bpk. Hendro (0812-7788-9900)';
        form.elements['service_type'].value = 'ON_SITE';
        form.elements['customer_address'].value = 'Kawasan Lapangan Duri, Riau';
        form.elements['instrument_name'].value = 'Pressure Gauge High Precision';
        form.elements['scope_code'].value = 'P';
        form.elements['is_kan'].value = '1';
        form.elements['brand'].value = 'WIKA Instrument';
        form.elements['model_type'].value = '232.50.100';
        form.elements['serial_number'].value = 'WK-' + Math.floor(100000 + Math.random() * 900000);
        form.elements['capacity_range'].value = '0 - 25 bar';
        form.elements['resolution'].value = '0.01 bar';
        if (registeredTech) form.elements['technician_name'].value = registeredTech;
    } else if (type === 'temperature') {
        form.elements['customer_name'].value = 'PT Kalbe Farma Tbk';
        form.elements['customer_contact'].value = 'Ibu Dewi (0813-1122-3344)';
        form.elements['service_type'].value = 'IN_LAB';
        form.elements['customer_address'].value = 'Delta Silicon II Cikarang';
        form.elements['instrument_name'].value = 'Digital Thermometer & RTD Probe';
        form.elements['scope_code'].value = 'T';
        form.elements['is_kan'].value = '1';
        form.elements['brand'].value = 'Fluke Calibration';
        form.elements['model_type'].value = 'Fluke 1523';
        form.elements['serial_number'].value = 'FLK-' + Math.floor(100000 + Math.random() * 900000);
        form.elements['capacity_range'].value = '-50 °C s/d 200 °C';
        form.elements['resolution'].value = '0.001 °C';
        if (registeredTech) form.elements['technician_name'].value = registeredTech;
    } else if (type === 'mass') {
        form.elements['customer_name'].value = 'PT Mayora Indah Tbk';
        form.elements['customer_contact'].value = 'Bpk. Agus (0815-5566-7788)';
        form.elements['service_type'].value = 'IN_LAB';
        form.elements['customer_address'].value = 'Jl. Telesonic Jatake, Tangerang';
        form.elements['instrument_name'].value = 'Precision Analytical Balance';
        form.elements['scope_code'].value = 'M';
        form.elements['is_kan'].value = '1';
        form.elements['brand'].value = 'Sartorius';
        form.elements['model_type'].value = 'Entris II';
        form.elements['serial_number'].value = 'SAR-' + Math.floor(100000 + Math.random() * 900000);
        form.elements['capacity_range'].value = '0 - 220 g';
        form.elements['resolution'].value = '0.0001 g';
        if (registeredTech) form.elements['technician_name'].value = registeredTech;
    } else if (type === 'dimension') {
        form.elements['customer_name'].value = 'PT Komatsu Indonesia';
        form.elements['customer_contact'].value = 'Bpk. Hermawan (0817-8899-0011)';
        form.elements['service_type'].value = 'IN_LAB';
        form.elements['customer_address'].value = 'Jl. Raya Bekasi KM. 22, Cakung';
        form.elements['instrument_name'].value = 'Digital Vernier Caliper 150mm';
        form.elements['scope_code'].value = 'D';
        form.elements['is_kan'].value = '1';
        form.elements['brand'].value = 'Mitutoyo';
        form.elements['model_type'].value = '500-196-30';
        form.elements['serial_number'].value = 'MTY-' + Math.floor(100000 + Math.random() * 900000);
        form.elements['capacity_range'].value = '0 - 150 mm';
        form.elements['resolution'].value = '0.01 mm';
        if (registeredTech) form.elements['technician_name'].value = registeredTech;
    } else if (type === 'electric_nonkan') {
        form.elements['customer_name'].value = 'PT Schneider Electric Manufacturing';
        form.elements['customer_contact'].value = 'Ibu Lisa (0819-2233-4455)';
        form.elements['service_type'].value = 'ON_SITE';
        form.elements['customer_address'].value = 'Batamindo Industrial Park, Batam';
        form.elements['instrument_name'].value = 'Digital True-RMS Multimeter';
        form.elements['scope_code'].value = 'E';
        form.elements['is_kan'].value = '0'; // Non-KAN
        form.elements['brand'].value = 'Fluke';
        form.elements['model_type'].value = 'Fluke 87V';
        form.elements['serial_number'].value = 'FLK-' + Math.floor(100000 + Math.random() * 900000);
        form.elements['capacity_range'].value = '0 - 1000 V AC/DC';
        form.elements['resolution'].value = '0.0001 V';
        if (registeredTech) form.elements['technician_name'].value = registeredTech;
    }
}

<?php if (!empty($autoEditData)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openEditOrderModal(<?= json_encode($autoEditData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
