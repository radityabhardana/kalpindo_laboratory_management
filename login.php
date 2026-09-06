<?php
/**
 * Halaman Login Sistem Internal PT Kalpindo
 * Multi-Role Authentication with Real Session Enforcement
 * Clean Modern Enterprise Centered View
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
        $error = 'Harap masukkan username dan kata sandi akun Anda.';
    } elseif (loginUser($username, $password, $db)) {
        $user = getCurrentUser();
        $roles = getAllRoles();
        $roleName = $roles[$user['role']]['name'] ?? $user['role'];
        setFlash('success', "Selamat datang, {$user['full_name']}! Anda berhasil masuk sebagai {$roleName}.");
        header('Location: index.php');
        exit;
    } else {
        $error = 'Username atau kata sandi salah. Silakan periksa kembali kredensial Anda.';
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk Sistem | PT Kalpindo Kalibrasi</title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/custom.css">
</head>
<body class="bg-slate-50 text-slate-800 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <!-- Centered Enterprise Login Card -->
    <div class="w-full max-w-sm bg-white rounded-xl shadow-subtle border border-slate-200/80 overflow-hidden">
        
        <div class="p-6 sm:p-7">
            
            <!-- Logo & Brand Header -->
            <div class="text-center mb-6">
                <a href="index.php" class="inline-block mb-3">
                    <img src="assets/img/logo.png" alt="Logo PT Kalpindo" class="h-9 mx-auto object-contain">
                </a>
                <h1 class="text-base font-bold text-slate-900 tracking-tight">Masuk ke Sistem Alur Kerja</h1>
                <p class="text-xs text-slate-500 mt-0.5">Sistem Manajemen & Sertifikasi Kalibrasi ISO/IEC 17025</p>
            </div>

            <!-- Flash & Error Alert -->
            <?php if ($error || $flash): ?>
                <div class="mb-4 p-3 rounded-lg border flex items-start gap-2 text-xs <?= $error ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-emerald-50 border-emerald-200 text-emerald-800' ?>">
                    <i class="ph-bold <?= $error ? 'ph-warning-circle text-rose-600' : 'ph-check-circle text-emerald-600' ?> text-sm shrink-0 mt-0.5"></i>
                    <span class="font-medium"><?= htmlspecialchars($error ?: $flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form action="login.php" method="POST" class="space-y-4 text-xs" id="login-form">
                
                <!-- Username Field -->
                <div>
                    <label for="username" class="block font-medium text-slate-700 mb-1">Username</label>
                    <div class="relative">
                        <i class="ph-bold ph-user absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input id="username" name="username" type="text" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Masukkan username akun..." class="w-full bg-white border border-slate-300 rounded-lg pl-8 pr-3 py-2 text-slate-900 text-xs focus:outline-none focus:border-slate-800 transition-colors">
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block font-medium text-slate-700 mb-1">Kata Sandi</label>
                    <div class="relative">
                        <i class="ph-bold ph-lock-key absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input id="password" name="password" type="password" required placeholder="Masukkan kata sandi..." class="w-full bg-white border border-slate-300 rounded-lg pl-8 pr-9 py-2 text-slate-900 text-xs focus:outline-none focus:border-slate-800 transition-colors">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-0.5 rounded transition-colors" title="Lihat/Sembunyikan sandi">
                            <i id="password-toggle-icon" class="ph-bold ph-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-1">
                    <button type="submit" id="submit-login-btn" class="w-full bg-[#C81E26] hover:bg-[#B2151D] text-white py-2 px-4 rounded-lg font-semibold text-xs transition-colors shadow-subtle flex items-center justify-center gap-1.5">
                        <i class="ph-bold ph-sign-in text-xs"></i>
                        <span>Masuk Sistem</span>
                    </button>
                </div>

            </form>

        </div>

        <!-- Card Footer -->
        <div class="bg-slate-50/80 px-6 py-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
            <span>PT Kalpindo Kalibrasi</span>
            <span class="text-slate-500 font-medium">KAN LK-088-IDN</span>
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
