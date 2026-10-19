<?php
declare(strict_types=1);

// Keep creation validation, permissions, and persistence in the shared Users controller.
$usersPageMode = 'create';
require __DIR__ . '/users.php';
