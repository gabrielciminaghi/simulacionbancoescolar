<?php
require __DIR__ . '/comun.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $_SESSION = [];
    session_destroy();
}
header('Location: index.php');
exit;
