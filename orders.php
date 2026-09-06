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

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_order') {
    requireRole(['SUPER_ADMIN', 'SALES'], 'orders.php');
    try {
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerAddress = trim($_POST['customer_address'] ?? '');
        $customerContact = trim($_POST['customer_contact'] ?? '');
        $serviceType = in_array($_POST['service_type'] ?? '', ['IN_LAB', 'ON_SITE']) ? $_POST['service_type'] : 'IN_LAB';
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');

        $instName = trim($_POST['instrument_name'] ?? '');
        $scopeCode = strtoupper(trim($_POST['scope_code'] ?? 'P'));
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
        $maxOrderCount = (int)$db->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'ORD-{$yearMonth}-%'")->fetchColumn();
        $nextOrderSeq = str_pad((string)($maxOrderCount + 1), 3, '0', STR_PAD_LEFT);
        $orderNumber = "ORD-{$yearMonth}-{$nextOrderSeq}";

        $db->beginTransaction();

        $stmtOrder = $db->prepare("
            INSERT INTO orders (order_number, customer_name, customer_address, customer_contact, order_date, service_type, status)
            VALUES (?, ?, ?, ?, ?, ?, 'PENDING')
        ");
        $stmtOrder->execute([$orderNumber, $customerName, $customerAddress, $customerContact, $orderDate, $serviceType]);
        $orderId = (int)$db->lastInsertId();

        $stmtInst = $db->prepare("
            INSERT INTO instruments (order_id, name, scope_code, brand, model_type, serial_number, capacity_range, resolution, technician_name, calibration_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ASSIGNED')
        ");
        $stmtInst->execute([$orderId, $instName, $scopeCode, $brand, $modelType, $serialNumber, $capacityRange, $resolution, $technicianName, $orderDate]);

        $db->commit();

        setFlash('success', "Work Order baru {$orderNumber} berhasil dibuat dan ditugaskan ke teknisi {$technicianName}!");
        header('Location: orders.php');
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', $e->getMessage());
    }
}

$orders = $db->query("
    SELECT 
        o.*,
        COUNT(i.id) as total_instruments,
        GROUP_CONCAT(i.name, '||') as instrument_names,
        GROUP_CONCAT(i.scope_code, '||') as scope_codes
    FROM orders o
    LEFT JOIN instruments i ON i.order_id = o.id
    GROUP BY o.id
    ORDER BY o.id DESC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header Control Bar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
            <span>Portal Karyawan</span>
            <span>/</span>
            <span>Sales & Front Office</span>
            <span>/</span>
            <span class="text-slate-800 font-semibold">Daftar SPK</span>
        </div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Penerimaan Order & Registrasi Alat (SPK)</h1>
    </div>
    <div>
        <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
            <button onclick="openModal('create-order-modal')" class="bg-[#C81E26] hover:bg-[#A8141B] text-white px-4 py-2 rounded-lg font-semibold text-xs inline-flex items-center gap-1.5 shadow-sm transition-all">
                <i class="ph-bold ph-plus text-sm"></i>
                <span>+ Input Work Order Baru</span>
            </button>
        <?php else: ?>
            <span class="px-3 py-2 rounded-lg bg-gray-100 text-gray-500 font-medium text-xs inline-flex items-center gap-1.5 border border-gray-200">
                <i class="ph-bold ph-lock-key text-gray-400"></i>
                <span>Mode View (Khusus Divisi Sales)</span>
            </span>
        <?php endif; ?>
    </div>
</div>



<!-- Orders Table Card -->
<div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 sm:p-6">
    <div class="flex items-center justify-between mb-4 border-b border-gray-100 pb-3">
        <h2 class="text-base font-bold text-slate-900">Daftar Surat Perintah Kerja (SPK)</h2>
        <span class="text-xs font-mono text-gray-500 bg-gray-100 px-2.5 py-0.5 rounded-full font-bold">Total <?= count($orders) ?> Berkas</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs min-w-[850px]">
            <thead class="bg-gray-50 text-gray-600 font-semibold border-y border-gray-200 uppercase text-[10px] tracking-wider">
                <tr>
                    <th class="py-2.5 px-3">No. Order & Tanggal</th>
                    <th class="py-2.5 px-3">Pelanggan & Kontak</th>
                    <th class="py-2.5 px-3">Jenis Layanan</th>
                    <th class="py-2.5 px-3">Daftar Alat</th>
                    <th class="py-2.5 px-3">Ruang Lingkup</th>
                    <th class="py-2.5 px-3">Status Order</th>
                    <th class="py-2.5 px-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center text-gray-400">Belum ada order kalibrasi yang terdaftar.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($orders as $o): ?>
                    <tr class="hover:bg-slate-50/80 transition-colors">
                        <td class="py-3 px-3">
                            <span class="font-mono text-xs font-bold text-slate-900 bg-gray-100 px-2 py-0.5 rounded">
                                <?= htmlspecialchars($o['order_number']) ?>
                            </span>
                            <p class="text-[11px] text-gray-400 mt-0.5"><?= formatIndonesianDate($o['order_date']) ?></p>
                        </td>

                        <td class="py-3 px-3">
                            <p class="font-bold text-slate-900"><?= htmlspecialchars($o['customer_name']) ?></p>
                            <p class="text-[11px] text-gray-500 mt-0.5 max-w-[220px] truncate" title="<?= htmlspecialchars($o['customer_address']) ?>">
                                <?= htmlspecialchars($o['customer_address']) ?>
                            </p>
                            <p class="text-[10px] text-gray-400 font-mono"><?= htmlspecialchars($o['customer_contact'] ?: '-') ?></p>
                        </td>

                        <td class="py-3 px-3">
                            <?= renderLocationBadge($o['service_type']) ?>
                        </td>

                        <td class="py-3 px-3">
                            <?php 
                                $instNames = explode('||', (string)$o['instrument_names']);
                                foreach (array_filter($instNames) as $name): 
                            ?>
                                <p class="text-xs text-slate-700 font-medium flex items-center gap-1.5 py-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#C81E26]"></span>
                                    <?= htmlspecialchars($name) ?>
                                </p>
                            <?php endforeach; ?>
                        </td>

                        <td class="py-3 px-3">
                            <div class="flex flex-wrap gap-1">
                                <?php 
                                    $scCodes = array_unique(array_filter(explode('||', (string)$o['scope_codes'])));
                                    foreach ($scCodes as $sc):
                                        $scInfo = $scopes[$sc] ?? ['name' => $sc, 'badge_class' => 'bg-gray-100 text-gray-700'];
                                ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border <?= $scInfo['badge_class'] ?>">
                                        <?= htmlspecialchars($sc) ?> - <?= htmlspecialchars(explode(' ', $scInfo['name'])[0]) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>

                        <td class="py-3 px-3">
                            <?= renderStatusBadge($o['status']) ?>
                        </td>

                        <td class="py-3 px-3 text-right">
                            <a href="worksheet.php?order_id=<?= $o['id'] ?>" class="px-3 py-1 rounded-md text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-slate-700 inline-flex items-center gap-1 transition-all">
                                <span>Pengerjaan &rarr;</span>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Tambah Order Baru -->
<div id="create-order-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl border border-gray-200 w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6 shadow-xl text-xs">
        
        <div class="flex items-center justify-between border-b border-gray-100 pb-3 mb-4">
            <div>
                <h3 class="text-base font-bold text-slate-900">Input Work Order Kalibrasi Baru</h3>
                <p class="text-gray-400 text-[11px]">Tahap 1 Sales: Registrasi order customer & penugasan teknisi</p>
            </div>
            <button onclick="closeModal('create-order-modal')" class="text-gray-400 hover:text-gray-700 text-xl font-bold">&times;</button>
        </div>

        <form id="create-order-form" action="orders.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_order">

            <!-- Quick Sample Autofill Buttons -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-red-50/60 p-2.5 rounded-xl border border-red-200">
                <span class="text-[11px] font-bold text-[#C81E26] flex items-center gap-1">
                    <i class="ph-bold ph-lightning text-amber-500"></i>
                    Isi Cepat Data Standar Industri:
                </span>
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button type="button" onclick="fillSampleOrder('pressure')" class="px-2.5 py-1 rounded-md bg-white hover:bg-red-50 text-slate-700 hover:text-[#C81E26] border border-gray-200 font-semibold text-[10px] shadow-2xs transition-colors">
                        ⚡ Pressure Gauge
                    </button>
                    <button type="button" onclick="fillSampleOrder('temperature')" class="px-2.5 py-1 rounded-md bg-white hover:bg-amber-50 text-slate-700 hover:text-amber-800 border border-gray-200 font-semibold text-[10px] shadow-2xs transition-colors">
                        ⚡ Termometer Digital
                    </button>
                    <button type="button" onclick="fillSampleOrder('mass')" class="px-2.5 py-1 rounded-md bg-white hover:bg-blue-50 text-slate-700 hover:text-blue-800 border border-gray-200 font-semibold text-[10px] shadow-2xs transition-colors">
                        ⚡ Timbangan Analitik
                    </button>
                </div>
            </div>

            <!-- Section 1 -->
            <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 space-y-2.5">
                <h4 class="text-[11px] font-bold uppercase text-[#C81E26] tracking-wider">A. Data Pelanggan</h4>
                
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Nama Perusahaan / Customer *</label>
                    <input type="text" name="customer_name" required placeholder="Contoh: PT Astra Otoparts Tbk" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Kontak Person / PIC</label>
                        <input type="text" name="customer_contact" placeholder="Contoh: Bpk. Bambang (0812-xxxx)" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Jenis Layanan Kalibrasi *</label>
                        <select name="service_type" required class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                            <option value="IN_LAB">In-Lab (Pengerjaan di Lab PT Kalpindo)</option>
                            <option value="ON_SITE">On-Site (Kunjungan ke Pabrik Klien)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Alamat Pabrik / Site</label>
                    <textarea name="customer_address" rows="2" placeholder="Contoh: Kawasan Industri KIIC, Karawang" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]"></textarea>
                </div>
            </div>

            <!-- Section 2 -->
            <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 space-y-2.5">
                <h4 class="text-[11px] font-bold uppercase text-amber-700 tracking-wider">B. Identitas Instrumen & Penugasan</h4>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Nama Alat *</label>
                        <input type="text" name="instrument_name" required placeholder="Contoh: Digital Pressure Gauge" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Ruang Lingkup (Scope) *</label>
                        <select name="scope_code" required class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-mono focus:border-[#C81E26]">
                            <?php foreach ($scopes as $code => $sc): ?>
                                <option value="<?= $code ?>">[<?= $code ?>] <?= htmlspecialchars($sc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Merk / Brand</label>
                        <input type="text" name="brand" placeholder="Contoh: Fluke / Mitutoyo" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Model / Tipe</label>
                        <input type="text" name="model_type" placeholder="Contoh: 700G07" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Nomor Seri (SN) *</label>
                        <input type="text" name="serial_number" required placeholder="Contoh: SN-829104" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 font-mono focus:border-[#C81E26]">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Rentang Kapasitas (Range)</label>
                        <input type="text" name="capacity_range" placeholder="Contoh: 0 - 10 bar / 0 - 150 mm" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Resolusi</label>
                        <input type="text" name="resolution" placeholder="Contoh: 0.001 bar / 0.01 mm" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Teknisi Penanggung Jawab</label>
                    <select name="technician_name" class="w-full bg-white border border-gray-300 rounded-lg px-3 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                        <option value="Ahmad Farhan, A.Md.">Ahmad Farhan, A.Md. (Teknisi Tekanan & Massa)</option>
                        <option value="Budi Santoso, S.T.">Budi Santoso, S.T. (Teknisi Massa & Dimensi)</option>
                        <option value="Dedi Kurniawan, A.Md.">Dedi Kurniawan, A.Md. (Teknisi Suhu & Kelistrikan)</option>
                        <option value="Rizky Pratama, S.T.">Rizky Pratama, S.T. (Teknisi Volumetrik & Torsi)</option>
                    </select>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-gray-100">
                <button type="button" onclick="closeModal('create-order-modal')" class="px-4 py-2 rounded-lg bg-gray-100 text-slate-700 font-semibold hover:bg-gray-200">Batal</button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#A8141B] text-white px-5 py-2 rounded-lg font-semibold shadow-sm">
                    Simpan & Terbitkan SPK
                </button>
            </div>
        </form>

    </div>
</div>

<script>
function fillSampleOrder(type) {
    const form = document.getElementById('create-order-form');
    if (!form) return;

    if (type === 'pressure') {
        form.elements['customer_name'].value = 'PT Pertamina Hulu Rokan';
        form.elements['customer_contact'].value = 'Bpk. Hendro (0812-7788-9900)';
        form.elements['service_type'].value = 'ON_SITE';
        form.elements['customer_address'].value = 'Kawasan Lapangan Duri, Riau';
        form.elements['instrument_name'].value = 'Pressure Gauge High Precision';
        form.elements['scope_code'].value = 'P';
        form.elements['brand'].value = 'WIKA Instrument';
        form.elements['model_type'].value = '232.50.100';
        form.elements['serial_number'].value = 'WK-' + Math.floor(100000 + Math.random() * 900000);
        form.elements['capacity_range'].value = '0 - 25 bar';
        form.elements['resolution'].value = '0.01 bar';
        form.elements['technician_name'].value = 'Ahmad Farhan, A.Md.';
    } else if (type === 'temperature') {
        form.elements['customer_name'].value = 'PT Kalbe Farma Tbk';
        form.elements['customer_contact'].value = 'Ibu Dewi (0813-1122-3344)';
        form.elements['service_type'].value = 'IN_LAB';
        form.elements['customer_address'].value = 'Delta Silicon II Cikarang';
        form.elements['instrument_name'].value = 'Digital Thermometer & RTD Probe';
        form.elements['scope_code'].value = 'S';
        form.elements['brand'].value = 'Fluke Calibration';
        form.elements['model_type'].value = 'Fluke 1523';
        form.elements['serial_number'].value = 'FLK-' + Math.floor(100000 + Math.random() * 900000);
        form.elements['capacity_range'].value = '-50 °C s/d 200 °C';
        form.elements['resolution'].value = '0.001 °C';
        form.elements['technician_name'].value = 'Dedi Kurniawan, A.Md.';
    } else if (type === 'mass') {
        form.elements['customer_name'].value = 'PT Mayora Indah Tbk';
        form.elements['customer_contact'].value = 'Bpk. Agus (0815-5566-7788)';
        form.elements['service_type'].value = 'IN_LAB';
        form.elements['customer_address'].value = 'Jl. Telesonic Jatake, Tangerang';
        form.elements['instrument_name'].value = 'Precision Analytical Balance';
        form.elements['scope_code'].value = 'M';
        form.elements['brand'].value = 'Sartorius';
        form.elements['model_type'].value = 'Entris II';
        form.elements['serial_number'].value = 'SAR-' + Math.floor(100000 + Math.random() * 900000);
        form.elements['capacity_range'].value = '0 - 220 g';
        form.elements['resolution'].value = '0.0001 g';
        form.elements['technician_name'].value = 'Budi Santoso, S.T.';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
