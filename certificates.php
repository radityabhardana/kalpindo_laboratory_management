<?php
/**
 * Modul Bagian Sertifikat (Certificate Administration)
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Structured Enterprise Operational Module
 */

declare(strict_types=1);

$pageTitle = '3. Bagian Pengurus Sertifikat';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$db = getDbConnection();
$scopes = getScopeList();

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

            $validUntil = date('Y-m-d', strtotime("+{$validMonths} months", strtotime($issueDate)));

            $scopeCode = $inst['scope_code'];
            $genResult = generateCertificateNumber($db, $scopeCode, $issueDate, '00');
            $certNumber = $genResult['certificate_number'];

            $db->beginTransaction();

            $stmtCert = $db->prepare("
                INSERT INTO certificates (
                    instrument_id, certificate_number, scope_code,
                    year_prefix, month_prefix, sequence_number, revision_number,
                    issue_date, valid_until, technical_manager, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ISSUED')
            ");
            $stmtCert->execute([
                $instrumentId, $certNumber, $scopeCode,
                $genResult['year_prefix'], $genResult['month_prefix'],
                $genResult['sequence_number'], $genResult['revision_number'],
                $issueDate, $validUntil, $technicalManager
            ]);

            $db->exec("UPDATE instruments SET status = 'CERTIFIED' WHERE id = {$instrumentId}");
            
            $orderId = (int)$inst['order_id'];
            $uncompletedCount = (int)$db->query("SELECT COUNT(*) FROM instruments WHERE order_id = {$orderId} AND status != 'CERTIFIED'")->fetchColumn();
            if ($uncompletedCount === 0) {
                $db->exec("UPDATE orders SET status = 'COMPLETED' WHERE id = {$orderId}");
            }

            $db->commit();

            setFlash('success', "Sertifikat Nomor {$certNumber} berhasil diterbitkan dan siap dicetak!");
            header("Location: print_certificate.php?cert=" . urlencode($certNumber));
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

            setFlash('success', "Nomor Sertifikat berhasil direvisi menjadi {$newCertNumber}!");
            header('Location: certificates.php');
            exit;

        } catch (Exception $e) {
            setFlash('error', 'Gagal revisi: ' . $e->getMessage());
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

// 2. Daftar sertifikat yang sudah terbit
$issuedCertificates = $db->query("
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
    ORDER BY c.id DESC
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header Control Bar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
            <span>Portal Karyawan</span>
            <span>/</span>
            <span>Bagian Sertifikat</span>
            <span>/</span>
            <span class="text-slate-800 font-semibold">Penomoran & Penerbitan</span>
        </div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Administrasi & Penerbitan Sertifikat Kalibrasi</h1>
    </div>
</div>

<!-- SECTION 1: Antrean Berkas Masuk dari Teknisi (Menunggu Penomoran) -->
<div class="bg-white rounded-2xl border-2 border-red-200 shadow-2xs p-5 sm:p-6 mb-6 bg-red-50/15">
    <div class="flex items-center justify-between mb-4 border-b border-red-100 pb-3">
        <div class="flex items-center gap-2.5">
            <span class="w-2.5 h-2.5 rounded-full bg-[#C81E26] animate-pulse"></span>
            <h2 class="text-base font-bold text-slate-900">Antrean Lembar Kerja Masuk dari Teknisi</h2>
            <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-[#C81E26] text-white">
                <?= count($incomingQueue) ?> Berkas Menunggu Nomor
            </span>
        </div>
    </div>

    <?php if (empty($incomingQueue)): ?>
        <div class="p-6 text-center bg-white rounded-xl border border-gray-200 text-gray-400 text-xs">
            <i class="ph-fill ph-check-circle text-emerald-500 text-2xl mx-auto mb-1 block"></i>
            Semua lembar kerja teknisi telah selesai diproses dan dibuatkan nomor sertifikat resminya.
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-gray-50 text-gray-600 uppercase text-[10px] tracking-wider border-y border-gray-200">
                    <tr>
                        <th class="py-2.5 px-3">Alat & Pelanggan</th>
                        <th class="py-2.5 px-3">Ruang Lingkup</th>
                        <th class="py-2.5 px-3">Teknisi & Tgl Masuk</th>
                        <th class="py-2.5 px-3">Kondisi Suhu/RH</th>
                        <th class="py-2.5 px-3">Standar Acuan</th>
                        <th class="py-2.5 px-3 text-right">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <?php foreach ($incomingQueue as $item): ?>
                        <?php 
                            $sc = $scopes[$item['scope_code']] ?? ['name' => $item['scope_code'], 'badge_class' => ''];
                            $predicted = generateCertificateNumber($db, $item['scope_code'], date('Y-m-d'));
                        ?>
                        <tr class="hover:bg-red-50/30 transition-colors">
                            <td class="py-3 px-3">
                                <span class="font-bold text-slate-900"><?= htmlspecialchars($item['name']) ?></span>
                                <p class="text-[11px] text-gray-500"><?= htmlspecialchars($item['brand']) ?> • <?= htmlspecialchars($item['model_type']) ?></p>
                                <p class="font-mono text-[10px] text-gray-400">SN: <?= htmlspecialchars($item['serial_number']) ?> • <?= htmlspecialchars($item['customer_name']) ?></p>
                            </td>

                            <td class="py-3 px-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold border <?= $sc['badge_class'] ?>">
                                    [<?= htmlspecialchars($item['scope_code']) ?>] <?= htmlspecialchars(explode(' ', $sc['name'])[0]) ?>
                                </span>
                            </td>

                            <td class="py-3 px-3">
                                <p class="font-semibold text-slate-800"><?= htmlspecialchars($item['technician_name']) ?></p>
                                <p class="text-gray-400 font-mono text-[10px] mt-0.5"><?= htmlspecialchars($item['submitted_at']) ?></p>
                            </td>

                            <td class="py-3 px-3 font-mono text-slate-700">
                                <span><?= $item['temperature'] ?> °C</span> • <span><?= $item['humidity'] ?> %RH</span>
                            </td>

                            <td class="py-3 px-3 text-gray-600 max-w-[200px] truncate" title="<?= htmlspecialchars($item['standard_calibrator']) ?>">
                                <?= htmlspecialchars($item['standard_calibrator']) ?>
                            </td>

                            <td class="py-3 px-3 text-right whitespace-nowrap">
                                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                    <button onclick="openGenerateModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>', '<?= htmlspecialchars(addslashes($item['customer_name'])) ?>', '<?= $item['scope_code'] ?>', '<?= $predicted['certificate_number'] ?>')" class="bg-[#C81E26] hover:bg-[#A8141B] text-white px-3.5 py-1.5 rounded-lg font-semibold text-xs shadow-2xs inline-flex items-center gap-1 transition-all">
                                        <i class="ph-bold ph-plus-circle"></i>
                                        <span>Buat No. (<?= $predicted['certificate_number'] ?>)</span>
                                    </button>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded-md bg-gray-100 text-gray-500 font-medium text-xs inline-flex items-center gap-1 border border-gray-200">
                                        <i class="ph-bold ph-lock-key text-gray-400"></i>
                                        <span>Mode View (Admin Sertifikat)</span>
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
<div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-5 sm:p-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4 border-b border-gray-100 pb-3">
        <div>
            <h2 class="text-base font-bold text-slate-900">Arsip Sertifikat Kalibrasi Resmi Terbit</h2>
            <p class="text-xs text-gray-500">Database sertifikat terbit standar ISO/IEC 17025 siap cetak dan terintegrasi QR code</p>
        </div>
        <span class="text-xs font-mono font-bold text-[#C81E26] bg-red-50 px-3 py-1 rounded-full border border-red-200">
            Total: <?= count($issuedCertificates) ?> Sertifikat Terbit
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs min-w-[850px]">
            <thead class="bg-gray-50 text-gray-600 uppercase text-[10px] tracking-wider border-y border-gray-200">
                <tr>
                    <th class="py-2.5 px-3">Nomor Sertifikat</th>
                    <th class="py-2.5 px-3">Alat & No. Seri</th>
                    <th class="py-2.5 px-3">Pelanggan</th>
                    <th class="py-2.5 px-3">Tgl Terbit & Kedaluwarsa</th>
                    <th class="py-2.5 px-3">Penandatangan Teknis</th>
                    <th class="py-2.5 px-3">Status</th>
                    <th class="py-2.5 px-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($issuedCertificates)): ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center text-gray-400">Belum ada sertifikat yang diterbitkan.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($issuedCertificates as $cert): ?>
                    <?php 
                        $decoded = decodeCertificateNumber($cert['certificate_number']);
                        $scope = $scopes[$cert['scope_code']] ?? ['name' => $cert['scope_code'], 'badge_class' => ''];
                    ?>
                    <tr class="hover:bg-slate-50/80 transition-colors">
                        <td class="py-3 px-3">
                            <span class="font-mono text-xs font-black text-[#C81E26] bg-red-50 px-2 py-0.5 rounded border border-red-200 inline-block">
                                <?= htmlspecialchars($cert['certificate_number']) ?>
                            </span>
                            <div class="text-[10px] text-gray-500 mt-0.5">
                                Lingkup: <strong class="text-slate-800"><?= htmlspecialchars($scope['name']) ?></strong>
                            </div>
                        </td>

                        <td class="py-3 px-3">
                            <p class="font-bold text-slate-900"><?= htmlspecialchars($cert['instrument_name']) ?></p>
                            <p class="text-[11px] text-gray-500"><?= htmlspecialchars($cert['brand']) ?> <?= htmlspecialchars($cert['model_type']) ?></p>
                            <p class="font-mono text-[10px] text-gray-400">SN: <?= htmlspecialchars($cert['serial_number']) ?></p>
                        </td>

                        <td class="py-3 px-3">
                            <p class="font-semibold text-slate-800"><?= htmlspecialchars($cert['customer_name']) ?></p>
                            <p class="font-mono text-[10px] text-gray-400 mt-0.5">Order: <?= htmlspecialchars($cert['order_number']) ?></p>
                        </td>

                        <td class="py-3 px-3 font-mono">
                            <p class="text-slate-700">Terbit: <?= formatIndonesianDate($cert['issue_date']) ?></p>
                            <p class="text-gray-400 text-[10px] mt-0.5">Berlaku s/d: <?= formatIndonesianDate($cert['valid_until']) ?></p>
                        </td>

                        <td class="py-3 px-3">
                            <p class="font-semibold text-slate-800"><?= htmlspecialchars($cert['technical_manager']) ?></p>
                            <span class="text-[10px] text-gray-400">Manajer Teknis</span>
                        </td>

                        <td class="py-3 px-3">
                            <?php if ($cert['revision_number'] === '00'): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    Rev 00 (Asli)
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-800 border border-amber-200">
                                    Revisi -<?= htmlspecialchars($cert['revision_number']) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="py-3 px-3 text-right whitespace-nowrap">
                            <div class="flex items-center justify-end gap-1.5">
                                <a href="print_certificate.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" class="px-2.5 py-1 rounded-md text-xs font-semibold bg-white text-slate-700 hover:bg-gray-50 border border-gray-300 shadow-2xs inline-flex items-center gap-1">
                                    <i class="ph-bold ph-printer text-[#C81E26]"></i>
                                    <span>Cetak A4</span>
                                </a>

                                <a href="verify.php?cert=<?= urlencode($cert['certificate_number']) ?>" target="_blank" title="Cek Halaman Verifikasi QR Code" class="p-1.5 rounded-md bg-gray-100 text-gray-600 hover:text-slate-900 hover:bg-gray-200">
                                    <i class="ph-bold ph-qr-code text-sm"></i>
                                </a>

                                <?php if (hasRole(['SUPER_ADMIN', 'CERT_ADMIN'])): ?>
                                    <button onclick="openRevisionModal(<?= $cert['id'] ?>, '<?= htmlspecialchars(addslashes($cert['certificate_number'])) ?>')" title="Ajukan Revisi Nomor Sertifikat" class="p-1.5 rounded-md bg-gray-100 text-gray-600 hover:text-amber-700 hover:bg-amber-100">
                                        <i class="ph-bold ph-pencil-line text-sm"></i>
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
    <div class="bg-white rounded-2xl border border-gray-200 w-full max-w-lg p-6 shadow-xl text-xs">
        
        <div class="flex items-center justify-between border-b border-gray-100 pb-3 mb-4">
            <div class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-[#C81E26] text-white flex items-center justify-center font-bold text-sm">
                    <i class="ph-bold ph-certificate"></i>
                </span>
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Generator Nomor Sertifikat Otomatis</h3>
                    <p class="text-gray-400 text-[11px]">Bagian Pengurus Sertifikat Kalibrasi PT Kalpindo</p>
                </div>
            </div>
            <button onclick="closeModal('generate-cert-modal')" class="text-gray-400 hover:text-gray-700 text-xl font-bold">&times;</button>
        </div>

        <form action="certificates.php" method="POST" class="space-y-3.5">
            <input type="hidden" name="action" value="generate_certificate">
            <input type="hidden" id="modal-inst-id" name="instrument_id" value="">

            <div class="bg-gray-50 p-3 rounded-xl border border-gray-200 space-y-1 text-[11px]">
                <div class="flex items-center justify-between">
                    <span class="text-gray-500">Nama Alat:</span>
                    <strong id="modal-inst-name" class="text-slate-900 font-bold"></strong>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-500">Customer:</span>
                    <span id="modal-customer-name" class="text-slate-700 font-semibold"></span>
                </div>
            </div>

            <!-- Preview Nomor -->
            <div class="bg-red-50 p-4 rounded-xl border border-red-200 text-center">
                <span class="text-[10px] font-bold text-[#C81E26] uppercase tracking-wider block mb-0.5">
                    Nomor Sertifikat Diterbitkan:
                </span>
                <div id="modal-cert-preview" class="font-mono text-2xl font-black text-[#C81E26] tracking-wider">
                    2605P0012-00
                </div>
                <p class="text-[11px] text-gray-500 mt-1">
                    Format: Tahun (26) + Bulan (05) + Scope (P) + No. Urut (0012) + Revisi (-00)
                </p>
            </div>

            <div class="space-y-2.5">
                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Tanggal Terbit *</label>
                        <input type="date" name="issue_date" required value="<?= date('Y-m-d') ?>" class="w-full bg-white border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Masa Berlaku</label>
                        <select name="valid_months" class="w-full bg-white border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                            <option value="12">12 Bulan (1 Tahun)</option>
                            <option value="6">6 Bulan</option>
                            <option value="24">24 Bulan</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Penandatangan Manajer Teknis</label>
                    <input type="text" name="technical_manager" value="Ir. Hendra Wijaya, M.T." class="w-full bg-white border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]">
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-gray-100">
                <button type="button" onclick="closeModal('generate-cert-modal')" class="px-3.5 py-1.5 rounded-lg bg-gray-100 text-slate-700 font-semibold hover:bg-gray-200">Batal</button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#A8141B] text-white px-4 py-1.5 rounded-lg font-semibold shadow-sm">
                    Terbitkan Sertifikat &rarr;
                </button>
            </div>
        </form>

    </div>
</div>

<!-- MODAL 2: Revisi Sertifikat -->
<div id="revision-cert-modal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl border border-gray-200 w-full max-w-md p-5 shadow-xl text-xs">
        
        <div class="flex items-center justify-between border-b border-gray-100 pb-2.5 mb-3">
            <h3 class="text-sm font-bold text-slate-900">Revisi / Amandemen Sertifikat</h3>
            <button onclick="closeModal('revision-cert-modal')" class="text-gray-400 hover:text-gray-700 text-lg font-bold">&times;</button>
        </div>

        <form action="certificates.php" method="POST" class="space-y-3">
            <input type="hidden" name="action" value="create_revision">
            <input type="hidden" id="rev-cert-id" name="certificate_id" value="">

            <p class="text-slate-600">
                Sertifikat saat ini: <strong id="rev-cert-num" class="font-mono text-[#C81E26]"></strong>
            </p>
            <p class="text-gray-500 text-[11px]">
                Revisi akan menaikkan akhiran nomor sertifikat (misal <code class="font-bold text-slate-800">-00</code> &rarr; <code class="font-bold text-[#C81E26]">-01</code>) sesuai klausul amandemen sertifikat ISO/IEC 17025.
            </p>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Catatan / Alasan Revisi *</label>
                <textarea name="revision_notes" required rows="2.5" placeholder="Contoh: Perbaikan kesalahan penulisan alamat atau nama PT customer." class="w-full bg-white border border-gray-300 rounded-lg px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#C81E26]"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2.5 border-t border-gray-100">
                <button type="button" onclick="closeModal('revision-cert-modal')" class="px-3 py-1.5 rounded-lg bg-gray-100 text-slate-700 font-semibold hover:bg-gray-200">Batal</button>
                <button type="submit" class="bg-[#C81E26] text-white px-4 py-1.5 rounded-lg font-bold shadow-sm hover:bg-[#A8141B]">Terapkan Revisi</button>
            </div>
        </form>

    </div>
</div>

<script>
function openGenerateModal(instId, instName, customerName, scopeCode, previewNum) {
    document.getElementById('modal-inst-id').value = instId;
    document.getElementById('modal-inst-name').textContent = instName;
    document.getElementById('modal-customer-name').textContent = customerName;
    document.getElementById('modal-cert-preview').textContent = previewNum;
    openModal('generate-cert-modal');
}

function openRevisionModal(certId, certNumber) {
    document.getElementById('rev-cert-id').value = certId;
    document.getElementById('rev-cert-num').textContent = certNumber;
    openModal('revision-cert-modal');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
