<?php
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . (custodia_current_user() ? 'dashboard.php' : 'login.php'));
exit;
