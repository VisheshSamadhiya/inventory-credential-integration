<?php
require_once __DIR__ . '/auth.php';
if (currentUser()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
