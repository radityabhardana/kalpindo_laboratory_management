<?php
/**
 * Halaman Login Sistem Internal PT Kalpindo
 * Multi-Role Authentication with Real Session Enforcement
 * Clean Modern Enterprise Design System (ISO/IEC 17025)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Harap masukkan username dan kata sandi akun Anda.';
    } else {
        $db = getDbConnection();
        if (loginUser($username, $password, $db)) {
            $user = getCurrentUser();
            $roles = getAllRoles();
            $roleName = $roles[$user['role']]['name'] ?? $user['role'];
            setFlash('success', "Selamat datang, {$user['full_name']}! Anda berhasil masuk sebagai {$roleName}.");
            header('Location: index.php');
            exit;
        }
        $error = 'Username atau kata sandi salah. Silakan periksa kembali kredensial Anda.';
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk Sistem | PT Kalpindo Kalibrasi</title>
    
    <script>
        (function() {
            try {
                var savedTheme = localStorage.getItem('theme');
                var prefersDark = (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (savedTheme === 'dark' || prefersDark) {
                    document.documentElement.classList.add('dark');
                    document.documentElement.setAttribute('data-bs-theme', 'dark');
                } else {
                    document.documentElement.classList.remove('dark');
                    document.documentElement.setAttribute('data-bs-theme', 'light');
                }
            } catch (e) {}
        })();
    </script>

    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=5.3.3">
    <link rel="stylesheet" href="assets/css/phosphor.css?v=2.1.1">
    <link rel="stylesheet" href="assets/css/custom.css?v=<?= filemtime(__DIR__ . '/assets/css/custom.css') ?>">

    <!-- Self-Contained Bulletproof Styles for Login (100% Immune to Browser Cache Issues) -->
    <style>
        :root {
            --brand-primary: #C81E26;
            --brand-primary-hover: #A8141B;
            --brand-primary-ring: rgba(200, 30, 38, 0.18);
            --bg-app: #F8FAFC;
            --surface-card: #FFFFFF;
            --surface-subtle: #F1F5F9;
            --surface-muted: #E2E8F0;
            --border-subtle: #E2E8F0;
            --border-strong: #CBD5E1;
            --text-primary: #0F172A;
            --text-secondary: #334155;
            --text-muted: #64748B;
        }

        html.dark, [data-bs-theme="dark"] {
            --bg-app: #0B0F17;
            --surface-card: #111827;
            --surface-subtle: #151E2E;
            --surface-muted: #1E293B;
            --border-subtle: #1F2937;
            --border-strong: #374151;
            --text-primary: #F8FAFC;
            --text-secondary: #E2E8F0;
            --text-muted: #94A3B8;
        }

        *, *::before, *::after {
            box-sizing: border-box !important;
        }

        body {
            background-color: var(--bg-app) !important;
            color: var(--text-primary) !important;
            margin: 0 !important;
            padding: 0 !important;
            min-height: 100vh !important;
            font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .login-page-wrap {
            min-height: 100vh;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            background-image: 
                radial-gradient(at 0% 0%, rgba(200, 30, 38, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(15, 23, 42, 0.04) 0px, transparent 50%);
        }

        html.dark .login-page-wrap,
        [data-bs-theme="dark"] .login-page-wrap {
            background-image: 
                radial-gradient(at 0% 0%, rgba(200, 30, 38, 0.15) 0px, transparent 55%),
                radial-gradient(at 100% 100%, rgba(30, 41, 59, 0.3) 0px, transparent 50%);
        }

        .login-box {
            width: 100% !important;
            max-width: 420px !important;
            margin: 0 auto !important;
            background-color: var(--surface-card) !important;
            border: 1px solid var(--border-subtle) !important;
            border-radius: 1rem !important;
            box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04) !important;
            position: relative !important;
            overflow: hidden !important;
        }

        .login-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3.5px;
            background: linear-gradient(90deg, #C81E26 0%, #EA580C 100%);
            z-index: 10;
        }

        .login-box-body {
            padding: 2rem 1.75rem 1.5rem !important;
        }

        .login-input-group {
            position: relative !important;
            display: flex !important;
            align-items: center !important;
            width: 100% !important;
        }

        .login-input-icon {
            position: absolute !important;
            left: 1rem !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            pointer-events: none !important;
            color: #94A3B8 !important;
            font-size: 1.15rem !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            z-index: 2 !important;
            transition: color 0.15s ease !important;
        }

        .login-input-group:focus-within .login-input-icon {
            color: var(--brand-primary) !important;
        }

        .login-input {
            width: 100% !important;
            height: 2.75rem !important;
            padding-left: 2.875rem !important;
            padding-right: 1rem !important;
            font-size: 0.8125rem !important;
            border-radius: 0.5rem !important;
            border: 1px solid var(--border-strong) !important;
            background-color: var(--surface-card) !important;
            color: var(--text-primary) !important;
            outline: none !important;
            transition: all 0.15s ease !important;
            box-sizing: border-box !important;
        }

        .login-input.has-toggle {
            padding-right: 2.875rem !important;
        }

        .login-input:focus {
            border-color: var(--brand-primary) !important;
            box-shadow: 0 0 0 3px var(--brand-primary-ring) !important;
            background-color: var(--surface-card) !important;
        }

        html.dark .login-input,
        [data-bs-theme="dark"] .login-input {
            background-color: var(--surface-subtle) !important;
            border-color: var(--border-strong) !important;
            color: var(--text-primary) !important;
        }

        html.dark .login-input:focus,
        [data-bs-theme="dark"] .login-input:focus {
            background-color: var(--surface-muted) !important;
            border-color: var(--brand-primary) !important;
            color: #FFFFFF !important;
        }

        .login-pwd-toggle {
            position: absolute !important;
            right: 0.5rem !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            width: 2.125rem !important;
            height: 2.125rem !important;
            border-radius: 0.375rem !important;
            border: none !important;
            background: transparent !important;
            color: #94A3B8 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            transition: all 0.15s ease !important;
            z-index: 2 !important;
            padding: 0 !important;
        }

        .login-pwd-toggle:hover {
            color: var(--text-primary) !important;
            background-color: var(--surface-muted) !important;
        }
        .btn-brand-primary {
            background-color: var(--brand-primary) !important;
            color: #FFFFFF !important;
            font-weight: 600 !important;
            font-size: 0.8125rem !important;
            height: 2.75rem !important;
            border-radius: 0.5rem !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.5rem !important;
            width: 100% !important;
            border: 1px solid transparent !important;
            cursor: pointer !important;
            transition: all 0.12s ease !important;
            box-shadow: 0 1px 2px 0 rgba(200, 30, 38, 0.2) !important;
        }

        .btn-brand-primary:hover {
            background-color: var(--brand-primary-hover) !important;
            box-shadow: 0 4px 6px -1px rgba(200, 30, 38, 0.3) !important;
        }

        .btn-brand-primary:active {
            transform: translateY(1px) !important;
        }
        .pill-kan {
            display: inline-flex !important;
            align-items: center !important;
            gap: 0.375rem !important;
            padding: 0.25rem 0.625rem !important;
            border-radius: 9999px !important;
            font-size: 0.625rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.04em !important;
            background-color: rgba(200, 30, 38, 0.08) !important;
            border: 1px solid rgba(200, 30, 38, 0.22) !important;
            color: #C81E26 !important;
        }

        .theme-toggle-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 2.375rem !important;
            height: 2.375rem !important;
            border-radius: 0.625rem !important;
            background-color: var(--surface-card) !important;
            border: 1px solid var(--border-subtle) !important;
            color: var(--text-secondary) !important;
            cursor: pointer !important;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05) !important;
            transition: all 0.15s ease !important;
        }

        .theme-toggle-btn:hover {
            background-color: var(--surface-muted) !important;
            color: var(--text-primary) !important;
        }

        html.dark .theme-icon-moon,
        [data-bs-theme="dark"] .theme-icon-moon { display: none !important; }
        html.dark .theme-icon-sun,
        [data-bs-theme="dark"] .theme-icon-sun { display: block !important; }
        html:not(.dark):not([data-bs-theme="dark"]) .theme-icon-moon { display: block !important; }
        html:not(.dark):not([data-bs-theme="dark"]) .theme-icon-sun { display: none !important; }
    </style>
</head>
<body>

    <!-- Floating Theme Toggle Button -->
    <div style="position: fixed; top: 1rem; right: 1rem; z-index: 50;">
        <button type="button" onclick="toggleTheme()" class="theme-toggle-btn" title="Ganti Mode Tema (Terang / Gelap)">
            <i class="ph-bold ph-moon text-base theme-icon-moon"></i>
            <i class="ph-bold ph-sun text-base theme-icon-sun"></i>
        </button>
    </div>

    <!-- Centered Login Layout Wrapper -->
    <div class="login-page-wrap login-container">
        <div class="login-box login-card" style="max-width: 420px; width: 100%; margin: 0 auto;">
            
            <div class="login-box-body">
                
                <!-- Brand Header -->
                <div class="text-center mb-4" style="text-align: center; margin-bottom: 1.5rem;">
                    <a href="index.php" style="display: inline-block; margin-bottom: 0.75rem;" title="PT Kalpindo Kalibrasi">
                        <img src="assets/img/logo.png" alt="Logo PT Kalpindo" style="height: 42px; max-width: 100%; object-fit: contain; margin: 0 auto; display: block;">
                    </a>
                    
                    <div style="margin-bottom: 0.5rem;">
                        <span class="pill-kan" style="font-size: 10px;">
                            <i class="ph-bold ph-seal-check"></i>
                            KAN LK-088-IDN &bull; ISO/IEC 17025
                        </span>
                    </div>

                    <h1 style="font-size: 1.0625rem; font-weight: 700; margin: 0; color: var(--text-primary); letter-spacing: -0.025em;">
                        Masuk ke Sistem Alur Kerja
                    </h1>
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; margin-bottom: 0;">
                        Sistem Manajemen & Sertifikasi Kalibrasi Internal
                    </p>
                </div>

                <!-- Alert Feedback -->
                <?php if ($error || $flash): ?>
                    <div style="margin-bottom: 1rem; padding: 0.75rem; border-radius: 0.5rem; display: flex; align-items: flex-start; gap: 0.625rem; font-size: 0.75rem; border: 1px solid <?= $error ? '#FECDD3; background-color: #FFF1F2; color: #BE123C;' : '#A7F3D0; background-color: #ECFDF5; color: #065F46;' ?>">
                        <i class="ph-bold <?= $error ? 'ph-warning-circle' : 'ph-check-circle' ?>" style="font-size: 1rem; flex-shrink: 0; margin-top: 0.125rem;"></i>
                        <span style="font-weight: 500; line-height: 1.4;"><?= htmlspecialchars($error ?: $flash['message']) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Form Login -->
                <form action="login.php" method="POST" style="display: flex; flex-direction: column; gap: 1rem;" id="login-form">
                    
                    <!-- Username Field -->
                    <div>
                        <label for="username" style="display: block; font-weight: 600; font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.375rem;">
                            Username Akun
                        </label>
                        <div class="login-input-group">
                            <span class="login-input-icon">
                                <i class="ph-bold ph-user"></i>
                            </span>
                            <input 
                                id="username" 
                                name="username" 
                                type="text" 
                                required 
                                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                                placeholder="Masukkan username akun..." 
                                class="login-input"
                                autocomplete="username"
                                autofocus
                                style="width: 100% !important; height: 44px; padding-left: 44px; padding-right: 14px; border-radius: 8px; border: 1px solid var(--border-strong); background-color: var(--surface-card); color: var(--text-primary); font-size: 13px; box-sizing: border-box; outline: none;"
                            >
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div>
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.375rem;">
                            <label for="password" style="display: block; font-weight: 600; font-size: 0.75rem; color: var(--text-secondary);">
                                Kata Sandi
                            </label>
                        </div>
                        <div class="login-input-group">
                            <span class="login-input-icon">
                                <i class="ph-bold ph-lock-key"></i>
                            </span>
                            <input 
                                id="password" 
                                name="password" 
                                type="password" 
                                required 
                                placeholder="Masukkan kata sandi..." 
                                class="login-input has-toggle"
                                autocomplete="current-password"
                                style="width: 100% !important; height: 44px; padding-left: 44px; padding-right: 44px; border-radius: 8px; border: 1px solid var(--border-strong); background-color: var(--surface-card); color: var(--text-primary); font-size: 13px; box-sizing: border-box; outline: none;"
                            >
                            <button 
                                type="button" 
                                onclick="togglePasswordVisibility()" 
                                class="login-pwd-toggle" 
                                title="Lihat/Sembunyikan sandi"
                                aria-label="Toggle password visibility"
                                style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; border-radius: 6px; color: #94A3B8;"
                            >
                                <i id="password-toggle-icon" class="ph-bold ph-eye" style="font-size: 1.1rem;"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div style="padding-top: 0.5rem;">
                        <button type="submit" id="submit-login-btn" class="btn-brand-primary" style="width: 100% !important; height: 44px; border-radius: 8px; background-color: var(--brand-primary); color: #FFFFFF; font-weight: 600; font-size: 13px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 1px 2px rgba(200, 30, 38, 0.2);">
                            <i class="ph-bold ph-sign-in" style="font-size: 1.1rem;"></i>
                            <span>Masuk Sistem</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card Footer -->
            <div style="background-color: var(--surface-subtle); padding: 0.75rem 1.75rem; border-top: 1px solid var(--border-subtle); display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: var(--text-muted);">
                <span style="font-weight: 500;">PT Kalpindo Kalibrasi</span>
                <span style="font-family: var(--font-mono, monospace); font-weight: 600; color: var(--text-secondary);">KAN LK-088-IDN</span>
            </div>
        </div>
    </div>

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
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
