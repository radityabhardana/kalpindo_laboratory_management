<?php
/**
 * Modul Manajemen Karyawan & Hak Akses (RBAC)
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Master Administrator Only (SUPER_ADMIN)
 */

declare(strict_types=1);

$pageTitle = 'Kelola Karyawan & Peran';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

// Hanya Master Administrator (SUPER_ADMIN) yang memiliki akses ke halaman ini!
requireRole('SUPER_ADMIN', 'index.php');

$db = getDbConnection();
$allRoles = getAllRoles();
$currentUser = getCurrentUser();

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Tambah Karyawan Baru
    if ($action === 'add_user') {
        try {
            $username = strtolower(trim($_POST['username'] ?? ''));
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $department = trim($_POST['department'] ?? 'Operasional Kalibrasi');
            $role = trim($_POST['role'] ?? 'TECHNICIAN');
            $password = $_POST['password'] ?? 'password123';

            if (empty($username) || strlen($username) < 3) {
                throw new Exception('Username minimal 3 karakter tanpa spasi.');
            }
            if (!preg_match('/^[a-z0-9_.-]+$/i', $username)) {
                throw new Exception('Username hanya boleh berisi huruf, angka, titik, atau garis bawah.');
            }
            if (empty($fullName)) {
                throw new Exception('Nama lengkap karyawan wajib diisi.');
            }
            if (!array_key_exists($role, $allRoles)) {
                throw new Exception('Peran (role) yang dipilih tidak valid.');
            }
            if (strlen($password) < 4) {
                throw new Exception('Kata sandi minimal 4 karakter.');
            }

            // Check if username already exists
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmtCheck->execute([$username]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("Username '{$username}' sudah terdaftar! Harap gunakan username lain.");
            }

            // Avatar Initials
            $parts = explode(' ', $fullName);
            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("
                INSERT INTO users (username, password_hash, full_name, email, role, department, avatar_initials)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$username, $hash, $fullName, $email, $role, $department, $initials]);

            $roleName = $allRoles[$role]['name'];
            setFlash('success', "Karyawan baru '{$fullName}' (@{$username}) berhasil didaftarkan dengan peran {$roleName}!");
            header('Location: users.php');
            exit;

        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
    }

    // 2. Ubah Data & Peran Karyawan (Edit)
    if ($action === 'edit_user') {
        try {
            $userId = (int)($_POST['user_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $role = trim($_POST['role'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                throw new Exception('Data karyawan tidak ditemukan.');
            }

            if (empty($fullName)) {
                throw new Exception('Nama lengkap karyawan tidak boleh kosong.');
            }

            // Proteksi master admin: tidak boleh didowngrade dari SUPER_ADMIN
            if ($targetUser['username'] === 'admin') {
                $role = 'SUPER_ADMIN'; // Tetap SUPER_ADMIN demi keamanan sistem
            } elseif (!array_key_exists($role, $allRoles)) {
                throw new Exception('Peran (role) yang dipilih tidak valid.');
            }

            // Avatar Initials
            $parts = explode(' ', $fullName);
            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

            if (!empty($newPassword)) {
                if (strlen($newPassword) < 4) {
                    throw new Exception('Kata sandi baru minimal 4 karakter.');
                }
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtUpdate = $db->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, department = ?, role = ?, avatar_initials = ?, password_hash = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$fullName, $email, $department, $role, $initials, $newHash, $userId]);
            } else {
                $stmtUpdate = $db->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, department = ?, role = ?, avatar_initials = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$fullName, $email, $department, $role, $initials, $userId]);
            }

            // Update session jika sedang mengedit akun sendiri
            if ($currentUser['id'] === $userId) {
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['department'] = $department;
                $_SESSION['user']['role'] = $role;
                $_SESSION['user']['avatar_initials'] = $initials;
            }

            $roleName = $allRoles[$role]['name'];
            setFlash('success', "Data dan peran akun '{$targetUser['username']}' berhasil diperbarui ke peran {$roleName}.");
            header('Location: users.php');
            exit;

        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
    }

    // 3. Quick Role Change (Langsung Set Role Cepat)
    if ($action === 'quick_set_role') {
        try {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newRole = trim($_POST['new_role'] ?? '');

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                throw new Exception('Karyawan tidak ditemukan.');
            }

            if ($targetUser['username'] === 'admin') {
                throw new Exception('Peran akun Master Administrator tidak dapat diubah demi stabilitas sistem.');
            }

            if (!array_key_exists($newRole, $allRoles)) {
                throw new Exception('Peran tidak valid.');
            }

            $stmtUpdate = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmtUpdate->execute([$newRole, $userId]);

            $roleName = $allRoles[$newRole]['name'];
            setFlash('success', "Peran karyawan {$targetUser['full_name']} (@{$targetUser['username']}) berhasil diubah menjadi: {$roleName}!");
            header('Location: users.php');
            exit;

        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
    }

    // 4. Hapus Karyawan
    if ($action === 'delete_user') {
        try {
            $userId = (int)($_POST['user_id'] ?? 0);

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                throw new Exception('Karyawan tidak ditemukan.');
            }

            if ($targetUser['username'] === 'admin') {
                throw new Exception('Akun Master Administrator tidak dapat dihapus!');
            }

            if ($targetUser['id'] === $currentUser['id']) {
                throw new Exception('Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif digunakan.');
            }

            $stmtDelete = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmtDelete->execute([$userId]);

            setFlash('success', "Akun karyawan '{$targetUser['full_name']}' (@{$targetUser['username']}) berhasil dihapus dari sistem.");
            header('Location: users.php');
            exit;

        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }
    }
}

// Fetch all registered users (Pinned admin first, then ID ascending)
$users = $db->query("
    SELECT * FROM users 
    ORDER BY (username = 'admin') DESC, id ASC
")->fetchAll();

// Statistics
$totalUsers = count($users);
$countAdmin = 0;
$countSales = 0;
$countTech = 0;
$countCert = 0;

foreach ($users as $u) {
    if ($u['role'] === 'SUPER_ADMIN') $countAdmin++;
    elseif ($u['role'] === 'SALES') $countSales++;
    elseif ($u['role'] === 'TECHNICIAN') $countTech++;
    elseif ($u['role'] === 'CERT_ADMIN') $countCert++;
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header Breadcrumb & Controls -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
            <span>Portal Karyawan</span>
            <i class="ph-bold ph-caret-right text-[10px] text-gray-400"></i>
            <span>Administrator</span>
            <i class="ph-bold ph-caret-right text-[10px] text-gray-400"></i>
            <span class="text-slate-800 font-semibold">Kelola Karyawan & Peran</span>
        </div>
        <h1 class="text-2xl font-black text-slate-900 tracking-tight">Manajemen Karyawan & Hak Akses</h1>
        <p class="text-xs text-slate-500 mt-0.5">Daftarkan akun resmi karyawan dan tentukan peran (Super Admin, Sales, Teknisi, atau Sertifikat).</p>
    </div>

    <!-- Action: Tambah Karyawan Baru Button -->
    <div class="flex items-center gap-2.5">
        <button type="button" onclick="openModal('modal-add-user')" class="bg-[#C81E26] hover:bg-[#A8141B] active:scale-[0.98] text-white px-4 py-2.5 rounded-xl font-bold text-xs flex items-center gap-2 shadow-sm transition-all">
            <i class="ph-bold ph-user-plus text-base"></i>
            <span>Tambah Karyawan Baru</span>
        </button>
    </div>
</div>

<!-- Role Statistics Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5 mb-6">
    
    <!-- 1. Total Karyawan -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
        <div class="flex items-center justify-between">
            <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider">Total Karyawan</span>
            <div class="w-8 h-8 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center text-base">
                <i class="ph-bold ph-users-three"></i>
            </div>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
            <span class="text-2xl font-black text-slate-900"><?= $totalUsers ?></span>
            <span class="text-[10px] text-slate-400 font-medium">Akun Aktif</span>
        </div>
    </div>

    <!-- 2. Super Admin -->
    <div class="bg-white p-4 rounded-2xl border border-purple-200 shadow-2xs">
        <div class="flex items-center justify-between">
            <span class="text-[11px] font-bold text-purple-700 uppercase tracking-wider">Super Admin</span>
            <div class="w-8 h-8 rounded-lg bg-purple-50 text-purple-700 flex items-center justify-center text-base border border-purple-200">
                <i class="ph-bold ph-shield-check"></i>
            </div>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
            <span class="text-2xl font-black text-purple-950"><?= $countAdmin ?></span>
            <span class="text-[10px] text-purple-600 font-medium">Akses Penuh</span>
        </div>
    </div>

    <!-- 3. Sales -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
        <div class="flex items-center justify-between">
            <span class="text-[11px] font-bold text-slate-700 uppercase tracking-wider">Divisi Sales</span>
            <div class="w-8 h-8 rounded-lg bg-slate-100 text-slate-800 flex items-center justify-center text-base border border-slate-200">
                <i class="ph-bold ph-clipboard-text"></i>
            </div>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
            <span class="text-2xl font-black text-slate-900"><?= $countSales ?></span>
            <span class="text-[10px] text-slate-500 font-medium">Order SPK</span>
        </div>
    </div>

    <!-- 4. Teknisi -->
    <div class="bg-white p-4 rounded-2xl border border-blue-200 shadow-2xs">
        <div class="flex items-center justify-between">
            <span class="text-[11px] font-bold text-blue-700 uppercase tracking-wider">Teknisi Kalibrasi</span>
            <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center text-base border border-blue-200">
                <i class="ph-bold ph-wrench"></i>
            </div>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
            <span class="text-2xl font-black text-blue-950"><?= $countTech ?></span>
            <span class="text-[10px] text-blue-600 font-medium">Lembar Kerja</span>
        </div>
    </div>

    <!-- 5. Bagian Sertifikat -->
    <div class="bg-white p-4 rounded-2xl border border-red-200 shadow-2xs col-span-2 sm:col-span-1">
        <div class="flex items-center justify-between">
            <span class="text-[11px] font-bold text-[#C81E26] uppercase tracking-wider">Pengurus Sertifikat</span>
            <div class="w-8 h-8 rounded-lg bg-red-50 text-[#C81E26] flex items-center justify-center text-base border border-red-200">
                <i class="ph-bold ph-certificate"></i>
            </div>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
            <span class="text-2xl font-black text-red-950"><?= $countCert ?></span>
            <span class="text-[10px] text-[#C81E26] font-medium">Format Sertifikat</span>
        </div>
    </div>

</div>

<!-- Filter & Search Toolbar -->
<div class="bg-white rounded-2xl border border-slate-200 p-4 mb-5 shadow-2xs flex flex-col md:flex-row items-center justify-between gap-3">
    
    <!-- Real-time Filter Buttons -->
    <div class="flex items-center gap-1.5 overflow-x-auto w-full md:w-auto pb-1 md:pb-0" id="role-filter-group">
        <button type="button" onclick="filterUserRole('ALL', this)" class="role-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold border transition-all bg-slate-900 text-white border-slate-900 shadow-2xs">
            Semua (<?= $totalUsers ?>)
        </button>
        <button type="button" onclick="filterUserRole('SUPER_ADMIN', this)" class="role-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition-all">
            Admin (<?= $countAdmin ?>)
        </button>
        <button type="button" onclick="filterUserRole('SALES', this)" class="role-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition-all">
            Sales (<?= $countSales ?>)
        </button>
        <button type="button" onclick="filterUserRole('TECHNICIAN', this)" class="role-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition-all">
            Teknisi (<?= $countTech ?>)
        </button>
        <button type="button" onclick="filterUserRole('CERT_ADMIN', this)" class="role-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition-all">
            Sertifikat (<?= $countCert ?>)
        </button>
    </div>

    <!-- Search Input -->
    <div class="w-full md:w-72 relative">
        <i class="ph-bold ph-magnifying-glass absolute left-3 top-2.5 text-gray-400 text-sm"></i>
        <input type="text" id="user-search-input" onkeyup="searchUsers()" placeholder="Cari nama, username, email..." class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-9 pr-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-[#C81E26] focus:bg-white transition-all">
    </div>

</div>

<!-- Table of Registered Employees -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-700" id="users-table">
            <thead class="bg-slate-50/80 border-b border-slate-200 font-bold text-slate-600 uppercase text-[10px] tracking-wider">
                <tr>
                    <th class="py-3 px-4">Karyawan Laboratorium</th>
                    <th class="py-3 px-4">Username Login</th>
                    <th class="py-3 px-4">Departemen / Divisi</th>
                    <th class="py-3 px-4">Peran Sistem (Role)</th>
                    <th class="py-3 px-4">Terdaftar</th>
                    <th class="py-3 px-4 text-right">Aksi Manajemen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($users as $u): 
                    $roleInfo = $allRoles[$u['role']] ?? [
                        'name' => $u['role'],
                        'short' => $u['role'],
                        'badge_class' => 'bg-slate-100 text-slate-700 border-slate-200',
                        'icon' => 'ph-user'
                    ];
                    $isMasterAdmin = ($u['username'] === 'admin');
                    $isSelf = ($u['id'] === $currentUser['id']);
                ?>
                    <tr class="user-row hover:bg-slate-50/80 transition-colors" data-role="<?= htmlspecialchars($u['role']) ?>" data-search="<?= htmlspecialchars(strtolower($u['full_name'] . ' ' . $u['username'] . ' ' . $u['email'] . ' ' . $u['department'])) ?>">
                        
                        <!-- 1. Karyawan -->
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl flex items-center justify-center font-bold text-xs shrink-0 shadow-2xs <?= $isMasterAdmin ? 'bg-purple-700 text-white' : ($u['role'] === 'SALES' ? 'bg-slate-800 text-white' : ($u['role'] === 'TECHNICIAN' ? 'bg-blue-600 text-white' : 'bg-[#C81E26] text-white')) ?>">
                                    <?= htmlspecialchars($u['avatar_initials'] ?: 'KP') ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-slate-900 truncate"><?= htmlspecialchars($u['full_name']) ?></span>
                                        <?php if ($isMasterAdmin): ?>
                                            <span class="px-1.5 py-0.2 bg-purple-100 text-purple-800 border border-purple-200 rounded text-[9px] font-black tracking-wider uppercase">Master</span>
                                        <?php endif; ?>
                                        <?php if ($isSelf): ?>
                                            <span class="px-1.5 py-0.2 bg-emerald-100 text-emerald-800 border border-emerald-200 rounded text-[9px] font-bold">Anda</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-[11px] text-gray-400 truncate"><?= htmlspecialchars($u['email'] ?: 'Belum diisi') ?></p>
                                </div>
                            </div>
                        </td>

                        <!-- 2. Username -->
                        <td class="py-3 px-4">
                            <span class="font-mono text-xs font-semibold px-2 py-1 rounded-lg bg-slate-100 text-slate-800 border border-slate-200 inline-block">
                                <?= htmlspecialchars($u['username']) ?>
                            </span>
                        </td>

                        <!-- 3. Departemen -->
                        <td class="py-3 px-4">
                            <span class="text-xs text-slate-600 font-medium"><?= htmlspecialchars($u['department']) ?></span>
                        </td>

                        <!-- 4. Peran Sistem (Role) + Quick Role Changer -->
                        <td class="py-3 px-4">
                            <?php if ($isMasterAdmin): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold bg-purple-100 text-purple-800 border border-purple-200 shadow-2xs">
                                    <i class="ph-bold ph-shield-check"></i>
                                    <span>Super Admin (Akses Penuh)</span>
                                </span>
                            <?php else: ?>
                                <!-- Quick Select Dropdown Form for instant role change -->
                                <form action="users.php" method="POST" class="inline-flex items-center gap-1.5 m-0" id="quick-role-form-<?= $u['id'] ?>">
                                    <input type="hidden" name="action" value="quick_set_role">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    
                                    <div class="relative inline-block">
                                        <select name="new_role" onchange="this.form.submit()" class="text-xs font-bold pl-2.5 pr-7 py-1 rounded-lg border appearance-none cursor-pointer focus:outline-none focus:ring-1 focus:ring-[#C81E26] shadow-2xs <?= $roleInfo['badge_class'] ?>">
                                            <?php foreach ($allRoles as $rKey => $rVal): ?>
                                                <option value="<?= $rKey ?>" <?= $u['role'] === $rKey ? 'selected' : '' ?>>
                                                    <?= $rVal['short'] ?> - <?= $rVal['name'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <i class="ph-bold ph-caret-down text-[10px] text-gray-500 absolute right-2 top-2.5 pointer-events-none"></i>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </td>

                        <!-- 5. Terdaftar -->
                        <td class="py-3 px-4 text-[11px] text-slate-500 font-mono">
                            <?= !empty($u['created_at']) ? date('d/m/Y', strtotime($u['created_at'])) : '-' ?>
                        </td>

                        <!-- 6. Aksi Manajemen -->
                        <td class="py-3 px-4 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                
                                <!-- Edit & Set Role Modal Button -->
                                <button type="button" 
                                    onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u)) ?>)" 
                                    class="p-1.5 px-2 text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-semibold flex items-center gap-1 transition-all"
                                    title="Ubah Data & Peran Karyawan">
                                    <i class="ph-bold ph-pencil-simple"></i>
                                    <span class="hidden sm:inline">Ubah</span>
                                </button>

                                <!-- Delete Button -->
                                <?php if (!$isMasterAdmin && !$isSelf): ?>
                                    <button type="button" 
                                        onclick="confirmDeleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>', '<?= htmlspecialchars(addslashes($u['username'])) ?>')"
                                        class="p-1.5 px-2 text-red-600 hover:text-red-800 bg-red-50 hover:bg-red-100 border border-red-200 rounded-lg text-xs font-semibold flex items-center gap-1 transition-all"
                                        title="Hapus Karyawan">
                                        <i class="ph-bold ph-trash"></i>
                                        <span class="hidden sm:inline">Hapus</span>
                                    </button>
                                <?php else: ?>
                                    <span class="p-1.5 px-2 text-gray-300 bg-gray-50 rounded-lg text-xs font-medium cursor-not-allowed" title="Akun ini diproteksi dan tidak dapat dihapus">
                                        <i class="ph-bold ph-lock-key"></i>
                                    </span>
                                <?php endif; ?>

                            </div>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Empty State -->
    <div id="users-empty-message" class="hidden p-12 text-center text-slate-400">
        <i class="ph-bold ph-user-circle-slash text-4xl mb-2 text-slate-300"></i>
        <p class="text-sm font-semibold text-slate-600">Tidak ada karyawan yang sesuai filter atau pencarian.</p>
        <p class="text-xs text-slate-400 mt-1">Coba gunakan kata kunci pencarian yang lain.</p>
    </div>

</div>

<!-- ========================================================== -->
<!-- MODAL 1: TAMBAH KARYAWAN BARU                              -->
<!-- ========================================================== -->
<div id="modal-add-user" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-lg w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all">
        
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50/70">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-red-50 text-[#C81E26] border border-red-200 flex items-center justify-center text-base">
                    <i class="ph-bold ph-user-plus"></i>
                </div>
                <div>
                    <h3 class="font-black text-sm text-slate-900">Daftarkan Karyawan Baru</h3>
                    <p class="text-[10px] text-gray-500">Buat akun resmi dan tetapkan peran akses sistem</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('modal-add-user')" class="p-1.5 text-slate-400 hover:text-slate-700 rounded-lg">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <!-- Form -->
        <form action="users.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="action" value="add_user">

            <!-- Nama Lengkap -->
            <div>
                <label for="add_full_name" class="block font-bold text-slate-700 mb-1">Nama Lengkap & Gelar *</label>
                <input type="text" id="add_full_name" name="full_name" required placeholder="Contoh: Raditya Pratama, S.T." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 font-semibold focus:outline-none focus:border-[#C81E26] focus:bg-white transition-all">
            </div>

            <!-- Username & Password Row -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="add_username" class="block font-bold text-slate-700 mb-1">Username Login *</label>
                    <input type="text" id="add_username" name="username" required placeholder="misal: radit" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 font-mono font-bold focus:outline-none focus:border-[#C81E26] focus:bg-white transition-all">
                    <p class="text-[10px] text-gray-400 mt-0.5">Huruf kecil, tanpa spasi</p>
                </div>
                <div>
                    <label for="add_password" class="block font-bold text-slate-700 mb-1">Kata Sandi Awal *</label>
                    <input type="text" id="add_password" name="password" required value="password123" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 font-mono focus:outline-none focus:border-[#C81E26] focus:bg-white transition-all">
                    <p class="text-[10px] text-gray-400 mt-0.5">Default: password123</p>
                </div>
            </div>

            <!-- Email & Departemen Row -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="add_email" class="block font-bold text-slate-700 mb-1">Alamat Email Resmi</label>
                    <input type="email" id="add_email" name="email" placeholder="nama@kalpindo.co.id" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none focus:border-[#C81E26] focus:bg-white transition-all">
                </div>
                <div>
                    <label for="add_department" class="block font-bold text-slate-700 mb-1">Departemen / Divisi</label>
                    <input type="text" id="add_department" name="department" value="Operasional Laboratorium" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none focus:border-[#C81E26] focus:bg-white transition-all">
                </div>
            </div>

            <!-- Peran Sistem (Role Selection) -->
            <div>
                <label for="add_role" class="block font-bold text-slate-700 mb-1.5">Tetapkan Peran Sistem (Role) *</label>
                <select id="add_role" name="role" required class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 font-bold focus:outline-none focus:border-[#C81E26] focus:bg-white transition-all">
                    <?php foreach ($allRoles as $rKey => $rVal): ?>
                        <option value="<?= $rKey ?>" <?= $rKey === 'TECHNICIAN' ? 'selected' : '' ?>>
                            <?= $rVal['name'] ?> — <?= $rVal['desc'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2 p-2.5 bg-slate-50 rounded-xl border border-slate-200 text-[11px] text-slate-600 space-y-1">
                    <p><strong class="text-purple-700">Super Admin:</strong> Wewenang penuh mengatur seluruh sistem & kelola pengguna.</p>
                    <p><strong class="text-slate-800">Sales:</strong> Penerimaan permintaan kalibrasi & pembuatan SPK order.</p>
                    <p><strong class="text-blue-700">Teknisi:</strong> Pengerjaan kalibrasi alat & input formulir worksheet data mentah.</p>
                    <p><strong class="text-[#C81E26]">Sertifikat:</strong> Penomoran sertifikat resmi (YYMMSNNNN-RR) & cetak dokumen.</p>
                </div>
            </div>

            <!-- Buttons -->
            <div class="pt-3 border-t border-slate-200 flex items-center justify-end gap-2.5">
                <button type="button" onclick="closeModal('modal-add-user')" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100 font-semibold text-xs">
                    Batal
                </button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#A8141B] active:scale-[0.98] text-white px-5 py-2.5 rounded-xl font-bold text-xs flex items-center gap-1.5 shadow-sm transition-all">
                    <i class="ph-bold ph-check"></i>
                    <span>Simpan & Daftarkan</span>
                </button>
            </div>

        </form>

    </div>
</div>

<!-- ========================================================== -->
<!-- MODAL 2: UBAH DATA & PERAN KARYAWAN (EDIT USER)            -->
<!-- ========================================================== -->
<div id="modal-edit-user" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-lg w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all">
        
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50/70">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-700 border border-blue-200 flex items-center justify-center text-base">
                    <i class="ph-bold ph-pencil-simple"></i>
                </div>
                <div>
                    <h3 class="font-black text-sm text-slate-900">Ubah Data & Peran Karyawan</h3>
                    <p class="text-[10px] text-gray-500">Perbarui profil karyawan, hak akses, atau reset kata sandi</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('modal-edit-user')" class="p-1.5 text-slate-400 hover:text-slate-700 rounded-lg">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <!-- Form -->
        <form action="users.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" id="edit_user_id" name="user_id" value="">

            <!-- Username Info Card (Read-only) -->
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between">
                <div>
                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-wider block">Username Karyawan</span>
                    <span id="edit_username_badge" class="font-mono text-xs font-bold text-slate-900"></span>
                </div>
                <span id="edit_admin_tag" class="hidden px-2 py-0.5 bg-purple-100 text-purple-800 border border-purple-200 rounded text-[9px] font-black uppercase">
                    Master Administrator
                </span>
            </div>

            <!-- Nama Lengkap -->
            <div>
                <label for="edit_full_name" class="block font-bold text-slate-700 mb-1">Nama Lengkap & Gelar *</label>
                <input type="text" id="edit_full_name" name="full_name" required class="w-full bg-white border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 font-semibold focus:outline-none focus:border-[#C81E26] transition-all">
            </div>

            <!-- Email & Departemen Row -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="edit_email" class="block font-bold text-slate-700 mb-1">Email Resmi</label>
                    <input type="email" id="edit_email" name="email" class="w-full bg-white border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none focus:border-[#C81E26] transition-all">
                </div>
                <div>
                    <label for="edit_department" class="block font-bold text-slate-700 mb-1">Departemen / Divisi</label>
                    <input type="text" id="edit_department" name="department" class="w-full bg-white border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 focus:outline-none focus:border-[#C81E26] transition-all">
                </div>
            </div>

            <!-- Peran Sistem (Role Selection) -->
            <div>
                <label for="edit_role" class="block font-bold text-slate-700 mb-1.5">Peran Sistem (Role Akses) *</label>
                <select id="edit_role" name="role" required class="w-full bg-white border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs text-slate-900 font-bold focus:outline-none focus:border-[#C81E26] transition-all">
                    <?php foreach ($allRoles as $rKey => $rVal): ?>
                        <option value="<?= $rKey ?>">
                            <?= $rVal['name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p id="edit_role_note" class="text-[10px] text-gray-500 mt-1">Admin dapat bebas memindahkan peran karyawan kapan saja sesuai kebutuhan operasional lab.</p>
            </div>

            <!-- Reset Password (Opsional) -->
            <div class="pt-2 border-t border-slate-100">
                <label for="edit_new_password" class="block font-bold text-slate-700 mb-1">
                    Ganti Kata Sandi <span class="text-gray-400 font-normal">(Kosongkan jika tidak ingin mengubah)</span>
                </label>
                <div class="relative">
                    <input type="text" id="edit_new_password" name="new_password" placeholder="Masukkan kata sandi baru..." class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3.5 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-[#C81E26] focus:bg-white transition-all">
                </div>
            </div>

            <!-- Buttons -->
            <div class="pt-3 border-t border-slate-200 flex items-center justify-end gap-2.5">
                <button type="button" onclick="closeModal('modal-edit-user')" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100 font-semibold text-xs">
                    Batal
                </button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#A8141B] active:scale-[0.98] text-white px-5 py-2.5 rounded-xl font-bold text-xs flex items-center gap-1.5 shadow-sm transition-all">
                    <i class="ph-bold ph-check"></i>
                    <span>Simpan Perubahan</span>
                </button>
            </div>

        </form>

    </div>
</div>

<!-- ========================================================== -->
<!-- MODAL 3: KONFIRMASI HAPUS KARYAWAN                         -->
<!-- ========================================================== -->
<div id="modal-delete-user" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all">
        <div class="p-6 text-center">
            <div class="w-12 h-12 rounded-2xl bg-red-50 text-[#C81E26] border border-red-200 flex items-center justify-center text-2xl mx-auto mb-3.5 shadow-2xs">
                <i class="ph-bold ph-warning"></i>
            </div>
            <h3 class="font-black text-base text-slate-900">Hapus Akun Karyawan?</h3>
            <p class="text-xs text-gray-500 mt-1">Apakah Anda yakin ingin menghapus akun karyawan <strong id="delete-user-name" class="text-slate-800"></strong> (<span id="delete-user-username" class="font-mono text-slate-700"></span>)? Tindakan ini tidak dapat dibatalkan.</p>
            
            <form action="users.php" method="POST" class="mt-6 flex items-center justify-center gap-3">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" id="delete_user_id" name="user_id" value="">
                
                <button type="button" onclick="closeModal('modal-delete-user')" class="w-full py-2.5 px-4 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-100 font-bold text-xs">
                    Batal
                </button>
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 active:scale-[0.98] text-white py-2.5 px-4 rounded-xl font-bold text-xs shadow-sm transition-all flex items-center justify-center gap-1.5">
                    <i class="ph-bold ph-trash"></i>
                    <span>Ya, Hapus Akun</span>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Client-side Logic for Users Management -->
<script>
    // Open & Prefill Edit User Modal
    function openEditUserModal(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_username_badge').textContent = '@' + user.username;
        document.getElementById('edit_full_name').value = user.full_name || '';
        document.getElementById('edit_email').value = user.email || '';
        document.getElementById('edit_department').value = user.department || '';
        document.getElementById('edit_role').value = user.role || 'TECHNICIAN';
        document.getElementById('edit_new_password').value = '';

        const adminTag = document.getElementById('edit_admin_tag');
        const roleSelect = document.getElementById('edit_role');
        const roleNote = document.getElementById('edit_role_note');

        if (user.username === 'admin') {
            adminTag.classList.remove('hidden');
            roleSelect.disabled = true;
            roleNote.textContent = 'Akun Master Administrator tidak dapat diubah perannya demi stabilitas sistem.';
        } else {
            adminTag.classList.add('hidden');
            roleSelect.disabled = false;
            roleNote.textContent = 'Admin dapat bebas memindahkan peran karyawan kapan saja sesuai kebutuhan operasional lab.';
        }

        openModal('modal-edit-user');
    }

    // Confirm Delete Dialog
    function confirmDeleteUser(id, name, username) {
        document.getElementById('delete_user_id').value = id;
        document.getElementById('delete-user-name').textContent = name;
        document.getElementById('delete-user-username').textContent = '@' + username;
        openModal('modal-delete-user');
    }

    // Role Filter Function
    function filterUserRole(roleKey, btnElement) {
        // Update button styles
        document.querySelectorAll('.role-filter-btn').forEach(btn => {
            btn.className = 'role-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition-all';
        });
        btnElement.className = 'role-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold border transition-all bg-slate-900 text-white border-slate-900 shadow-2xs';

        const rows = document.querySelectorAll('.user-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const rowRole = row.dataset.role;
            const searchMatch = checkSearchMatch(row);

            if ((roleKey === 'ALL' || rowRole === roleKey) && searchMatch) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        toggleEmptyMessage(visibleCount);
    }

    // Search input handler
    function searchUsers() {
        const query = document.getElementById('user-search-input').value.toLowerCase().trim();
        const activeFilterBtn = document.querySelector('.role-filter-btn.bg-slate-900');
        let activeRole = 'ALL';
        if (activeFilterBtn && activeFilterBtn.textContent.includes('Admin')) activeRole = 'SUPER_ADMIN';
        else if (activeFilterBtn && activeFilterBtn.textContent.includes('Sales')) activeRole = 'SALES';
        else if (activeFilterBtn && activeFilterBtn.textContent.includes('Teknisi')) activeRole = 'TECHNICIAN';
        else if (activeFilterBtn && activeFilterBtn.textContent.includes('Sertifikat')) activeRole = 'CERT_ADMIN';

        const rows = document.querySelectorAll('.user-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const rowRole = row.dataset.role;
            const searchData = row.dataset.search || '';
            const matchQuery = !query || searchData.includes(query);
            const matchRole = (activeRole === 'ALL' || rowRole === activeRole);

            if (matchQuery && matchRole) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        toggleEmptyMessage(visibleCount);
    }

    function checkSearchMatch(row) {
        const query = document.getElementById('user-search-input').value.toLowerCase().trim();
        if (!query) return true;
        const searchData = row.dataset.search || '';
        return searchData.includes(query);
    }

    function toggleEmptyMessage(count) {
        const emptyMsg = document.getElementById('users-empty-message');
        if (count === 0) {
            emptyMsg.classList.remove('hidden');
        } else {
            emptyMsg.classList.add('hidden');
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
