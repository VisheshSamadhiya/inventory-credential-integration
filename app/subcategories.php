<?php
require_once __DIR__ . '/auth.php';
requirePermission('categories', 'view');
header('Location: categories.php');
exit;
