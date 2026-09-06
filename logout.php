<?php
/**
 * Logout Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

logoutUser();
setFlash('success', 'Anda telah berhasil keluar dari sistem.');
header('Location: login.php');
exit;
