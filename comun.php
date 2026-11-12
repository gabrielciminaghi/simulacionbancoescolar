<?php
// Funciones compartidas por el portal público, el login y el panel de administración.
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fecha($s) { return date('d/m/Y', strtotime($s)); }
function dinero($n) { return '$' . number_format((float)$n, 2); }
function csrf_ok(): bool { return hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? ''); }
function csrf_campo(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }

// Roles y permisos por tabla: v = ver, a = agregar, e = eliminar, s = cambiar estado.
const ROLES = ['gerente' => 'Gerente', 'asesor' => 'Asesor de ventas', 'cajero' => 'Cajero'];
const PERMISOS = [
    'gerente' => ['clientes' => 'vae', 'trabajadores' => 'vae', 'cuentas' => 'vae', 'movimientos' => 'vae', 'solicitudes' => 'vaes'],
    'asesor'  => ['clientes' => 'va',  'cuentas' => 'va', 'solicitudes' => 'va'],
    'cajero'  => ['clientes' => 'v',   'cuentas' => 'v',  'movimientos' => 'va'],
];
function puede(string $tabla, string $accion): bool {
    $rol = $_SESSION['admin']['rol'] ?? '';
    return strpos(PERMISOS[$rol][$tabla] ?? '', $accion) !== false;
}

function exigir_admin(): void {
    if (empty($_SESSION['admin']['rol'])) { header('Location: login.php'); exit; }
}

// Bloqueo simple tras 5 intentos fallidos (60 s), guardado en la sesión.
function bloqueado(string $k): int { return max(0, ($_SESSION['bloq'][$k] ?? 0) - time()); }
function fallar(string $k): void {
    $n = ($_SESSION['fallos'][$k] ?? 0) + 1;
    $_SESSION['fallos'][$k] = $n;
    if ($n >= 5) { $_SESSION['bloq'][$k] = time() + 60; $_SESSION['fallos'][$k] = 0; }
}
function acierto(string $k): void { unset($_SESSION['fallos'][$k], $_SESSION['bloq'][$k]); }

function siguienteId($tabla) {
    $f = ['clientes'=>['C-','%03d'],'trabajadores'=>['T-','%02d'],'cuentas'=>['A-','%d'],'movimientos'=>['M-','%04d'],'solicitudes'=>['S-','%03d']][$tabla];
    $n = (int) db()->query("SELECT MAX(CAST(SUBSTRING(id,3) AS UNSIGNED)) FROM $tabla")->fetchColumn();
    if ($tabla === 'cuentas') $n = max($n, 1000);
    return $f[0] . sprintf($f[1], $n + 1);
}

function banner_simulacion(): string {
    return '<div class="sim-banner" role="note">Proyecto educativo de simulación · Todos los datos son ficticios · No escribas datos personales ni bancarios reales</div>';
}
