<?php
/**
 * Header Template - Enterprise Internal System
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Sertifikasi
 * Professional Left Sidebar Dashboard Architecture
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
    'badge_class' => 'bg-gray-100 text-gray-800 border-gray-200',
    'icon' => 'ph-user'
];

$nameParts = explode(' ', trim($currentUser['full_name']));
$userInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
$isMasterSetupAdmin = ($currentUser['username'] === 'admin');

$currentPage = basename($_SERVER['PHP_SELF']);
$flash = getFlash();

$pendingWorksheetCount = (int)$dbHeader->query("SELECT COUNT(*) FROM instruments WHERE status = 'ASSIGNED' OR status = 'IN_PROGRESS'")->fetchColumn();
$pendingCertCount = (int)$dbHeader->query("SELECT COUNT(*) FROM instruments WHERE status = 'DATA_SUBMITTED'")->fetchColumn();

// Strict Role-Based Operational Navigation Map
$allMenuItems = [
    [
        'name' => 'Dashboard',
        'url' => 'index.php',
        'icon' => 'ph-squares-four',
        'badge' => null,
        'roles' => ['SUPER_ADMIN', 'SALES', 'TECHNICIAN', 'CERT_ADMIN'],
    ],
    [
        'name' => 'Sales Order',
        'url' => 'orders.php',
        'icon' => 'ph-clipboard-text',
        'badge' => null,
        'roles' => ['SUPER_ADMIN', 'SALES'],
    ],
    [
        'name' => 'Worksheet Teknisi',
        'url' => 'worksheet.php',
        'icon' => 'ph-wrench',
        'badge' => $pendingWorksheetCount > 0 ? [
            'label' => (string)$pendingWorksheetCount,
            'class' => 'bg-amber-100 text-amber-800 border border-amber-200'
        ] : null,
        'roles' => ['SUPER_ADMIN', 'TECHNICIAN'],
    ],
    [
        'name' => 'Penerbitan Sertifikat',
        'url' => 'certificates.php',
        'icon' => 'ph-certificate',
        'badge' => $pendingCertCount > 0 ? [
            'label' => (string)$pendingCertCount,
            'class' => 'bg-[#C81E26] text-white animate-pulse'
        ] : null,
        'roles' => ['SUPER_ADMIN', 'CERT_ADMIN'],
    ],
];

$accessibleMenuItems = array_values(array_filter($allMenuItems, function($item) {
    return hasRole($item['roles']);
}));

$navSections = [
    [
        'title' => 'MENU OPERASIONAL',
        'items' => $accessibleMenuItems
    ]
];

// Add Administrator module for Master Admin
if (hasRole('SUPER_ADMIN')) {
    $navSections[] = [
        'title' => 'ADMINISTRATOR',
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
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' | ' : '' ?>Kalpindo CalibFlow - Sistem Internal Kalibrasi</title>
    
    <!-- Fonts: Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        brand: {
                            red: '#C81E26',
                            redDark: '#A8141B',
                            orange: '#F59D3F',
                            slate: '#0F172A',
                            dark: '#1E293B',
                            grayBg: '#F8FAFC',
                        }
                    },
                    boxShadow: {
                        '2xs': '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
                        'xs': '0 1px 3px 0 rgba(0, 0, 0, 0.08), 0 1px 2px 0 rgba(0, 0, 0, 0.04)',
                    }
                }
            }
        }
    </script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="assets/css/print.css">
</head>
<body class="bg-[#F8FAFC] text-slate-800 font-sans antialiased min-h-screen flex text-sm selection:bg-red-100 selection:text-red-800">

    <!-- ========================================================== -->
    <!-- 1. MOBILE DRAWER SIDEBAR (Slide-over with Backdrop)         -->
    <!-- ========================================================== -->
    <div id="mobile-sidebar-backdrop" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs z-50 hidden transition-opacity duration-300" onclick="closeMobileSidebar()"></div>
    
    <aside id="mobile-sidebar" class="fixed inset-y-0 left-0 z-50 w-72 bg-white border-r border-slate-200 shadow-2xl flex flex-col justify-between transform -translate-x-full transition-transform duration-300 ease-in-out lg:hidden">
        
        <!-- Mobile Sidebar Top -->
        <div>
            <!-- Header Brand & Close Button -->
            <div class="h-16 px-5 border-b border-slate-200 flex items-center justify-between">
                <a href="index.php" class="flex items-center">
                    <img src="assets/img/logo.png" alt="Logo PT Kalpindo Kalibrasi" class="h-8 object-contain">
                </a>
                <button type="button" onclick="closeMobileSidebar()" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg">
                    <i class="ph-bold ph-x text-lg"></i>
                </button>
            </div>

            <!-- Accreditation Sub-banner -->
            <div class="px-5 py-2.5 bg-slate-50 border-b border-slate-100 flex items-center justify-between text-[10px] font-mono">
                <span class="text-slate-600 font-semibold">KAN LK-088-IDN</span>
                <span class="text-emerald-700 font-bold flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> ISO 17025
                </span>
            </div>

            <!-- Navigation Links -->
            <div class="px-3 py-4 space-y-5 overflow-y-auto max-h-[calc(100vh-220px)]">
                <?php foreach ($navSections as $sec): ?>
                    <div>
                        <span class="px-3 text-[10px] font-bold tracking-wider text-slate-400 uppercase block mb-1.5 font-mono">
                            <?= $sec['title'] ?>
                        </span>
                        <div class="space-y-1">
                            <?php foreach ($sec['items'] as $item): 
                                $isActive = ($currentPage === $item['url']);
                            ?>
                                <a href="<?= $item['url'] ?>" class="flex items-center justify-between px-3 py-2.5 rounded-xl text-xs font-semibold transition-all <?= $isActive ? 'bg-red-50 text-[#C81E26] border border-red-200/80 shadow-2xs font-bold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100' ?>">
                                    <div class="flex items-center gap-2.5">
                                        <i class="ph-bold <?= $item['icon'] ?> text-base <?= $isActive ? 'text-[#C81E26]' : 'text-slate-400' ?>"></i>
                                        <span><?= $item['name'] ?></span>
                                    </div>
                                    <?php if ($item['badge']): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $item['badge']['class'] ?>">
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
        <div class="p-4 border-t border-slate-200 bg-slate-50/60">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-xl <?= $isMasterSetupAdmin ? 'bg-purple-700' : 'bg-slate-900' ?> text-white font-bold flex items-center justify-center text-xs shrink-0 shadow-2xs">
                        <?= $isMasterSetupAdmin ? 'AD' : $userInitials ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-slate-900 truncate"><?= htmlspecialchars($currentUser['full_name']) ?></p>
                        <p class="text-[10px] <?= $isMasterSetupAdmin ? 'text-purple-700 font-bold' : 'text-slate-500' ?> truncate">
                            <?= $isMasterSetupAdmin ? 'Master Setup Sistem' : htmlspecialchars($activeRoleInfo['name']) ?>
                        </p>
                    </div>
                </div>
                <a href="logout.php" title="Keluar / Logout" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors shrink-0">
                    <i class="ph-bold ph-sign-out text-base"></i>
                </a>
            </div>
        </div>

    </aside>

    <!-- ========================================================== -->
    <!-- 2. DESKTOP FIXED LEFT SIDEBAR                              -->
    <!-- ========================================================== -->
    <aside class="no-print hidden lg:flex lg:flex-col lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:w-64 xl:w-72 bg-white border-r border-slate-200 justify-between shadow-2xs">
        
        <!-- Desktop Sidebar Top -->
        <div class="flex flex-col flex-1 min-h-0">
            
            <!-- Header Brand -->
            <div class="h-16 px-5 border-b border-slate-200 flex items-center shrink-0">
                <a href="index.php" class="flex items-center">
                    <img src="assets/img/logo.png" alt="Logo PT Kalpindo Kalibrasi" class="h-8 object-contain">
                </a>
            </div>

            <!-- Accreditation Status Ribbon -->
            <div class="px-5 py-2 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between text-[10px] font-mono shrink-0">
                <span class="text-slate-600 font-semibold flex items-center gap-1">
                    <i class="ph-bold ph-shield-check text-slate-500"></i> KAN LK-088-IDN
                </span>
                <span class="text-emerald-700 font-bold flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> ISO 17025
                </span>
            </div>

            <!-- Scrollable Navigation Area -->
            <div class="flex-1 px-3 py-4 space-y-6 overflow-y-auto">
                <?php foreach ($navSections as $sec): ?>
                    <div>
                        <span class="px-3 text-[10px] font-bold tracking-wider text-slate-400 uppercase block mb-1.5 font-mono">
                            <?= $sec['title'] ?>
                        </span>
                        <div class="space-y-1">
                            <?php foreach ($sec['items'] as $item): 
                                $isActive = ($currentPage === $item['url']);
                            ?>
                                <a href="<?= $item['url'] ?>" class="flex items-center justify-between px-3 py-2 rounded-xl text-xs transition-all <?= $isActive ? 'bg-red-50 text-[#C81E26] font-bold border border-red-200/80 shadow-2xs' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium' ?>">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <i class="ph-bold <?= $item['icon'] ?> text-base shrink-0 <?= $isActive ? 'text-[#C81E26]' : 'text-slate-400' ?>"></i>
                                        <span class="truncate"><?= $item['name'] ?></span>
                                    </div>
                                    <?php if ($item['badge']): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold shrink-0 <?= $item['badge']['class'] ?>">
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
        <div class="p-3.5 border-t border-slate-200 bg-slate-50/70 shrink-0">
            <div class="flex items-center justify-between gap-2 bg-white p-2.5 rounded-xl border border-slate-200/80 shadow-2xs">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-lg <?= $isMasterSetupAdmin ? 'bg-purple-700' : 'bg-slate-900' ?> text-white font-bold flex items-center justify-center text-xs shrink-0 shadow-2xs">
                        <?= $isMasterSetupAdmin ? 'AD' : $userInitials ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-bold text-slate-900 truncate leading-tight"><?= htmlspecialchars($currentUser['full_name']) ?></p>
                        <p class="text-[10px] <?= $isMasterSetupAdmin ? 'text-purple-700 font-bold' : 'text-slate-500' ?> truncate mt-0.5">
                            <?= $isMasterSetupAdmin ? 'Master Setup Sistem' : htmlspecialchars($activeRoleInfo['name']) ?>
                        </p>
                    </div>
                </div>
                <a href="logout.php" title="Keluar / Logout Akun" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors shrink-0" aria-label="Logout">
                    <i class="ph-bold ph-sign-out text-base"></i>
                </a>
            </div>
        </div>

    </aside>

    <!-- ========================================================== -->
    <!-- 3. MAIN APPLICATION WRAPPER (Fluid with Sidebar Offset)    -->
    <!-- ========================================================== -->
    <div class="flex-1 lg:pl-64 xl:pl-72 flex flex-col min-h-screen min-w-0">

        <!-- Top Header Toolbar (Elevated & Functional) -->
        <header class="no-print sticky top-0 z-20 bg-white/95 backdrop-blur-sm border-b border-slate-200 h-16 flex items-center justify-between px-4 sm:px-6 lg:px-8 shadow-2xs">
            
            <!-- Left: Mobile Toggle & Page Context Title -->
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" onclick="toggleMobileSidebar()" class="lg:hidden p-2 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-100 transition-colors" aria-label="Menu Utama">
                    <i class="ph-bold ph-list text-xl"></i>
                </button>

                <div>
                    <div class="flex items-center gap-1.5 text-xs text-slate-500">
                        <span>Portal Karyawan</span>
                        <i class="ph-bold ph-caret-right text-[10px] text-slate-400"></i>
                        <span class="font-bold text-slate-900 truncate"><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Dashboard Operasional' ?></span>
                    </div>
                </div>
            </div>

            <!-- Right: System Indicators & Quick Actions -->
            <div class="flex items-center gap-2 sm:gap-3">
                
                <!-- KAN Status Badge -->
                <span class="hidden md:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200 font-mono">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Live LK-088-IDN</span>
                </span>

                <!-- Role Badge -->
                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs border <?= $activeRoleInfo['badge_class'] ?> whitespace-nowrap shadow-2xs font-semibold">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                    <span class="hidden sm:inline"><?= htmlspecialchars($activeRoleInfo['name']) ?></span>
                    <span class="sm:hidden font-mono"><?= htmlspecialchars($activeRoleInfo['short']) ?></span>
                </div>

                <!-- Quick Action "+ Input Order" (Visible if authorized) -->
                <?php if (hasRole(['SUPER_ADMIN', 'SALES'])): ?>
                    <a href="orders.php?action=create" class="bg-[#C81E26] hover:bg-[#A8141B] active:scale-[0.98] text-white px-3 py-1.5 rounded-xl text-xs font-bold inline-flex items-center gap-1.5 shadow-sm transition-all whitespace-nowrap shrink-0">
                        <i class="ph-bold ph-plus"></i>
                        <span class="hidden sm:inline">Input Order</span>
                    </a>
                <?php endif; ?>

                <!-- Direct Logout Icon Button -->
                <a href="logout.php" title="Keluar / Logout" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-xl border border-slate-200 transition-colors shrink-0" aria-label="Logout">
                    <i class="ph-bold ph-sign-out text-base"></i>
                </a>

            </div>

        </header>

        <!-- Main Content Area -->
        <main class="flex-1 w-full px-4 sm:px-6 lg:px-8 py-6">

            <!-- Flash Message Notification -->
            <?php if ($flash): ?>
                <div id="flash-alert" class="mb-5 p-3.5 rounded-2xl border flex items-center justify-between gap-3 shadow-xs transition-all <?= $flash['type'] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : ($flash['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-900' : 'bg-blue-50 border-blue-200 text-blue-900') ?>">
                    <div class="flex items-center gap-2.5">
                        <?php if ($flash['type'] === 'success'): ?>
                            <i class="ph-fill ph-check-circle text-lg text-emerald-600"></i>
                        <?php else: ?>
                            <i class="ph-fill ph-warning-circle text-lg text-[#C81E26]"></i>
                        <?php endif; ?>
                        <p class="font-medium text-xs sm:text-sm"><?= htmlspecialchars($flash['message']) ?></p>
                    </div>
                    <button onclick="document.getElementById('flash-alert').remove()" class="text-gray-400 hover:text-gray-700 font-bold p-1">&times;</button>
                </div>
            <?php endif; ?>
