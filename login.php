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
        /* Solid viewport locks on screens above mobile */
        @media (min-height: 500px) and (min-width: 768px) {
            html, body {
                height: 100vh !important;
                overflow: hidden !important;
            }
        }
    </style>
</head>
<body class="bg-[#F1F5F9] text-slate-800 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <!-- Top Red Accent Bar -->
    <div class="fixed top-0 left-0 right-0 h-1.5 bg-[#C81E26] z-50"></div>

    <!-- Centered Login Card -->
    <div class="w-full max-w-md bg-white rounded-2xl sm:rounded-3xl shadow-xl shadow-slate-200/80 border border-slate-200 overflow-hidden">
        
        <div class="p-6 sm:p-8">
            
            <!-- Logo & Brand Header -->
            <div class="text-center mb-6">
                <div class="inline-flex items-center justify-center mb-3">
                    <img src="assets/img/logo.png" alt="Logo PT Kalpindo" class="h-10 object-contain">
                </div>
                <div class="flex items-center justify-center gap-2 mb-1.5">
                    <span class="text-xs font-black tracking-wider text-slate-900 uppercase">CalibFlow</span>
                    <span class="px-1.5 py-0.5 text-[9px] font-extrabold bg-slate-100 text-slate-700 rounded border border-slate-200 tracking-wider">PORTAL RESMI</span>
                </div>
                <h1 class="text-xl font-black text-slate-900 tracking-tight">Masuk ke Sistem</h1>
                <p class="text-xs text-gray-500 mt-1">Masukkan username dan kata sandi akun Anda untuk memulai sesi kerja.</p>
            </div>

            <!-- Flash & Error Alert -->
            <?php if ($error || $flash): ?>
                <div class="mb-5 p-3 rounded-xl border flex items-center gap-2.5 text-xs <?= $error ? 'bg-red-50 border-red-200 text-[#C81E26]' : 'bg-emerald-50 border-emerald-200 text-emerald-800' ?>">
                    <i class="ph-bold <?= $error ? 'ph-warning-circle text-base text-[#C81E26]' : 'ph-check-circle text-base text-emerald-600' ?> shrink-0"></i>
                    <span class="font-medium"><?= htmlspecialchars($error ?: $flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form action="login.php" method="POST" class="space-y-4 text-xs" id="login-form">
                
                <!-- Username Field -->
                <div>
                    <label for="username" class="block font-bold text-slate-700 mb-1.5">Username Pengguna</label>
                    <div class="relative">
                        <i class="ph-bold ph-user absolute left-3.5 top-3 text-gray-400 text-sm"></i>
                        <input id="username" name="username" type="text" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Masukkan username akun..." class="w-full bg-gray-50/70 border border-gray-300 rounded-xl pl-10 pr-3.5 py-2.5 text-slate-900 text-xs font-semibold focus:outline-none focus:border-[#C81E26] focus:bg-white focus:ring-1 focus:ring-[#C81E26] transition-all">
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="block font-bold text-slate-700">Kata Sandi</label>
                    </div>
                    <div class="relative">
                        <i class="ph-bold ph-lock-key absolute left-3.5 top-3 text-gray-400 text-sm"></i>
                        <input id="password" name="password" type="password" required placeholder="Masukkan kata sandi..." class="w-full bg-gray-50/70 border border-gray-300 rounded-xl pl-10 pr-10 py-2.5 text-slate-900 text-xs font-semibold focus:outline-none focus:border-[#C81E26] focus:bg-white focus:ring-1 focus:ring-[#C81E26] transition-all">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute right-3 top-2.5 text-gray-400 hover:text-slate-700 p-0.5 rounded cursor-pointer" title="Lihat/Sembunyikan sandi">
                            <i id="password-toggle-icon" class="ph-bold ph-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-2">
                    <button type="submit" id="submit-login-btn" class="w-full bg-[#C81E26] hover:bg-[#A8141B] active:scale-[0.99] text-white py-2.5 px-4 rounded-xl font-bold text-xs transition-all shadow-md shadow-red-900/10 flex items-center justify-center gap-2 cursor-pointer">
                        <i class="ph-bold ph-sign-in text-sm"></i>
                        <span>Masuk ke Sistem</span>
                    </button>
                </div>

            </form>

        </div>

        <!-- Card Footer -->
        <div class="bg-slate-50 px-6 py-3.5 border-t border-gray-100 flex items-center justify-between text-[10px] text-gray-400 font-mono">
            <span>PT Kalpindo Kalibrasi</span>
            <span class="text-emerald-700 font-semibold flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> KAN LK-088-IDN
            </span>
        </div>

    </div>

    <!-- Interactive Script -->
    <script>
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
