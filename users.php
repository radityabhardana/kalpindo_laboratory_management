<?php
/**
 * Modul Manajemen Karyawan & Hak Akses (RBAC)
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Clean Modern Enterprise User Management
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
            if ($username === 'admin') {
                throw new Exception("Username 'admin' dicadangkan khusus untuk Akun Master Setup Sistem dan tidak dapat didaftarkan sebagai akun karyawan.");
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
            setFlash('success', "Karyawan baru '{$fullName}' (@{$username}) berhasil didaftarkan dengan peran {$roleName}.");
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
            setFlash('success', "Peran karyawan {$targetUser['full_name']} (@{$targetUser['username']}) berhasil diubah menjadi: {$roleName}.");
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

// 1. Akun Master Setup Sistem (Built-in Root, Bukan Karyawan)
$systemAdmin = $db->query("SELECT * FROM users WHERE username = 'admin' LIMIT 1")->fetch();

// 2. Daftar Karyawan Terdaftar (Seluruh personil laboratorium selain akun setup)
$employees = $db->query("
    SELECT * FROM users 
    WHERE username != 'admin' 
    ORDER BY id ASC
")->fetchAll();

// Statistik Karyawan
$totalEmployees = count($employees);
$countAdminEmployees = 0;
$countSales = 0;
$countTech = 0;
$countCert = 0;

foreach ($employees as $u) {
    if ($u['role'] === 'SUPER_ADMIN') $countAdminEmployees++;
    elseif ($u['role'] === 'SALES') $countSales++;
    elseif ($u['role'] === 'TECHNICIAN') $countTech++;
    elseif ($u['role'] === 'CERT_ADMIN') $countCert++;
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Header Control Bar -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
    <div>
        <h1 class="text-xl font-bold text-slate-900 tracking-tight">Manajemen Akun Karyawan</h1>
        <p class="text-xs text-slate-500 mt-0.5">
            Daftarkan akun staf laboratorium dan tetapkan peran akses operasional (Super Admin, Sales, Teknisi, atau Pengurus Sertifikat).
        </p>
    </div>

    <!-- Action: Tambah Karyawan Baru Button -->
    <div>
        <button type="button" onclick="openModal('modal-add-user')" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-3.5 py-1.5 rounded-lg text-xs font-semibold inline-flex items-center gap-1.5 shadow-subtle transition-colors">
            <i class="ph-bold ph-plus text-xs"></i>
            <span>+ Tambah Karyawan</span>
        </button>
    </div>
</div>

<!-- Banner: Akun Master Setup Sistem -->
<div class="bg-slate-900 text-white rounded-xl p-4 mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border border-slate-800 shadow-subtle">
    <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-slate-800 text-slate-300 flex items-center justify-center text-sm shrink-0 border border-slate-700">
            <i class="ph-bold ph-gear-six"></i>
        </div>
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xs font-semibold text-white">Akun Master Setup Sistem</h2>
                <span class="text-[10px] text-slate-400 font-mono">(@admin)</span>
            </div>
            <p class="text-[11px] text-slate-400 mt-0.5">
                Akun bawaan sistem berlevel root untuk konfigurasi awal, inisialisasi basis data, dan darurat otorisasi.
            </p>
        </div>
    </div>
    <?php if ($systemAdmin): ?>
        <div class="shrink-0">
            <button type="button" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($systemAdmin)) ?>)" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs font-medium text-slate-200 transition-colors inline-flex items-center gap-1.5">
                <i class="ph-bold ph-key text-xs"></i>
                <span>Ganti Sandi Setup</span>
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Role Statistics Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3.5 mb-6">
    
    <!-- 1. Total Karyawan -->
    <div class="bg-white p-3.5 sm:p-4 rounded-xl border border-slate-200/80 shadow-subtle">
        <span class="text-[11px] font-medium text-slate-500 uppercase tracking-wider block">Total Karyawan</span>
        <div class="mt-1 flex items-baseline gap-2">
            <span class="text-2xl font-bold text-slate-900 tracking-tight"><?= $totalEmployees ?></span>
            <span class="text-[11px] text-slate-400">staf aktif</span>
        </div>
    </div>

    <!-- 2. Super Admin Karyawan -->
    <div class="bg-white p-3.5 sm:p-4 rounded-xl border border-slate-200/80 shadow-subtle">
        <span class="text-[11px] font-medium text-slate-500 uppercase tracking-wider block">Super Admin</span>
        <div class="mt-1 flex items-baseline gap-2">
            <span class="text-2xl font-bold text-slate-900 tracking-tight"><?= $countAdminEmployees ?></span>
            <span class="text-[11px] text-slate-400">akun</span>
        </div>
    </div>

    <!-- 3. Sales -->
    <div class="bg-white p-3.5 sm:p-4 rounded-xl border border-slate-200/80 shadow-subtle">
        <span class="text-[11px] font-medium text-slate-500 uppercase tracking-wider block">Divisi Sales</span>
        <div class="mt-1 flex items-baseline gap-2">
            <span class="text-2xl font-bold text-slate-900 tracking-tight"><?= $countSales ?></span>
            <span class="text-[11px] text-slate-400">order SPK</span>
        </div>
    </div>

    <!-- 4. Teknisi -->
    <div class="bg-white p-3.5 sm:p-4 rounded-xl border border-slate-200/80 shadow-subtle">
        <span class="text-[11px] font-medium text-slate-500 uppercase tracking-wider block">Teknisi Lab</span>
        <div class="mt-1 flex items-baseline gap-2">
            <span class="text-2xl font-bold text-slate-900 tracking-tight"><?= $countTech ?></span>
            <span class="text-[11px] text-slate-400">worksheet</span>
        </div>
    </div>

    <!-- 5. Bagian Sertifikat -->
    <div class="bg-white p-3.5 sm:p-4 rounded-xl border border-slate-200/80 shadow-subtle col-span-2 sm:col-span-1">
        <span class="text-[11px] font-medium text-slate-500 uppercase tracking-wider block">Pengurus Sertifikat</span>
        <div class="mt-1 flex items-baseline gap-2">
            <span class="text-2xl font-bold text-slate-900 tracking-tight"><?= $countCert ?></span>
            <span class="text-[11px] text-slate-400">penerbitan</span>
        </div>
    </div>

</div>

<!-- Filter & Search Toolbar -->
<div class="bg-white rounded-xl border border-slate-200/80 p-3 mb-5 shadow-subtle flex flex-col md:flex-row items-center justify-between gap-3">
    
    <!-- Real-time Filter Buttons -->
    <div class="flex items-center gap-1 overflow-x-auto w-full md:w-auto" id="role-filter-group">
        <button type="button" onclick="filterUserRole('ALL', this)" class="role-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors bg-slate-900 text-white">
            Semua (<?= $totalEmployees ?>)
        </button>
        <button type="button" onclick="filterUserRole('SUPER_ADMIN', this)" class="role-filter-btn px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-50 transition-colors">
            Admin (<?= $countAdminEmployees ?>)
        </button>
        <button type="button" onclick="filterUserRole('SALES', this)" class="role-filter-btn px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-50 transition-colors">
            Sales (<?= $countSales ?>)
        </button>
        <button type="button" onclick="filterUserRole('TECHNICIAN', this)" class="role-filter-btn px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-50 transition-colors">
            Teknisi (<?= $countTech ?>)
        </button>
        <button type="button" onclick="filterUserRole('CERT_ADMIN', this)" class="role-filter-btn px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-50 transition-colors">
            Sertifikat (<?= $countCert ?>)
        </button>
    </div>

    <!-- Search Input -->
    <div class="w-full md:w-72 relative">
        <i class="ph-bold ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
        <input type="text" id="user-search-input" onkeyup="searchUsers()" placeholder="Cari nama, username, email..." class="w-full bg-slate-50 border border-slate-300 rounded-lg pl-8 pr-3 py-1.5 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
    </div>

</div>

<!-- Table of Registered Employees -->
<div class="bg-white rounded-xl border border-slate-200/80 shadow-subtle overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-700" id="users-table">
            <thead class="bg-slate-50/75 border-b border-slate-200 font-semibold text-slate-500 uppercase text-[10px] tracking-wider whitespace-nowrap">
                <tr>
                    <th class="py-3 px-4">Karyawan</th>
                    <th class="py-3 px-4">Username</th>
                    <th class="py-3 px-4">Departemen</th>
                    <th class="py-3 px-4">Peran Sistem (Role)</th>
                    <th class="py-3 px-4">Terdaftar</th>
                    <th class="py-3 px-4 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="6" class="py-10 text-center text-slate-400">
                            Belum ada akun karyawan yang terdaftar.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $u): 
                        $isSelf = ($u['id'] === $currentUser['id']);
                    ?>
                        <tr class="user-row hover:bg-slate-50/60 transition-colors" data-role="<?= htmlspecialchars($u['role']) ?>" data-search="<?= htmlspecialchars(strtolower($u['full_name'] . ' ' . $u['username'] . ' ' . $u['email'] . ' ' . $u['department'])) ?>">
                            
                            <!-- 1. Karyawan -->
                            <td class="py-3.5 px-4 align-middle">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center font-semibold text-xs shrink-0 border border-slate-200">
                                        <?= htmlspecialchars($u['avatar_initials'] ?: 'KP') ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-semibold text-slate-900 text-xs truncate"><?= htmlspecialchars($u['full_name']) ?></span>
                                            <?php if ($isSelf): ?>
                                                <span class="px-1.5 py-0.2 bg-slate-100 text-slate-600 rounded text-[9px] font-medium border border-slate-200">Anda</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-400 truncate mt-0.5"><?= htmlspecialchars($u['email'] ?: 'Email belum diisi') ?></p>
                                    </div>
                                </div>
                            </td>

                            <!-- 2. Username -->
                            <td class="py-3.5 px-4 align-middle">
                                <span class="font-mono text-xs text-slate-800">
                                    <?= htmlspecialchars($u['username']) ?>
                                </span>
                            </td>

                            <!-- 3. Departemen -->
                            <td class="py-3.5 px-4 align-middle">
                                <span class="text-xs text-slate-600"><?= htmlspecialchars($u['department']) ?></span>
                            </td>

                            <!-- 4. Peran Sistem (Role) + Quick Role Changer -->
                            <td class="py-3.5 px-4 align-middle">
                                <form action="users.php" method="POST" class="inline-flex items-center gap-1.5 m-0" id="quick-role-form-<?= $u['id'] ?>">
                                    <input type="hidden" name="action" value="quick_set_role">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    
                                    <div class="relative inline-block">
                                        <select name="new_role" onchange="this.form.submit()" class="text-xs font-medium pl-2.5 pr-7 py-1 rounded-lg border border-slate-200 bg-slate-50 text-slate-800 appearance-none cursor-pointer focus:outline-none focus:border-slate-800 transition-colors">
                                            <?php foreach ($allRoles as $rKey => $rVal): ?>
                                                <option value="<?= $rKey ?>" <?= $u['role'] === $rKey ? 'selected' : '' ?>>
                                                    <?= $rVal['name'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <i class="ph-bold ph-caret-down text-[10px] text-slate-400 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                                    </div>
                                </form>
                            </td>

                            <!-- 5. Terdaftar -->
                            <td class="py-3.5 px-4 text-[11px] text-slate-400 font-mono align-middle">
                                <?= !empty($u['created_at']) ? date('d/m/Y', strtotime($u['created_at'])) : '-' ?>
                            </td>

                            <!-- 6. Aksi Manajemen -->
                            <td class="py-3.5 px-4 text-right align-middle">
                                <div class="inline-flex items-center gap-1.5">
                                    
                                    <!-- Edit Button -->
                                    <button type="button" 
                                        onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u)) ?>)" 
                                        class="p-1.5 px-2.5 text-slate-600 hover:text-slate-900 bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg text-xs font-medium transition-colors"
                                        title="Ubah Data & Peran Karyawan">
                                        <i class="ph-bold ph-pencil-simple"></i>
                                        <span class="hidden sm:inline">Edit</span>
                                    </button>

                                    <!-- Delete Button -->
                                    <?php if (!$isSelf): ?>
                                        <button type="button" 
                                            onclick="confirmDeleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>', '<?= htmlspecialchars(addslashes($u['username'])) ?>')"
                                            class="p-1.5 px-2 text-rose-600 hover:text-rose-800 hover:bg-rose-50 border border-transparent rounded-lg text-xs font-medium transition-colors"
                                            title="Hapus Karyawan">
                                            <i class="ph-bold ph-trash"></i>
                                        </button>
                                    <?php endif; ?>

                                </div>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Empty State -->
    <div id="users-empty-message" class="hidden p-10 text-center text-slate-400 text-xs">
        <p class="font-medium text-slate-600">Tidak ada karyawan yang sesuai filter atau pencarian.</p>
        <p class="text-slate-400 mt-1">Coba gunakan kata kunci pencarian yang lain.</p>
    </div>

</div>

<!-- MODAL 1: TAMBAH KARYAWAN BARU -->
<div id="modal-add-user" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-lg w-full shadow-xl border border-slate-200 overflow-hidden">
        
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="font-bold text-base text-slate-900 tracking-tight">Daftarkan Karyawan Baru</h3>
                <p class="text-[11px] text-slate-500 mt-0.5">Registrasi akun staf laboratorium dan tetapkan peran akses operasional.</p>
            </div>
            <button type="button" onclick="closeModal('modal-add-user')" class="text-slate-400 hover:text-slate-700 p-1 rounded-md transition-colors">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <!-- Form -->
        <form action="users.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="action" value="add_user">

            <!-- Nama Lengkap -->
            <div>
                <label for="add_full_name" class="block font-medium text-slate-700 mb-1">Nama Lengkap & Gelar <span class="text-rose-500">*</span></label>
                <input type="text" id="add_full_name" name="full_name" required placeholder="Contoh: Raditya Pratama, S.T." class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
            </div>

            <!-- Username & Password Row -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="add_username" class="block font-medium text-slate-700 mb-1">Username Login <span class="text-rose-500">*</span></label>
                    <input type="text" id="add_username" name="username" required placeholder="misal: radit" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-slate-800 transition-colors">
                    <p class="text-[10px] text-slate-400 mt-0.5">Huruf kecil tanpa spasi</p>
                </div>
                <div>
                    <label for="add_password" class="block font-medium text-slate-700 mb-1">Kata Sandi Awal <span class="text-rose-500">*</span></label>
                    <input type="text" id="add_password" name="password" required value="password123" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-slate-800 transition-colors">
                    <p class="text-[10px] text-slate-400 mt-0.5">Default: password123</p>
                </div>
            </div>

            <!-- Email & Departemen Row -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="add_email" class="block font-medium text-slate-700 mb-1">Alamat Email</label>
                    <input type="email" id="add_email" name="email" placeholder="nama@kalpindo.co.id" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>
                <div>
                    <label for="add_department" class="block font-medium text-slate-700 mb-1">Departemen / Divisi</label>
                    <input type="text" id="add_department" name="department" value="Operasional Laboratorium" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>
            </div>

            <!-- Peran Sistem (Role Selection) -->
            <div>
                <label for="add_role" class="block font-medium text-slate-700 mb-1">Peran Akses (Role) <span class="text-rose-500">*</span></label>
                <select id="add_role" name="role" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    <?php foreach ($allRoles as $rKey => $rVal): ?>
                        <option value="<?= $rKey ?>" <?= $rKey === 'TECHNICIAN' ? 'selected' : '' ?>>
                            <?= $rVal['name'] ?> — <?= $rVal['desc'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Buttons -->
            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modal-add-user')" class="px-3.5 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium text-xs transition-colors">
                    Batal
                </button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-4 py-2 rounded-lg font-semibold text-xs shadow-subtle transition-colors">
                    Simpan & Daftarkan
                </button>
            </div>

        </form>

    </div>
</div>

<!-- MODAL 2: UBAH DATA & PERAN KARYAWAN (EDIT USER) -->
<div id="modal-edit-user" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-lg w-full shadow-xl border border-slate-200 overflow-hidden">
        
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h3 class="font-bold text-base text-slate-900 tracking-tight">Ubah Data & Peran Karyawan</h3>
                <p class="text-[11px] text-slate-500 mt-0.5">Perbarui profil karyawan, hak akses, atau atur ulang kata sandi.</p>
            </div>
            <button type="button" onclick="closeModal('modal-edit-user')" class="text-slate-400 hover:text-slate-700 p-1 rounded-md transition-colors">
                <i class="ph-bold ph-x text-base"></i>
            </button>
        </div>

        <!-- Form -->
        <form action="users.php" method="POST" class="p-6 space-y-4 text-xs">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" id="edit_user_id" name="user_id" value="">

            <!-- Username Info Card -->
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg flex items-center justify-between">
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-medium block">Username Akun</span>
                    <span id="edit_username_badge" class="font-mono text-xs font-semibold text-slate-900"></span>
                </div>
                <span id="edit_admin_tag" class="hidden px-2 py-0.5 bg-slate-200 text-slate-800 rounded text-[10px] font-medium">
                    Root Master
                </span>
            </div>

            <!-- Nama Lengkap -->
            <div>
                <label for="edit_full_name" class="block font-medium text-slate-700 mb-1">Nama Lengkap & Gelar <span class="text-rose-500">*</span></label>
                <input type="text" id="edit_full_name" name="full_name" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
            </div>

            <!-- Email & Departemen Row -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="edit_email" class="block font-medium text-slate-700 mb-1">Alamat Email</label>
                    <input type="email" id="edit_email" name="email" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>
                <div>
                    <label for="edit_department" class="block font-medium text-slate-700 mb-1">Departemen / Divisi</label>
                    <input type="text" id="edit_department" name="department" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                </div>
            </div>

            <!-- Peran Sistem (Role Selection) -->
            <div>
                <label for="edit_role" class="block font-medium text-slate-700 mb-1">Peran Akses (Role) <span class="text-rose-500">*</span></label>
                <select id="edit_role" name="role" required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-800 transition-colors">
                    <?php foreach ($allRoles as $rKey => $rVal): ?>
                        <option value="<?= $rKey ?>">
                            <?= $rVal['name'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Reset Password (Opsional) -->
            <div class="pt-3 border-t border-slate-100">
                <label for="edit_new_password" class="block font-medium text-slate-700 mb-1">
                    Ganti Kata Sandi <span class="text-slate-400 font-normal text-[11px]">(Kosongkan jika tidak diubah)</span>
                </label>
                <input type="text" id="edit_new_password" name="new_password" placeholder="Masukkan kata sandi baru..." class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 font-mono focus:outline-none focus:border-slate-800 transition-colors">
            </div>

            <!-- Buttons -->
            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modal-edit-user')" class="px-3.5 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium text-xs transition-colors">
                    Batal
                </button>
                <button type="submit" class="bg-[#C81E26] hover:bg-[#B2151D] text-white px-4 py-2 rounded-lg font-semibold text-xs shadow-subtle transition-colors">
                    Simpan Perubahan
                </button>
            </div>

        </form>

    </div>
</div>

<!-- MODAL 3: KONFIRMASI HAPUS KARYAWAN -->
<div id="modal-delete-user" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-md w-full shadow-xl border border-slate-200 overflow-hidden">
        <div class="p-6 text-center">
            <div class="w-12 h-12 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center text-2xl mx-auto mb-3 border border-rose-100">
                <i class="ph-bold ph-warning"></i>
            </div>
            <h3 class="font-bold text-base text-slate-900">Hapus Akun Karyawan?</h3>
            <p class="text-xs text-slate-500 mt-1">Apakah Anda yakin ingin menghapus akun karyawan <strong id="delete-user-name" class="text-slate-800"></strong> (<span id="delete-user-username" class="font-mono text-slate-700"></span>)? Tindakan ini tidak dapat dibatalkan.</p>
            
            <form action="users.php" method="POST" class="mt-6 flex items-center justify-center gap-2">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" id="delete_user_id" name="user_id" value="">
                
                <button type="button" onclick="closeModal('modal-delete-user')" class="w-full py-2 px-3 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 font-medium text-xs transition-colors">
                    Batal
                </button>
                <button type="submit" class="w-full py-2 px-3 rounded-lg bg-rose-600 hover:bg-rose-700 text-white font-semibold text-xs shadow-subtle transition-colors">
                    Ya, Hapus Akun
                </button>
            </form>
        </div>
    </div>
</div>

<script>
let currentRoleFilter = 'ALL';

function filterUserRole(role, btnEl) {
    currentRoleFilter = role;
    
    document.querySelectorAll('.role-filter-btn').forEach(b => {
        b.className = 'role-filter-btn px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-50 transition-colors';
    });
    btnEl.className = 'role-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors bg-slate-900 text-white';
    
    applyUserFilters();
}

function searchUsers() {
    applyUserFilters();
}

function applyUserFilters() {
    const searchVal = (document.getElementById('user-search-input')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.user-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowRole = row.getAttribute('data-role');
        const rowSearch = row.getAttribute('data-search') || '';

        const matchesRole = (currentRoleFilter === 'ALL' || rowRole === currentRoleFilter);
        const matchesSearch = (searchVal === '' || rowSearch.includes(searchVal));

        if (matchesRole && matchesSearch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const emptyMsg = document.getElementById('users-empty-message');
    if (emptyMsg) {
        if (visibleCount === 0 && rows.length > 0) {
            emptyMsg.classList.remove('hidden');
        } else {
            emptyMsg.classList.add('hidden');
        }
    }
}

function openEditUserModal(user) {
    if (!user) return;
    document.getElementById('edit_user_id').value = user.id || '';
    document.getElementById('edit_username_badge').textContent = '@' + (user.username || '');
    document.getElementById('edit_full_name').value = user.full_name || '';
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_department').value = user.department || '';
    document.getElementById('edit_new_password').value = '';

    const roleSelect = document.getElementById('edit_role');
    const adminTag = document.getElementById('edit_admin_tag');

    if (roleSelect) {
        roleSelect.value = user.role || 'TECHNICIAN';
        if (user.username === 'admin') {
            roleSelect.setAttribute('disabled', 'disabled');
            if (adminTag) adminTag.classList.remove('hidden');
        } else {
            roleSelect.removeAttribute('disabled');
            if (adminTag) adminTag.classList.add('hidden');
        }
    }

    openModal('modal-edit-user');
}

function confirmDeleteUser(id, fullName, username) {
    document.getElementById('delete_user_id').value = id;
    document.getElementById('delete-user-name').textContent = fullName;
    document.getElementById('delete-user-username').textContent = '@' + username;
    openModal('modal-delete-user');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
