<?php
// Crea la base de datos, las tablas y los datos de ejemplo.
// Uso:  php instalar.php            (o abrir instalar.php en el navegador)
//       php instalar.php --forzar   (borra y recrea todo con los datos de ejemplo)
require __DIR__ . '/config.php';

$cli = PHP_SAPI === 'cli';
function salida(string $m, bool $cli): void {
    echo $cli ? $m . PHP_EOL : '<p>' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</p>';
}
if (!$cli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Instalación</title><body style="font-family:sans-serif;max-width:640px;margin:40px auto">';
}
$forzar = $cli ? in_array('--forzar', $argv ?? [], true) : isset($_GET['forzar']);

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    $existe = $pdo->query("SHOW TABLES LIKE 'clientes'")->fetch();

    try { $actual = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'rol'")->fetch(); } catch (PDOException $e) { $actual = false; }
    if ($existe && !$actual && !$forzar) {
        salida('Se detectó una versión anterior de la base de datos. Se actualizará con el inicio de sesión (los datos de ejemplo se recrean).', $cli);
        $forzar = true;
    }

    if ($existe && !$forzar) {
        salida('La base de datos ya está instalada. Para reiniciarla con los datos de ejemplo usa "php instalar.php --forzar" (o instalar.php?forzar=1). Esto borra los cambios hechos.', $cli);
    } else {
        $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
        salida('Base de datos "' . DB_NAME . '" lista con datos de ejemplo.', $cli);
    }
} catch (PDOException $ex) {
    salida('No se pudo instalar: ' . $ex->getMessage() . ' Verifica que MySQL esté encendido y revisa config.php.', $cli);
    exit(1);
}
