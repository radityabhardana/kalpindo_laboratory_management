<?php
/**
 * Header Template - Enterprise Internal System
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Full Local Bootstrap 5 Dashboard Architecture
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

// Enforce real authentication
requireLogin();

$dbHeader = getDbConnection();
$currentUser = getCurrentUser();
$allRoles = getAllRoles();
$activeRoleInfo = $allRoles[$currentUser['role']] ?? [
    'name' => $currentUser['role'],
    'short' => $currentUser['role'],
    'badge_class' => 'bg-secondary-subtle text-secondary border',
    'icon' => 'ph-user'
];

$nameParts = explode(' ', trim($currentUser['full_name']));
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
$isMasterSetupAdmin = ($currentUser['username'] === 'admin');

$currentPage = basename($_SERVER['PHP_SELF']);
$flash = getFlash();

$pendingWorksheetCount = (int)$dbHeader->query("SELECT COUNT(*) FROM instruments WHERE status = 'ASSIGNED' OR status = 'IN_PROGRESS'")->fetchColumn();
$pendingCertCount = (int)$dbHeader->query("SELECT COUNT(*) FROM instruments WHERE status = 'DATA_SUBMITTED'")->fetchColumn();

// Strict Role-Based Operational Navigation Map with Clear Sectioning
$operationalItems = array_values(array_filter([
    [
        'name' => 'Dashboard',
        'url' => 'index.php',
        'icon' => 'ph-squares-four',
        'badge' => null,
        'roles' => ['SUPER_ADMIN', 'SALES', 'TECHNICIAN', 'CERT_ADMIN'],
    ],
    [
        'name' => 'Sales Order (SPK)',
        'url' => 'orders.php',
        'icon' => 'ph-clipboard-text',
        'badge' => null,
        'roles' => ['SUPER_ADMIN', 'SALES'],
    ],
], function($item) {
    return hasRole($item['roles']);
}));

$technicalItems = array_values(array_filter([
    [
        'name' => 'Worksheet Teknisi',
        'url' => 'worksheet.php',
        'icon' => 'ph-wrench',
        'badge' => $pendingWorksheetCount > 0 ? [
            'label' => (string)$pendingWorksheetCount,
            'class' => 'badge-status warning'
        ] : null,
        'roles' => ['SUPER_ADMIN', 'TECHNICIAN'],
    ],
    [
        'name' => 'Penerbitan Sertifikat',
        'url' => 'certificates.php',
        'icon' => 'ph-certificate',
        'badge' => $pendingCertCount > 0 ? [
            'label' => (string)$pendingCertCount,
            'class' => 'badge-status danger'
        ] : null,
        'roles' => ['SUPER_ADMIN', 'CERT_ADMIN'],
    ],
], function($item) {
    return hasRole($item['roles']);
}));

$navSections = [];

if (!empty($operationalItems)) {
    $navSections[] = [
        'title' => 'OPERASIONAL INTI',
        'items' => $operationalItems
    ];
}

if (!empty($technicalItems)) {
    $navSections[] = [
        'title' => 'LABORATORIUM & PENERBITAN',
        'items' => $technicalItems
    ];
}

// Administrator module for Super Admin
if (hasRole('SUPER_ADMIN')) {
    $navSections[] = [
        'title' => 'ADMINISTRASI SISTEM',
        'items' => [
            [
                'name' => 'Kelola Karyawan',
                'url' => 'users.php',
                'icon' => 'ph-users-three',
                'badge' => null,
            ],
        ]
    ];
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' | ' : '' ?>Kalpindo CalibFlow - Sistem Internal Kalibrasi</title>
    
    <!-- Dark Mode Immediate Anti-Flicker Script (Synchronized with Bootstrap 5) -->
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

    <!-- 100% Full Local Stylesheets (Zero External CDNs) -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=5.3.3">
    <link rel="stylesheet" href="assets/css/phosphor.css?v=2.1.1">
    <link rel="stylesheet" href="assets/css/custom.css?v=<?= filemtime(__DIR__ . '/../assets/css/custom.css') ?>">
    <link rel="stylesheet" href="assets/css/print.css?v=<?= filemtime(__DIR__ . '/../assets/css/print.css') ?>" media="print">

    <style>
        /* Core Structural Layout - High Resilience Lock */
        body {
            min-height: 100vh;
            background-color: var(--bg-app);
            color: var(--text-primary);
            margin: 0;
            padding: 0;
        }
        .sidebar-desktop {
            width: 17rem !important;
            position: fixed !important;
            top: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            z-index: 1030 !important;
            background-color: var(--surface-header) !important;
            border-right: 1px solid var(--border-subtle) !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
        }
        .app-main-wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }
        @media (min-width: 992px) {
            .app-main-wrapper {
                margin-left: 17rem !important;
            }
        }
        @media (max-width: 991.98px) {
            .sidebar-desktop {
                display: none !important;
            }
            .app-main-wrapper {
                margin-left: 0 !important;
            }
        }
        #mobile-sidebar-backdrop {
            position: fixed;
            inset: 0;
            z-index: 1040;
            background-color: rgba(11, 15, 23, 0.65);
            display: none;
        }
        #mobile-sidebar {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 1050;
            width: 18rem;
            background-color: var(--surface-header);
            border-right: 1px solid var(--border-subtle);
            display: none;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transition: transform 0.25s ease-in-out;
            transform: translateX(-100%);
        }
        #mobile-sidebar.show-drawer {
            transform: translateX(0) !important;
        }
    </style>
</head>
<body class="min-vh-100">

    <!-- ========================================================== -->
    <!-- 1. MOBILE DRAWER SIDEBAR (Slide-over with Backdrop)         -->
    <!-- ========================================================== -->
    <div id="mobile-sidebar-backdrop" onclick="closeMobileSidebar()"></div>
    
    <aside id="mobile-sidebar" class="d-lg-none">
        
        <!-- Mobile Sidebar Top -->
        <div>
            <!-- Header Brand & Close Button -->
            <div class="px-4 border-b flex items-center justify-between" style="height: 4rem;">
                <a href="index.php" class="flex items-center">
                    <img src="assets/img/logo.png" alt="Logo PT Kalpindo Kalibrasi" style="height: 2rem; object-fit: contain;">
                </a>
                <div class="flex items-center gap-1">
                    <button type="button" onclick="toggleTheme()" class="theme-toggle-btn btn-icon" title="Mode Gelap / Terang" aria-label="Toggle dark mode">
                        <i class="ph-bold ph-moon theme-icon-moon text-base"></i>
                        <i class="ph-bold ph-sun theme-icon-sun text-base text-warning"></i>
                    </button>
                    <button type="button" onclick="closeMobileSidebar()" class="btn-icon">
                        <i class="ph-bold ph-x text-lg"></i>
                    </button>
                </div>
            </div>

            <!-- Accreditation Sub-banner -->
            <div class="px-4 py-2 border-b flex items-center justify-between text-[10px] font-mono" style="background-color: var(--surface-subtle); border-color: var(--border-subtle);">
                <span class="text-secondary fw-semibold">KAN LK-088-IDN</span>
                <span class="text-success fw-bold flex items-center gap-1">
                    <span class="badge-dot bg-success"></span> ISO 17025
                </span>
            </div>

            <!-- Navigation Links -->
            <div class="px-3 py-4 space-y-4 overflow-y-auto" style="max-height: calc(100vh - 220px);">
                <?php foreach ($navSections as $sec): ?>
                    <div>
                        <span class="px-3 text-[10px] fw-bold tracking-wider text-muted text-uppercase d-block mb-1 font-mono">
                            <?= $sec['title'] ?>
                        </span>
                        <div class="space-y-1">
                            <?php foreach ($sec['items'] as $item): 
                                $isActive = ($currentPage === $item['url']);
                            ?>
                                <a href="<?= $item['url'] ?>" class="flex items-center justify-between px-3 py-2 rounded-lg text-xs transition-all <?= $isActive ? 'fw-bold' : '' ?>" style="<?= $isActive ? 'background-color: rgba(200, 30, 38, 0.12); color: var(--brand-primary); border-left: 3px solid var(--brand-primary);' : 'color: var(--text-secondary);' ?>">
                                    <div class="flex items-center gap-2">
                                        <i class="ph-bold <?= $item['icon'] ?> text-base" style="<?= $isActive ? 'color: var(--brand-primary);' : 'color: var(--text-muted);' ?>"></i>
                                        <span><?= $item['name'] ?></span>
                                    </div>
                                    <?php if ($item['badge']): ?>
                                        <span class="<?= $item['badge']['class'] ?>">
                                            <?= $item['badge']['label'] ?>
                                        </span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Mobile Sidebar Footer (User Info) -->
        <div class="p-3 border-t" style="background-color: var(--surface-subtle); border-color: var(--border-subtle);">
            <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl border" style="background-color: var(--surface-card); border-color: var(--border-subtle);">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="rounded-lg <?= $isMasterSetupAdmin ? 'bg-primary' : 'bg-dark text-white' ?> fw-bold flex items-center justify-center text-xs shrink-0 shadow-2xs" style="width: 2rem; height: 2rem;">
                        <?= $isMasterSetupAdmin ? 'AD' : $userInitials ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs fw-bold mb-0 text-truncate"><?= htmlspecialchars($currentUser['full_name']) ?></p>
                        <p class="text-[10px] text-muted mb-0 text-truncate">
                            <?= $isMasterSetupAdmin ? 'Master Setup Sistem' : htmlspecialchars($activeRoleInfo['name']) ?>
                        </p>
                    </div>
                </div>
                <a href="logout.php" title="Keluar / Logout" class="btn-icon" style="color: var(--brand-primary);">
                    <i class="ph-bold ph-sign-out text-base"></i>
                </a>
            </div>
        </div>

    </aside>

    <!-- ========================================================== -->
    <!-- 2. DESKTOP FIXED LEFT SIDEBAR                              -->
    <!-- ========================================================== -->
    <aside class="no-print sidebar-desktop d-none d-lg-flex flex-column justify-content-between position-fixed top-0 bottom-0 start-0">
        
        <!-- Desktop Sidebar Top -->
        <div class="flex flex-col flex-1 min-h-0">
            
            <!-- Header Brand -->
            <div class="px-4 border-b flex items-center shrink-0" style="height: 4rem; border-color: var(--border-subtle);">
                <a href="index.php" class="flex items-center">
                    <img src="assets/img/logo.png" alt="Logo PT Kalpindo Kalibrasi" style="height: 2rem; object-fit: contain;">
                </a>
            </div>

            <!-- Accreditation Status Ribbon -->
            <div class="px-4 py-2 border-b flex items-center justify-between text-[10px] font-mono shrink-0" style="background-color: var(--surface-subtle); border-color: var(--border-subtle);">
                <span class="text-secondary fw-semibold flex items-center gap-1">
                    <i class="ph-bold ph-shield-check text-muted"></i> KAN LK-088-IDN
                </span>
                <span class="text-success fw-bold flex items-center gap-1">
                    <span class="badge-dot bg-success"></span> ISO 17025
                </span>
            </div>

            <!-- Scrollable Navigation Area -->
            <div class="flex-1 px-3 py-4 space-y-5 overflow-y-auto">
                <?php foreach ($navSections as $sec): ?>
                    <div>
                        <span class="px-3 text-[10px] fw-bold tracking-wider text-muted text-uppercase d-block mb-1.5 font-mono">
                            <?= $sec['title'] ?>
                        </span>
                        <div class="space-y-1">
                            <?php foreach ($sec['items'] as $item): 
                                $isActive = ($currentPage === $item['url']);
                            ?>
                                <a href="<?= $item['url'] ?>" class="flex items-center justify-between px-3 py-2 rounded-lg text-xs transition-all <?= $isActive ? 'fw-bold' : '' ?>" style="<?= $isActive ? 'background-color: rgba(200, 30, 38, 0.1); color: var(--brand-primary); border-left: 3px solid var(--brand-primary);' : 'color: var(--text-secondary);' ?>">
                                    <div class="flex items-center gap-2.5">
                                        <i class="ph-bold <?= $item['icon'] ?> text-base shrink-0" style="<?= $isActive ? 'color: var(--brand-primary);' : 'color: var(--text-muted);' ?>"></i>
                                        <span class="text-truncate"><?= $item['name'] ?></span>
                                    </div>
                                    <?php if ($item['badge']): ?>
                                        <span class="<?= $item['badge']['class'] ?>">
                                            <?= $item['badge']['label'] ?>
                                        </span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- Desktop Sidebar User Profile Footer Card -->
        <div class="p-3 border-t shrink-0" style="background-color: var(--surface-subtle); border-color: var(--border-subtle);">
            <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl border" style="background-color: var(--surface-card); border-color: var(--border-subtle);">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="rounded-lg <?= $isMasterSetupAdmin ? 'bg-primary text-white' : 'bg-dark text-white' ?> fw-bold flex items-center justify-center text-xs shrink-0 shadow-2xs" style="width: 2rem; height: 2rem;">
                        <?= $isMasterSetupAdmin ? 'AD' : $userInitials ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs fw-bold mb-0 text-truncate" style="color: var(--text-primary);"><?= htmlspecialchars($currentUser['full_name']) ?></p>
                        <p class="text-[10px] mb-0 text-truncate" style="color: var(--text-muted);">
                            <?= $isMasterSetupAdmin ? 'Master Setup Sistem' : htmlspecialchars($activeRoleInfo['name']) ?>
                        </p>
                    </div>
                </div>
                <a href="logout.php" title="Keluar / Logout Akun" class="btn-icon" style="color: var(--brand-primary);" aria-label="Logout">
                    <i class="ph-bold ph-sign-out text-base"></i>
                </a>
            </div>
        </div>

    </aside>

    <!-- ========================================================== -->
    <!-- 3. MAIN APPLICATION WRAPPER (Fluid with Sidebar Offset)    -->
    <!-- ========================================================== -->
    <div class="app-main-wrapper">

        <!-- Top Header Toolbar (Elevated & Functional) -->
        <header class="no-print top-navbar">
            
            <!-- Left: Mobile Toggle & Page Context Title -->
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" onclick="toggleMobileSidebar()" class="btn-icon d-lg-none" aria-label="Menu Utama">
                    <i class="ph-bold ph-list text-xl"></i>
                </button>

                <div>
                    <div class="flex items-center gap-1.5 text-xs text-muted">
                        <span>Laboratorium Kalibrasi</span>
                        <i class="ph-bold ph-caret-right text-[10px] text-muted"></i>
                        <span class="fw-bold" style="color: var(--text-primary);"><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Dashboard Operasional' ?></span>
                    </div>
                </div>
            </div>

            <!-- Right: System Indicators & Quick Actions -->
            <div class="flex items-center gap-2 gap-sm-3">
                
                <!-- Facility & KAN Accreditation Subtle Indicator -->
                <div class="d-none d-md-inline-flex align-items-center gap-2 text-xs font-mono px-2.5 py-1 rounded-md border" style="background-color: var(--surface-subtle); border-color: var(--border-subtle); color: var(--text-muted);">
                    <span class="badge-dot bg-success"></span>
                    <span class="fw-medium" style="color: var(--text-secondary);">Lab Kalibrasi</span>
                    <span>·</span>
                    <span>LK-088-IDN</span>
                </div>

                <span class="d-none d-md-inline text-muted">|</span>

                <!-- User Role Indicator -->
                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs border whitespace-nowrap fw-medium" style="background-color: var(--surface-subtle); border-color: var(--border-subtle); color: var(--text-secondary);">
                    <span class="d-none d-sm-inline"><?= htmlspecialchars($activeRoleInfo['name']) ?></span>
                    <span class="d-sm-none font-mono"><?= htmlspecialchars($activeRoleInfo['short']) ?></span>
                </div>

                <!-- Quick Action "+ Input SPK" (Visible if authorized) -->
                <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
                    <a href="orders.php?action=create" class="btn-brand-primary">
                        <i class="ph-bold ph-plus text-xs"></i>
                        <span class="d-none d-sm-inline">+ SPK Baru</span>
                    </a>
                <?php endif; ?>

                <!-- Dark / Light Mode Toggle Button -->
                <button type="button" onclick="toggleTheme()" class="theme-toggle-btn btn-icon" title="Mode Gelap / Terang" aria-label="Toggle dark mode">
                    <i class="ph-bold ph-moon theme-icon-moon text-sm"></i>
                    <i class="ph-bold ph-sun theme-icon-sun text-sm text-warning"></i>
                </button>

                <!-- Direct Logout Icon Button -->
                <a href="logout.php" title="Keluar / Logout Akun" class="btn-icon" style="color: var(--brand-primary);" aria-label="Logout">
                    <i class="ph-bold ph-sign-out text-base"></i>
                </a>

            </div>

        </header>

        <!-- Main Content Area -->
        <main class="flex-1 w-full px-3 px-sm-4 px-lg-5 py-4">

            <!-- Flash Message Notification -->
            <?php if ($flash): ?>
                <div id="flash-alert" class="mb-4 p-3 rounded-xl border flex items-center justify-between gap-3 shadow-subtle transition-all" style="background-color: var(--surface-card); border-color: <?= $flash['type'] === 'success' ? 'var(--status-success-border)' : ($flash['type'] === 'error' ? 'var(--brand-primary)' : 'var(--border-strong)') ?>; color: var(--text-primary);">
                    <div class="flex items-center gap-2.5">
                        <span class="badge-dot <?= $flash['type'] === 'success' ? 'bg-success' : 'bg-danger' ?>"></span>
                        <p class="fw-medium text-xs text-sm mb-0"><?= $flash['message'] ?></p>
                    </div>
                    <button onclick="document.getElementById('flash-alert').remove()" class="btn-icon border-0" style="width: 1.5rem; height: 1.5rem; font-size: 1rem;">&times;</button>
                </div>
            <?php endif; ?>
