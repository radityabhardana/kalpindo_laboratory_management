<?php
declare(strict_types=1);

$pageTitle = 'Sales Order (SPK) Kalibrasi';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

requireRole(['SUPER_ADMIN', 'SALES', 'CERT_ADMIN'], 'index.php');

$db = getDbConnection();
$scopes = getScopeList();
$technicians = $db->query("SELECT full_name, department, username FROM users WHERE role = 'TECHNICIAN' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
            if ($scopeCode === 'S') $scopeCode = 'T';
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
            $stmtLast = $db->prepare("SELECT order_number FROM orders WHERE order_number LIKE ?");
            $stmtLast->execute(["ORD-{$yearMonth}-%"]);
            $allOrdersThisMonth = $stmtLast->fetchAll(PDO::FETCH_COLUMN);
            $maxSeq = 0;
            foreach ($allOrdersThisMonth as $on) {
                if (preg_match('/ORD-\d{4}-(\d+)/', (string)$on, $m)) {
                    $seq = (int)$m[1];
                    if ($seq > $maxSeq) $maxSeq = $seq;
                }
            }
            $nextSeqInt = $maxSeq + 1;
            $orderNumber = "ORD-{$yearMonth}-" . str_pad((string)$nextSeqInt, 3, '0', STR_PAD_LEFT);

            $db->beginTransaction();
            $stmtOrder = $db->prepare("INSERT INTO orders (order_number, customer_name, customer_address, customer_contact, order_date, service_type, status) VALUES (?, ?, ?, ?, ?, ?, 'PENDING')");
            $stmtOrder->execute([$orderNumber, $customerName, $customerAddress, $customerContact, $orderDate, $serviceType]);
            $orderId = (int)$db->lastInsertId();

            $stmtInst = $db->prepare("INSERT INTO instruments (order_id, name, scope_code, is_kan, brand, model_type, serial_number, capacity_range, resolution, technician_name, calibration_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ASSIGNED')");
            $stmtInst->execute([$orderId, $instName, $scopeCode, $isKan, $brand, $modelType, $serialNumber, $capacityRange, $resolution, $technicianName, $orderDate]);
            $db->commit();

            $escOrder = htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8');
            $escTech = htmlspecialchars($technicianName, ENT_QUOTES, 'UTF-8');
            setFlash('success', "Work Order baru <strong>{$escOrder}</strong> berhasil dibuat dan ditugaskan ke teknisi {$escTech}!");
            header('Location: orders.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', $e->getMessage());
        }
    }

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
            if ($scopeCode === 'S') $scopeCode = 'T';
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

            $stmtOrder = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmtOrder->execute([$orderId]);
            $existingOrder = $stmtOrder->fetch();
            if (!$existingOrder) throw new Exception('Data SPK tidak ditemukan.');

            if ($instrumentId <= 0) {
                $stmtFindInst = $db->prepare("SELECT id FROM instruments WHERE order_id = ? LIMIT 1");
                $stmtFindInst->execute([$orderId]);
                $instrumentId = (int)$stmtFindInst->fetchColumn();
            }

            $stmtInst = $db->prepare("SELECT i.*, c.certificate_number FROM instruments i LEFT JOIN certificates c ON c.instrument_id = i.id WHERE i.id = ?");
            $stmtInst->execute([$instrumentId]);
            $existingInst = $stmtInst->fetch();
            if (!$existingInst) throw new Exception('Data Instrumen untuk SPK ini tidak ditemukan.');

            $isCertified = ($existingInst['status'] === 'CERTIFIED' || !empty($existingInst['certificate_number']));
            $finalScopeCode = $isCertified ? $existingInst['scope_code'] : $scopeCode;
            $finalIsKan = $isCertified ? (int)$existingInst['is_kan'] : $isKan;

            $db->beginTransaction();
            $stmtUpdateOrder = $db->prepare("UPDATE orders SET customer_name = ?, customer_address = ?, customer_contact = ?, service_type = ?, order_date = ? WHERE id = ?");
            $stmtUpdateOrder->execute([$customerName, $customerAddress, $customerContact, $serviceType, $orderDate, $orderId]);

            $stmtUpdateInst = $db->prepare("UPDATE instruments SET name = ?, scope_code = ?, is_kan = ?, brand = ?, model_type = ?, serial_number = ?, capacity_range = ?, resolution = ?, technician_name = ?, calibration_date = ? WHERE id = ?");
            $stmtUpdateInst->execute([$instName, $finalScopeCode, $finalIsKan, $brand, $modelType, $serialNumber, $capacityRange, $resolution, $technicianName, $orderDate, $instrumentId]);
            $db->commit();

            $certNotice = $isCertified ? ' (Ruang lingkup & akreditasi KAN dipertahankan karena sertifikat resmi sudah terbit)' : '';
            $escOrderNum = htmlspecialchars($existingOrder['order_number'], ENT_QUOTES, 'UTF-8');
            $escInstName = htmlspecialchars($instName, ENT_QUOTES, 'UTF-8');
            setFlash('success', "Data SPK <strong>{$escOrderNum}</strong> ({$escInstName}) berhasil diperbarui!{$certNotice}");
            header('Location: orders.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', 'Gagal memperbarui SPK: ' . $e->getMessage());
        }
    }

    if ($_POST['action'] === 'delete_order') {
        requireRole(['SUPER_ADMIN', 'SALES'], 'orders.php');
        try {
            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($orderId <= 0) throw new Exception('ID SPK tidak valid.');

            $stmtOrder = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmtOrder->execute([$orderId]);
            $order = $stmtOrder->fetch();
            if (!$order) throw new Exception('Data SPK tidak ditemukan.');

            $stmtCheckCert = $db->prepare("SELECT COUNT(*) FROM instruments i LEFT JOIN certificates c ON c.instrument_id = i.id WHERE i.order_id = ? AND (i.status = 'CERTIFIED' OR c.id IS NOT NULL)");
            $stmtCheckCert->execute([$orderId]);
            if ((int)$stmtCheckCert->fetchColumn() > 0) throw new Exception('SPK ini tidak dapat dihapus karena sertifikat resmi sudah diterbitkan.');

            $db->beginTransaction();
            $stmtDelWs = $db->prepare("DELETE FROM worksheets WHERE instrument_id IN (SELECT id FROM instruments WHERE order_id = ?)");
            $stmtDelWs->execute([$orderId]);
            $stmtDelInst = $db->prepare("DELETE FROM instruments WHERE order_id = ?");
            $stmtDelInst->execute([$orderId]);
            $stmtDelOrder = $db->prepare("DELETE FROM orders WHERE id = ?");
            $stmtDelOrder->execute([$orderId]);
            $db->commit();

            $escOrderNum = htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8');
            $escCustName = htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8');
            setFlash('success', "SPK <strong>{$escOrderNum}</strong> ({$escCustName}) berhasil dibatalkan dan dihapus.");
            header('Location: orders.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', 'Gagal menghapus SPK: ' . $e->getMessage());
        }
    }
}

$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
$gcNames = ($driver === 'mysql') ? "GROUP_CONCAT(i.name SEPARATOR '||')" : "GROUP_CONCAT(i.name, '||')";
$gcScopes = ($driver === 'mysql') ? "GROUP_CONCAT(i.scope_code SEPARATOR '||')" : "GROUP_CONCAT(i.scope_code, '||')";
$gcKan = ($driver === 'mysql') ? "GROUP_CONCAT(COALESCE(i.is_kan, 1) SEPARATOR '||')" : "GROUP_CONCAT(COALESCE(i.is_kan, 1), '||')";

$orders = $db->query("
    SELECT o.id, o.order_number, o.customer_name, o.customer_address, o.customer_contact, 
           o.order_date, o.service_type, o.status, o.created_at,
           (SELECT id FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as instrument_id,
           (SELECT name FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as instrument_name,
           (SELECT scope_code FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as scope_code,
           (SELECT COALESCE(is_kan, 1) FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as instrument_is_kan,
           (SELECT brand FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as brand,
           (SELECT model_type FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as model_type,
           (SELECT serial_number FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as serial_number,
           (SELECT capacity_range FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as capacity_range,
           (SELECT resolution FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as resolution,
           (SELECT technician_name FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as technician_name,
           (SELECT calibration_date FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as calibration_date,
           (SELECT status FROM instruments WHERE order_id = o.id ORDER BY id ASC LIMIT 1) as instrument_status,
           (SELECT c.id FROM certificates c JOIN instruments inst ON c.instrument_id = inst.id WHERE inst.order_id = o.id LIMIT 1) as certificate_id,
           (SELECT c.certificate_number FROM certificates c JOIN instruments inst ON c.instrument_id = inst.id WHERE inst.order_id = o.id LIMIT 1) as certificate_number,
           COUNT(i.id) as total_instruments,
           {$gcNames} as instrument_names,
           {$gcScopes} as scope_codes,
           {$gcKan} as is_kan_list
    FROM orders o
    LEFT JOIN instruments i ON i.order_id = o.id
    GROUP BY o.id, o.order_number, o.customer_name, o.customer_address, o.customer_contact, 
             o.order_date, o.service_type, o.status, o.created_at
    ORDER BY o.id DESC
")->fetchAll();

function mapOrderEditData(array $o): array {
    return [
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
}

$autoEditOrderNumber = trim($_GET['edit_order'] ?? '');
$autoEditData = null;
if ($autoEditOrderNumber !== '') {
    foreach ($orders as $ord) {
        if ($ord['order_number'] === $autoEditOrderNumber) {
            $autoEditData = mapOrderEditData($ord);
            break;
        }
    }
}

require_once __DIR__ . '/includes/header.php';

$totalOrdersCount = count($orders);
$inLabOrdersCount = count(array_filter($orders, fn($o) => $o['service_type'] === 'IN_LAB'));
$onSiteOrdersCount = count(array_filter($orders, fn($o) => $o['service_type'] === 'ON_SITE'));
$completedOrdersCount = count(array_filter($orders, fn($o) => $o['status'] === 'COMPLETED'));
?>

<div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="px-2 py-0.5 rounded text-[11px] font-mono font-semibold bg-red-100 text-red-800 border border-red-200 dark:bg-red-950/50 dark:text-red-300 dark:border-red-800/60">SALES & FRONT OFFICE</span>
            <span class="text-xs text-slate-400">·</span>
            <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">Registrasi Pelanggan & SPK</span>
        </div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100 tracking-tight">Surat Perintah Kerja (SPK)</h1>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-3xl">Penerimaan permintaan kalibrasi pelanggan, pendaftaran spesifikasi instrumen, penentuan ruang lingkup acuan, dan penugasan teknisi lab.</p>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
            <button onclick="openModal('create-order-modal')" class="btn-brand-primary">
                <i class="ph-bold ph-plus text-xs"></i>
                <span>+ Buat SPK Baru</span>
            </button>
        <?php else: ?>
            <span class="badge-status neutral">
                <i class="ph-bold ph-lock-key"></i>
                <span>Mode Tinjau (Divisi Sales)</span>
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="kpi-widget accent-primary">
        <div class="flex items-center justify-between"><span class="kpi-label">Total SPK Terbit</span><i class="ph-bold ph-clipboard-text text-base text-slate-400"></i></div>
        <div class="kpi-value text-slate-900 dark:text-slate-100"><?= $totalOrdersCount ?></div>
        <div class="kpi-subtext">Semua berkas pemesanan</div>
    </div>
    <div class="kpi-widget accent-info">
        <div class="flex items-center justify-between"><span class="kpi-label">Layanan In-Lab</span><i class="ph-bold ph-flask text-base text-sky-500"></i></div>
        <div class="kpi-value text-sky-700 dark:text-sky-400"><?= $inLabOrdersCount ?></div>
        <div class="kpi-subtext">Dikerjakan di Lab Kalpindo</div>
    </div>
    <div class="kpi-widget accent-warning">
        <div class="flex items-center justify-between"><span class="kpi-label">Layanan On-Site</span><i class="ph-bold ph-buildings text-base text-amber-500"></i></div>
        <div class="kpi-value text-amber-700 dark:text-amber-400"><?= $onSiteOrdersCount ?></div>
        <div class="kpi-subtext">Kalibrasi di lokasi klien</div>
    </div>
    <div class="kpi-widget accent-success">
        <div class="flex items-center justify-between"><span class="kpi-label">SPK Selesai (Completed)</span><span class="w-2 h-2 rounded-full bg-emerald-500"></span></div>
        <div class="kpi-value text-emerald-700 dark:text-emerald-400"><?= $completedOrdersCount ?></div>
        <div class="kpi-subtext">Sertifikat terbit resmi</div>
    </div>
</div>

<div class="ent-card overflow-hidden">
    <div class="ent-card-header">
        <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full bg-slate-900 dark:bg-slate-100"></span>
            <h2 class="text-xs font-bold text-slate-900 dark:text-slate-100 uppercase tracking-wider">Daftar Berkas SPK Aktif</h2>
        </div>
        <span class="text-xs text-slate-500 dark:text-slate-400 font-mono font-medium">Total: <?= count($orders) ?> berkas</span>
    </div>

    <div class="overflow-x-auto">
        <table class="ent-table min-w-[1150px]">
            <thead>
                <tr>
                    <th class="w-[180px]">No. Order & Tanggal</th>
                    <th class="w-[240px]">Pelanggan & Kontak</th>
                    <th class="w-[110px]">Layanan</th>
                    <th class="w-[230px]">Instrumen / Alat</th>
                    <th class="w-[140px]">Ruang Lingkup</th>
                    <th class="w-[140px]">Status Order</th>
                    <th class="text-right w-[150px]">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="7" class="py-12 text-center text-slate-400">
                            <i class="ph-bold ph-folder-open text-3xl mb-2 inline-block text-slate-300 dark:text-slate-600"></i>
                            <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Belum ada order kalibrasi yang terdaftar.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($orders as $o): 
                    $editData = mapOrderEditData($o);
                    $menuIdOrder = "action-menu-order-" . $o['id'];
                ?>
                    <tr>
                        <td class="whitespace-nowrap">
                            <div class="font-mono text-xs font-bold text-slate-900 dark:text-slate-100 tracking-tight"><?= htmlspecialchars($o['order_number']) ?></div>
                            <div class="cell-meta text-slate-400 mt-1 flex items-center gap-1">
                                <i class="ph-bold ph-calendar-blank text-xs"></i>
                                <span><?= formatIndonesianDate($o['order_date']) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="cell-primary truncate max-w-[220px]" title="<?= htmlspecialchars($o['customer_name']) ?>"><?= htmlspecialchars($o['customer_name']) ?></div>
                            <div class="cell-secondary truncate max-w-[220px]" title="<?= htmlspecialchars($o['customer_address']) ?>"><?= htmlspecialchars($o['customer_address'] ?: '-') ?></div>
                            <div class="cell-meta"><?= htmlspecialchars($o['customer_contact'] ?: '-') ?></div>
                        </td>
                        <td class="whitespace-nowrap"><?= renderLocationBadge($o['service_type']) ?></td>
                        <td>
                            <?php 
                                $instNames = explode('||', (string)$o['instrument_names']);
                                $kanList = explode('||', (string)($o['is_kan_list'] ?? ''));
                                foreach (array_filter($instNames) as $idx => $name): 
                                    $isItemKan = ($kanList[$idx] ?? '1') === '1';
                            ?>
                                <div class="text-xs font-medium text-slate-800 dark:text-slate-200 flex items-center gap-1.5 py-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#C81E26] shrink-0"></span>
                                    <span class="truncate max-w-[160px]" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></span>
                                    <?= renderAccreditationBadge($isItemKan) ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td class="whitespace-nowrap">
                            <?php 
                                $scCodes = array_unique(array_filter(explode('||', (string)$o['scope_codes'])));
                                foreach ($scCodes as $sc):
                                    $scInfo = $scopes[$sc] ?? ['name' => $sc];
                            ?>
                                <span class="font-mono text-xs font-semibold text-slate-800 dark:text-slate-200 block">[<?= htmlspecialchars($sc) ?>] <?= htmlspecialchars(explode(' ', $scInfo['name'])[0]) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="whitespace-nowrap"><?= renderStatusBadge($o['status']) ?></td>
                        <td class="text-right whitespace-nowrap">
                            <div class="inline-flex items-center justify-end gap-1.5">
                                <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
                                    <button type="button" onclick='openEditOrderModal(<?= json_encode($editData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)' class="btn-surface btn-compact whitespace-nowrap text-xs" title="Edit SPK & Data Instrumen">
                                        <i class="ph-bold ph-pencil-simple text-slate-500"></i>
                                        <span>Edit SPK</span>
                                    </button>
                                <?php else: ?>
                                    <button type="button" onclick='openEditOrderModal(<?= json_encode($editData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)' class="btn-surface btn-compact whitespace-nowrap text-xs" title="Tinjau Rincian SPK & Spesifikasi Alat">
                                        <i class="ph-bold ph-eye text-slate-500"></i>
                                        <span>Detail SPK</span>
                                    </button>
                                <?php endif; ?>
                                <div class="action-menu-container">
                                    <button type="button" onclick="toggleActionMenu('<?= $menuIdOrder ?>', event)" class="btn-icon" title="Opsi SPK" aria-label="Opsi">
                                        <i class="ph-bold ph-dots-three-vertical text-sm"></i>
                                    </button>
                                    <div id="<?= $menuIdOrder ?>" class="action-menu-dropdown">
                                        <a href="index.php?search=<?= urlencode($o['order_number']) ?>" class="action-menu-item">
                                            <i class="ph-bold ph-chart-line text-slate-500"></i><span>Lacak Alur Kerja</span>
                                        </a>
                                        <?php if (hasRole(['SUPER_ADMIN', 'TECHNICIAN', 'CERT_ADMIN'])): ?>
                                            <a href="worksheet.php?instrument_id=<?= (int)($o['instrument_id'] ?? 0) ?>&order_id=<?= (int)$o['id'] ?>" class="action-menu-item">
                                                <i class="ph-bold ph-wrench text-slate-500"></i><span>Buka Worksheet</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (($o['instrument_status'] ?? '') === 'DATA_SUBMITTED' && hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                            <a href="certificates.php?instrument_id=<?= (int)($o['instrument_id'] ?? 0) ?>" class="action-menu-item">
                                                <i class="ph-bold ph-certificate text-slate-500"></i><span>Terbitkan Sertifikat</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (hasRole(['SUPER_ADMIN', 'SALES']) && $o['status'] !== 'COMPLETED' && ($o['instrument_status'] ?? '') !== 'CERTIFIED'): ?>
                                            <div class="action-menu-divider"></div>
                                            <form action="orders.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan & menghapus SPK <?= htmlspecialchars($o['order_number']) ?>?');">
                                                <input type="hidden" name="action" value="delete_order">
                                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                                <button type="submit" class="action-menu-item danger">
                                                    <i class="ph-bold ph-trash"></i><span>Hapus SPK</span>
                                                </button>
                                            </form>
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

    <div class="ent-card-footer flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-slate-500">
        <span>Menampilkan <strong><?= count($orders) ?></strong> berkas SPK kalibrasi</span>
        <span class="font-mono text-[11px] text-slate-400">Sistem Kalibrasi Terintegrasi ISO/IEC 17025:2017</span>
    </div>
</div>

<!-- Modal: Tambah Order Baru -->
<div id="create-order-modal" class="fixed inset-0 z-modal bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="ent-modal w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6 shadow-xl text-xs">
        <div class="flex items-center justify-between border-b pb-3 mb-4" style="border-color: var(--border-subtle);">
            <div>
                <h3 class="text-base font-bold tracking-tight mb-0" style="color: var(--text-primary);">Input Work Order Kalibrasi Baru</h3>
                <p class="text-muted text-[11px] mt-0.5 mb-0">Registrasi data order pelanggan, spesifikasi alat, dan penugasan teknisi.</p>
            </div>
            <button type="button" onclick="closeModal('create-order-modal')" class="btn-icon"><i class="ph-bold ph-x text-base"></i></button>
        </div>

        <form id="create-order-form" action="orders.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_order">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 px-3 py-2 rounded-lg border" style="background-color: var(--surface-subtle); border-color: var(--border-subtle);">
                <span class="text-[11px] font-medium text-secondary flex items-center gap-1.5"><i class="ph-bold ph-lightning text-muted"></i> Contoh Standar:</span>
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button type="button" onclick="fillSampleOrder('pressure')" class="btn-surface py-1 px-2 text-[11px]">[P] Tekanan</button>
                    <button type="button" onclick="fillSampleOrder('temperature')" class="btn-surface py-1 px-2 text-[11px]">[T] Suhu</button>
                    <button type="button" onclick="fillSampleOrder('mass')" class="btn-surface py-1 px-2 text-[11px]">[M] Massa</button>
                    <button type="button" onclick="fillSampleOrder('dimension')" class="btn-surface py-1 px-2 text-[11px]">[D] Dimensi</button>
                    <button type="button" onclick="fillSampleOrder('electric_nonkan')" class="btn-surface py-1 px-2 text-[11px]">[E] Listrik (Non-KAN)</button>
                </div>
            </div>

            <div class="space-y-3 pt-1">
                <div class="flex items-center gap-2 border-b pb-2" style="border-color: var(--border-subtle);">
                    <span class="text-[11px] font-semibold text-uppercase tracking-wider" style="color: var(--text-primary);">A. Data Pelanggan</span>
                </div>
                <div>
                    <label class="block font-medium mb-1" style="color: var(--text-secondary);">Nama Perusahaan / Pelanggan <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" required placeholder="Contoh: PT Astra Otoparts Tbk" class="ent-input">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Tanggal SPK <span class="text-danger">*</span></label>
                        <input type="date" name="order_date" value="<?= date('Y-m-d') ?>" required class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Kontak Person / PIC</label>
                        <input type="text" name="customer_contact" placeholder="Contoh: Bpk. Bambang (0812-xxxx)" class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Lokasi Layanan <span class="text-danger">*</span></label>
                        <select name="service_type" required class="ent-select">
                            <option value="IN_LAB">In-Lab (Laboratorium PT Kalpindo)</option>
                            <option value="ON_SITE">On-Site (Pabrik / Lokasi Klien)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block font-medium mb-1" style="color: var(--text-secondary);">Alamat Pabrik / Site</label>
                    <textarea name="customer_address" rows="2" placeholder="Contoh: Kawasan Industri KIIC, Karawang Barat" class="ent-input"></textarea>
                </div>
            </div>

            <div class="space-y-3 pt-2">
                <div class="flex items-center gap-2 border-b pb-2" style="border-color: var(--border-subtle);">
                    <span class="text-[11px] font-semibold text-uppercase tracking-wider" style="color: var(--text-primary);">B. Spesifikasi Instrumen & Penugasan</span>
                </div>
                <div>
                    <label class="block font-medium mb-1" style="color: var(--text-secondary);">Nama Alat <span class="text-danger">*</span></label>
                    <input type="text" name="instrument_name" required placeholder="Contoh: Digital Pressure Gauge" class="ent-input">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Ruang Lingkup (Scope) <span class="text-danger">*</span></label>
                        <select name="scope_code" required class="ent-select font-mono">
                            <?php foreach ($scopes as $code => $sc): ?>
                                <option value="<?= $code ?>">[<?= $code ?>] <?= htmlspecialchars($sc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Status Akreditasi <span class="text-danger">*</span></label>
                        <select name="is_kan" required class="ent-select font-medium">
                            <option value="1">Akreditasi KAN (Standar ISO/IEC 17025)</option>
                            <option value="0">Non-KAN (Kalibrasi Tertelusur / Awalan N)</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Merk / Brand</label>
                        <input type="text" name="brand" placeholder="Contoh: Fluke / Mitutoyo" class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Model / Tipe</label>
                        <input type="text" name="model_type" placeholder="Contoh: 700G07" class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Nomor Seri (SN) <span class="text-danger">*</span></label>
                        <input type="text" name="serial_number" required placeholder="Contoh: SN-829104" class="ent-input font-mono">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Rentang Ukur (Range)</label>
                        <input type="text" name="capacity_range" placeholder="Contoh: 0 - 25 bar / 0 - 150 mm" class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Resolusi</label>
                        <input type="text" name="resolution" placeholder="Contoh: 0.001 bar / 0.01 mm" class="ent-input">
                    </div>
                </div>
                <div>
                    <label class="block font-medium mb-1" style="color: var(--text-secondary);">Teknisi Penanggung Jawab <span class="text-danger">*</span></label>
                    <select name="technician_name" required class="ent-select">
                        <?php if (empty($technicians)): ?>
                            <option value="">-- Belum ada akun teknisi terdaftar di sistem --</option>
                        <?php else: ?>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= htmlspecialchars($t['full_name']) ?>"><?= htmlspecialchars($t['full_name']) ?> (<?= htmlspecialchars($t['department'] ?: 'Operasional Laboratorium') ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="text-[11px] text-muted mt-1">Daftar staf teknisi laboratorium yang terdaftar di sistem.</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t" style="border-color: var(--border-subtle);">
                <button type="button" onclick="closeModal('create-order-modal')" class="btn-surface">Batal</button>
                <button type="submit" class="btn-brand-primary">
                    <i class="ph-bold ph-plus text-xs"></i>
                    <span>Simpan & Terbitkan SPK</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Surat Perintah Kerja (SPK) -->
<div id="edit-order-modal" class="fixed inset-0 z-modal bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="ent-modal w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6 shadow-xl text-xs">
        <div class="flex items-center justify-between border-b pb-3 mb-4" style="border-color: var(--border-subtle);">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-bold tracking-tight mb-0" style="color: var(--text-primary);">Edit Surat Perintah Kerja (SPK)</h3>
                    <span id="edit-order-badge" class="font-mono text-xs font-semibold px-2 py-0.5 rounded border" style="background-color: var(--surface-subtle); border-color: var(--border-subtle); color: var(--text-primary);"></span>
                </div>
                <p class="text-muted text-[11px] mt-0.5 mb-0">Perbarui data pelanggan, informasi alat, atau penugasan teknisi.</p>
            </div>
            <button type="button" onclick="closeModal('edit-order-modal')" class="btn-icon"><i class="ph-bold ph-x text-base"></i></button>
        </div>

        <div id="edit-certified-warning" class="hidden p-3 rounded-lg border text-xs flex items-start gap-2.5 mb-4" style="background-color: var(--surface-subtle); border-color: var(--border-subtle); color: var(--text-primary);">
            <i class="ph-bold ph-info text-base text-warning shrink-0 mt-0.5"></i>
            <div>
                <p class="font-semibold mb-0">Sertifikat Terbit: <span id="edit-cert-no" class="font-mono"></span></p>
                <p class="text-[11px] text-muted mt-0.5 mb-0">Sesuai standar ISO/IEC 17025, Ruang Lingkup dan Status KAN dikunci karena nomor sertifikat telah diterbitkan. Data pelanggan dan spesifikasi fisik alat tetap dapat diperbarui.</p>
            </div>
        </div>

        <form id="edit-order-form" action="orders.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_order">
            <input type="hidden" name="order_id" id="edit-order-id">
            <input type="hidden" name="instrument_id" id="edit-instrument-id">

            <div class="space-y-3 pt-1">
                <div class="flex items-center gap-2 border-b pb-2" style="border-color: var(--border-subtle);">
                    <span class="text-[11px] font-semibold text-uppercase tracking-wider" style="color: var(--text-primary);">A. Data Pelanggan</span>
                </div>
                <div>
                    <label class="block font-medium mb-1" style="color: var(--text-secondary);">Nama Perusahaan / Pelanggan <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" id="edit-customer-name" required placeholder="Contoh: PT Astra Otoparts Tbk" class="ent-input">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Tanggal SPK <span class="text-danger">*</span></label>
                        <input type="date" name="order_date" id="edit-order-date" required class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Kontak Person / PIC</label>
                        <input type="text" name="customer_contact" id="edit-customer-contact" placeholder="Contoh: Bpk. Bambang (0812-xxxx)" class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Lokasi Layanan <span class="text-danger">*</span></label>
                        <select name="service_type" id="edit-service-type" required class="ent-select">
                            <option value="IN_LAB">In-Lab (Laboratorium PT Kalpindo)</option>
                            <option value="ON_SITE">On-Site (Pabrik / Lokasi Klien)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block font-medium mb-1" style="color: var(--text-secondary);">Alamat Pabrik / Site</label>
                    <textarea name="customer_address" id="edit-customer-address" rows="2" placeholder="Contoh: Kawasan Industri KIIC, Karawang Barat" class="ent-input"></textarea>
                </div>
            </div>

            <div class="space-y-3 pt-2">
                <div class="flex items-center gap-2 border-b pb-2" style="border-color: var(--border-subtle);">
                    <span class="text-[11px] font-semibold text-uppercase tracking-wider" style="color: var(--text-primary);">B. Spesifikasi Instrumen & Penugasan</span>
                </div>
                <div>
                    <label class="block font-medium mb-1" style="color: var(--text-secondary);">Nama Alat <span class="text-danger">*</span></label>
                    <input type="text" name="instrument_name" id="edit-instrument-name" required placeholder="Contoh: Digital Pressure Gauge" class="ent-input">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Ruang Lingkup (Scope) <span class="text-danger">*</span></label>
                        <select name="scope_code" id="edit-scope-code" required class="ent-select font-mono">
                            <?php foreach ($scopes as $code => $sc): ?>
                                <option value="<?= $code ?>">[<?= $code ?>] <?= htmlspecialchars($sc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Status Akreditasi <span class="text-danger">*</span></label>
                        <select name="is_kan" id="edit-is-kan" required class="ent-select font-medium">
                            <option value="1">Akreditasi KAN (Standar ISO/IEC 17025)</option>
                            <option value="0">Non-KAN (Kalibrasi Tertelusur / Awalan N)</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Merk / Brand</label>
                        <input type="text" name="brand" id="edit-brand" placeholder="Contoh: Fluke / Mitutoyo" class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Model / Tipe</label>
                        <input type="text" name="model_type" id="edit-model-type" placeholder="Contoh: 700G07" class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Nomor Seri (SN) <span class="text-danger">*</span></label>
                        <input type="text" name="serial_number" id="edit-serial-number" required placeholder="Contoh: SN-829104" class="ent-input font-mono">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Rentang Ukur (Range)</label>
                        <input type="text" name="capacity_range" id="edit-capacity-range" placeholder="Contoh: 0 - 25 bar / 0 - 150 mm" class="ent-input">
                    </div>
                    <div>
                        <label class="block font-medium mb-1" style="color: var(--text-secondary);">Resolusi</label>
                        <input type="text" name="resolution" id="edit-resolution" placeholder="Contoh: 0.001 bar / 0.01 mm" class="ent-input">
                    </div>
                </div>
                <div>
                    <label class="block font-medium mb-1" style="color: var(--text-secondary);">Teknisi Penanggung Jawab <span class="text-danger">*</span></label>
                    <select name="technician_name" id="edit-technician-name" required class="ent-select">
                        <?php if (empty($technicians)): ?>
                            <option value="">-- Belum ada akun teknisi terdaftar di sistem --</option>
                        <?php else: ?>
                            <?php foreach ($technicians as $t): ?>
                                <option value="<?= htmlspecialchars($t['full_name']) ?>"><?= htmlspecialchars($t['full_name']) ?> (<?= htmlspecialchars($t['department'] ?: 'Operasional Laboratorium') ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="text-[11px] text-muted mt-1">Daftar staf teknisi laboratorium yang terdaftar di sistem.</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t" style="border-color: var(--border-subtle);">
                <button type="button" onclick="closeModal('edit-order-modal')" class="btn-surface"><?= hasRole(['SUPER_ADMIN', 'SALES']) ? 'Batal' : 'Tutup' ?></button>
                <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
                    <button type="submit" class="btn-brand-primary">
                        <i class="ph-bold ph-floppy-disk"></i>
                        <span>Simpan Perubahan SPK</span>
                    </button>
                <?php endif; ?>
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
        const legacyOption = techSelect.querySelector('option[data-legacy="1"]');
        if (legacyOption) legacyOption.remove();
        if (data.technician_name) {
            let found = false;
            for (let i = 0; i < techSelect.options.length; i++) {
                if (techSelect.options[i].value === data.technician_name) {
                    techSelect.selectedIndex = i;
                    found = true;
                    break;
                }
            }
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

    const canEditOrder = <?= hasRole(['SUPER_ADMIN', 'SALES']) ? 'true' : 'false' ?>;

    if (!canEditOrder) {
        const modalForm = document.getElementById('edit-order-form');
        if (modalForm) {
            modalForm.querySelectorAll('input, select, textarea').forEach(el => {
                if (el.type !== 'hidden') {
                    el.setAttribute('disabled', 'disabled');
                    el.classList.add('bg-gray-100', 'cursor-not-allowed');
                }
            });
        }
    } else if (data.is_certified) {
        if (warningBox) warningBox.classList.remove('hidden');
        if (certNoSpan) certNoSpan.textContent = data.certificate_number || 'Sertifikat Terbit';
        [scopeSelect, kanSelect].forEach(el => {
            if (el) { el.setAttribute('disabled', 'disabled'); el.classList.add('bg-gray-100', 'cursor-not-allowed', 'opacity-75'); }
        });
    } else {
        if (warningBox) warningBox.classList.add('hidden');
        [scopeSelect, kanSelect].forEach(el => {
            if (el) { el.removeAttribute('disabled'); el.classList.remove('bg-gray-100', 'cursor-not-allowed', 'opacity-75'); }
        });
    }
    openModal('edit-order-modal');
}

function fillSampleOrder(type) {
    const form = document.getElementById('create-order-form');
    if (!form) return;
    const regTech = form.elements['technician_name']?.options[0]?.value || '';
    const samples = {
        pressure: { customer_name: 'PT Pertamina Hulu Rokan', customer_contact: 'Bpk. Hendro (0812-7788-9900)', service_type: 'ON_SITE', customer_address: 'Kawasan Lapangan Duri, Riau', instrument_name: 'Pressure Gauge High Precision', scope_code: 'P', is_kan: '1', brand: 'WIKA Instrument', model_type: '232.50.100', pfx: 'WK-', capacity_range: '0 - 25 bar', resolution: '0.01 bar' },
        temperature: { customer_name: 'PT Kalbe Farma Tbk', customer_contact: 'Ibu Dewi (0813-1122-3344)', service_type: 'IN_LAB', customer_address: 'Delta Silicon II Cikarang', instrument_name: 'Digital Thermometer & RTD Probe', scope_code: 'T', is_kan: '1', brand: 'Fluke Calibration', model_type: 'Fluke 1523', pfx: 'FLK-', capacity_range: '-50 °C s/d 200 °C', resolution: '0.001 °C' },
        mass: { customer_name: 'PT Mayora Indah Tbk', customer_contact: 'Bpk. Agus (0815-5566-7788)', service_type: 'IN_LAB', customer_address: 'Jl. Telesonic Jatake, Tangerang', instrument_name: 'Precision Analytical Balance', scope_code: 'M', is_kan: '1', brand: 'Sartorius', model_type: 'Entris II', pfx: 'SAR-', capacity_range: '0 - 220 g', resolution: '0.0001 g' },
        dimension: { customer_name: 'PT Komatsu Indonesia', customer_contact: 'Bpk. Hermawan (0817-8899-0011)', service_type: 'IN_LAB', customer_address: 'Jl. Raya Bekasi KM. 22, Cakung', instrument_name: 'Digital Vernier Caliper 150mm', scope_code: 'D', is_kan: '1', brand: 'Mitutoyo', model_type: '500-196-30', pfx: 'MTY-', capacity_range: '0 - 150 mm', resolution: '0.01 mm' },
        electric_nonkan: { customer_name: 'PT Schneider Electric Manufacturing', customer_contact: 'Ibu Lisa (0819-2233-4455)', service_type: 'ON_SITE', customer_address: 'Batamindo Industrial Park, Batam', instrument_name: 'Digital True-RMS Multimeter', scope_code: 'E', is_kan: '0', brand: 'Fluke', model_type: 'Fluke 87V', pfx: 'FLK-', capacity_range: '0 - 1000 V AC/DC', resolution: '0.0001 V' }
    };
    const s = samples[type];
    if (!s) return;
    Object.keys(s).forEach(k => {
        if (k !== 'pfx' && form.elements[k]) form.elements[k].value = s[k];
    });
    form.elements['serial_number'].value = s.pfx + Math.floor(100000 + Math.random() * 900000);
    if (regTech) form.elements['technician_name'].value = regTech;
}

<?php if (!empty($autoEditData)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openEditOrderModal(<?= json_encode($autoEditData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);
});
<?php elseif (isset($_GET['action']) && $_GET['action'] === 'create'): ?>
document.addEventListener('DOMContentLoaded', function() {
    openModal('create-order-modal');
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
