<?php
/**
 * Halaman Login Sistem Internal PT Kalpindo
 * Multi-Role Authentication with Real Session Enforcement
 * Solid Viewport Design (No-Scroll, Compact Enterprise Split-Card)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$db = getDbConnection();
$roles = getAllRoles();
$error = '';

// If already authenticated, redirect to dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Handle Real Form POST Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Harap masukkan username dan password akun Anda.';
    } elseif (loginUser($username, $password, $db)) {
        $user = getCurrentUser();
        $roles = getAllRoles();
        $roleName = $roles[$user['role']]['name'] ?? $user['role'];
        setFlash('success', "Selamat datang, {$user['full_name']}! Anda berhasil masuk sebagai {$roleName}.");
        header('Location: index.php');
        exit;
    } else {
        $error = 'Username atau password salah. Silakan periksa kembali kredensial Anda.';
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk Sistem | PT Kalpindo CalibFlow</title>
    
    <!-- Fonts: Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/custom.css">

    <style>
        /* Strict solid viewport locks on screens above mobile */
        @media (min-height: 600px) and (min-width: 768px) {
            html, body {
                height: 100vh !important;
                overflow: hidden !important;
            }
        }
        .role-btn.active-role {
            border-color: #C81E26 !important;
            background-color: #FEF2F2 !important;
            box-shadow: 0 0 0 1px #C81E26 inset;
        }
    </style>
</head>
<body class="bg-[#F1F5F9] text-slate-800 font-sans antialiased min-h-screen md:h-screen md:overflow-hidden flex items-center justify-center p-3 sm:p-5 lg:p-6">

    <!-- Top Red Accent Bar -->
    <div class="fixed top-0 left-0 right-0 h-1 bg-[#C81E26] z-50"></div>

    <!-- Main Solid Container (Max-W-5xl) -->
    <div class="w-full max-w-5xl bg-white rounded-2xl sm:rounded-3xl shadow-xl shadow-slate-200/80 border border-slate-200 overflow-hidden grid grid-cols-1 lg:grid-cols-12 max-h-[96vh]">
        
        <!-- LEFT COLUMN: Quick Role Picker & System Info (5 cols) -->
        <div class="lg:col-span-5 bg-slate-50/80 border-b lg:border-b-0 lg:border-r border-slate-200 p-5 sm:p-6 lg:p-7 flex flex-col justify-between">
            
            <!-- Brand & Heading -->
            <div>
                <div class="flex items-center gap-3">
                    <img src="assets/img/logo.png" alt="Logo PT Kalpindo" class="h-8 sm:h-9 object-contain">
                    <div class="border-l border-gray-200 pl-2.5">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs font-black tracking-wider text-slate-900 uppercase">CalibFlow</span>
                            <span class="px-1.5 py-0.5 text-[9px] font-extrabold bg-slate-200 text-slate-800 rounded border border-slate-300 tracking-wider">PORTAL KARYAWAN</span>
                        </div>
                        <p class="text-[10px] text-gray-500 font-medium">Sistem Operasional Kalibrasi</p>
                    </div>
                </div>

                <div class="mt-4 pb-3 border-b border-gray-200/80">
                    <h2 class="text-sm font-bold text-slate-900">Pilihan Akun & Peran Pengguna</h2>
                    <p class="text-[11px] text-gray-500 mt-0.5">Klik salah satu akun karyawan untuk mengisi formulir login:</p>
                </div>
            </div>

            <!-- Role Selector Buttons (Compact 4-stack) -->
            <div class="my-3 space-y-2" id="role-buttons-container">
                
                <!-- 1. Super Admin -->
                <button type="button" onclick="selectRole('admin', 'password123', this)" class="role-btn w-full p-2.5 rounded-xl border border-purple-200 bg-white hover:bg-purple-50/60 transition-all flex items-center justify-between text-left group shadow-2xs">
                    <div class="flex items-center gap-2.5">
                        <div class="w-7 h-7 rounded-lg bg-purple-600 text-white font-bold flex items-center justify-center text-xs shrink-0 shadow-2xs">
                            HW
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <strong class="text-xs text-purple-950 font-bold">Super Admin</strong>
                                <span class="font-mono text-[9px] text-purple-700 bg-purple-100 px-1 py-0.2 rounded font-bold">admin</span>
                            </div>
                            <p class="text-[10px] text-gray-500">Ir. Hendra Wijaya, M.T. (Manajer Teknis)</p>
                        </div>
                    </div>
                    <i class="ph-bold ph-caret-right text-purple-400 group-hover:text-purple-700 group-hover:translate-x-0.5 transition-all text-xs"></i>
                </button>

                <!-- 2. Sales -->
                <button type="button" onclick="selectRole('sales', 'password123', this)" class="role-btn w-full p-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-100/70 transition-all flex items-center justify-between text-left group shadow-2xs">
                    <div class="flex items-center gap-2.5">
                        <div class="w-7 h-7 rounded-lg bg-slate-800 text-white font-bold flex items-center justify-center text-xs shrink-0 shadow-2xs">
                            SR
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <strong class="text-xs text-slate-900 font-bold">Divisi Sales</strong>
                                <span class="font-mono text-[9px] text-slate-700 bg-gray-200 px-1 py-0.2 rounded font-bold">sales</span>
                            </div>
                            <p class="text-[10px] text-gray-500">Siti Rahmawati, S.E. (Order & Front Office)</p>
                        </div>
                    </div>
                    <i class="ph-bold ph-caret-right text-slate-400 group-hover:text-slate-700 group-hover:translate-x-0.5 transition-all text-xs"></i>
                </button>

                <!-- 3. Teknisi -->
                <button type="button" onclick="selectRole('teknisi', 'password123', this)" class="role-btn w-full p-2.5 rounded-xl border border-blue-200 bg-white hover:bg-blue-50/60 transition-all flex items-center justify-between text-left group shadow-2xs">
                    <div class="flex items-center gap-2.5">
                        <div class="w-7 h-7 rounded-lg bg-blue-600 text-white font-bold flex items-center justify-center text-xs shrink-0 shadow-2xs">
                            AF
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <strong class="text-xs text-blue-950 font-bold">Teknisi Kalibrasi</strong>
                                <span class="font-mono text-[9px] text-blue-700 bg-blue-100 px-1 py-0.2 rounded font-bold">teknisi</span>
                            </div>
                            <p class="text-[10px] text-gray-500">Ahmad Fauzi, A.Md. (Lembar Kerja Worksheet)</p>
                        </div>
                    </div>
                    <i class="ph-bold ph-caret-right text-blue-400 group-hover:text-blue-700 group-hover:translate-x-0.5 transition-all text-xs"></i>
                </button>

                <!-- 4. Pengurus Sertifikat -->
                <button type="button" onclick="selectRole('sertifikat', 'password123', this)" class="role-btn w-full p-2.5 rounded-xl border border-red-200 bg-white hover:bg-red-50/60 transition-all flex items-center justify-between text-left group shadow-2xs active-role">
                    <div class="flex items-center gap-2.5">
                        <div class="w-7 h-7 rounded-lg bg-[#C81E26] text-white font-bold flex items-center justify-center text-xs shrink-0 shadow-2xs">
                            RP
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <strong class="text-xs text-[#C81E26] font-bold">Pengurus Sertifikat</strong>
                                <span class="font-mono text-[9px] text-red-700 bg-red-100 px-1 py-0.2 rounded font-bold">sertifikat</span>
                            </div>
                            <p class="text-[10px] text-gray-500">Raditya Pratama (Format YYMMSNNNN-RR & Cetak)</p>
                        </div>
                    </div>
                    <i class="ph-bold ph-caret-right text-[#C81E26] group-hover:translate-x-0.5 transition-all text-xs"></i>
                </button>

            </div>

            <!-- Left Footer Note -->
            <div class="pt-2 border-t border-gray-200/80 flex items-center justify-between text-[10px] text-gray-400 font-mono">
                <span>Laboratorium KAN LK-088-IDN</span>
                <span class="text-emerald-600 font-semibold flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> ISO/IEC 17025
                </span>
            </div>

        </div>

        <!-- RIGHT COLUMN: Authentication Form (7 cols) -->
        <div class="lg:col-span-7 p-6 sm:p-8 lg:p-10 flex flex-col justify-between">
            
            <div>
                <!-- Top Title -->
                <div class="mb-5">
                    <span class="px-2 py-0.5 text-[10px] font-mono font-bold text-slate-500 bg-slate-100 rounded border border-slate-200 uppercase tracking-wider">
                        Sistem Otentikasi Terintegrasi
                    </span>
                    <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight mt-1.5">Masuk ke Portal Operasional</h1>
                    <p class="text-xs text-gray-500 mt-1">Masukkan username dan kata sandi akun karyawan Anda untuk memulai sesi kerja.</p>
                </div>

                <!-- Flash & Error Alert -->
                <?php if ($error || $flash): ?>
                    <div class="mb-4 p-3 rounded-xl border flex items-center gap-2.5 text-xs <?= $error ? 'bg-red-50 border-red-200 text-[#C81E26]' : 'bg-emerald-50 border-emerald-200 text-emerald-800' ?>">
                        <i class="ph-bold <?= $error ? 'ph-warning-circle text-base text-[#C81E26]' : 'ph-check-circle text-base text-emerald-600' ?> shrink-0"></i>
                        <span class="font-medium"><?= htmlspecialchars($error ?: $flash['message']) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <form action="login.php" method="POST" class="space-y-3.5 text-xs" id="login-form">
                    
                    <!-- Username Field -->
                    <div>
                        <label for="username" class="block font-bold text-slate-700 mb-1">Username Pengguna</label>
                        <div class="relative">
                            <i class="ph-bold ph-user absolute left-3.5 top-3 text-gray-400 text-sm"></i>
                            <input id="username" name="username" type="text" required value="<?= htmlspecialchars($_POST['username'] ?? 'sertifikat') ?>" placeholder="Masukkan username..." class="w-full bg-gray-50/70 border border-gray-300 rounded-xl pl-10 pr-3.5 py-2.5 text-slate-900 text-xs font-semibold focus:outline-none focus:border-[#C81E26] focus:bg-white focus:ring-1 focus:ring-[#C81E26] transition-all">
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="password" class="block font-bold text-slate-700">Kata Sandi</label>
                            <span class="text-[10px] text-gray-400 font-mono">Default: password123</span>
                        </div>
                        <div class="relative">
                            <i class="ph-bold ph-lock-key absolute left-3.5 top-3 text-gray-400 text-sm"></i>
                            <input id="password" name="password" type="password" required value="password123" placeholder="Masukkan password..." class="w-full bg-gray-50/70 border border-gray-300 rounded-xl pl-10 pr-10 py-2.5 text-slate-900 text-xs font-semibold focus:outline-none focus:border-[#C81E26] focus:bg-white focus:ring-1 focus:ring-[#C81E26] transition-all">
                            <button type="button" onclick="togglePasswordVisibility()" class="absolute right-3 top-2.5 text-gray-400 hover:text-slate-700 p-0.5 rounded" title="Lihat/Sembunyikan sandi">
                                <i id="password-toggle-icon" class="ph-bold ph-eye text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2">
                        <button type="submit" id="submit-login-btn" class="w-full bg-[#C81E26] hover:bg-[#A8141B] active:scale-[0.99] text-white py-2.5 px-4 rounded-xl font-bold text-xs transition-all shadow-md shadow-red-900/10 flex items-center justify-center gap-2">
                            <i class="ph-bold ph-sign-in text-sm"></i>
                            <span>Masuk ke Sistem</span>
                        </button>
                    </div>

                </form>
            </div>

            <!-- Bottom Security & Help Note -->
            <div class="mt-5 pt-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-2 text-[11px] text-gray-400">
                <div class="flex items-center gap-1.5 text-slate-600 font-medium">
                    <i class="ph-fill ph-shield-check text-emerald-600 text-sm"></i>
                    <span>Sesi Terenkripsi & Akses Berbasis Peran</span>
                </div>
                <span class="font-mono text-[10px]">PT Kalpindo Kalibrasi</span>
            </div>

        </div>

    </div>

    <!-- Interactive Script -->
    <script>
        function selectRole(username, password, btnElement) {
            document.getElementById('username').value = username;
            document.getElementById('password').value = password;
            
            // Remove active class from all buttons
            document.querySelectorAll('.role-btn').forEach(btn => {
                btn.classList.remove('active-role');
            });
            
            // Add active class to clicked button
            if (btnElement) {
                btnElement.classList.add('active-role');
            }
            
            // Focus username briefly
            document.getElementById('username').focus();
        }

        function togglePasswordVisibility() {
            const pwdInput = document.getElementById('password');
            const icon = document.getElementById('password-toggle-icon');
            if (pwdInput.type === 'password') {
                pwdInput.type = 'text';
                icon.classList.remove('ph-eye');
                icon.classList.add('ph-eye-slash');
            } else {
                pwdInput.type = 'password';
                icon.classList.remove('ph-eye-slash');
                icon.classList.add('ph-eye');
            }
        }
    </script>

</body>
</html>
