<?php
require_once __DIR__ . '/includes/auth.php';
custodia_logout();
header('Location: login.php');
exit;
