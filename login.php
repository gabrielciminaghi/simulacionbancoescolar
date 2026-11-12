<?php
require __DIR__ . '/comun.php';
if (!empty($_SESSION['admin'])) { header('Location: admin.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $espera = bloqueado('admin');
    if (!csrf_ok()) {
        $error = 'La sesión expiró. Intenta de nuevo.';
    } elseif ($espera > 0) {
        $error = "Demasiados intentos. Espera $espera segundos.";
    } else {
        try {
            $st = db()->prepare('SELECT id, pass_hash, nombre, rol FROM usuarios WHERE usuario = ?');
            $st->execute([trim($_POST['usuario'] ?? '')]);
            $u = $st->fetch();
            if ($u && password_verify($_POST['clave'] ?? '', $u['pass_hash'])) {
                session_regenerate_id(true);
                acierto('admin');
                $_SESSION['admin'] = ['id' => $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
                header('Location: admin.php');
                exit;
            }
            fallar('admin');
            $error = 'Usuario o contraseña incorrectos.';
        } catch (PDOException $ex) {
            $error = 'No se pudo conectar a la base de datos. Si acabas de actualizar el sitio, ejecuta "php instalar.php" (o abre instalar.php en el navegador) y revisa config.php.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acceso del personal · SimuBanco</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="estilos.css">
</head>
<body>
<?= banner_simulacion() ?>
<header class="top">
  <div class="brand"><div class="logo" aria-hidden="true">SB</div>
  <div><strong>SimuBanco</strong><small>Acceso del personal</small></div></div>
  <div class="user"><a href="index.php">Ir al portal público</a></div>
</header>
<main class="centrado">
  <section class="panel caja">
    <h2>Iniciar sesión</h2>
    <p class="note">Solo para personal autorizado del banco.</p>
    <?php if ($error): ?><div class="aviso" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="vertical">
      <?= csrf_campo() ?>
      <label>Usuario<input name="usuario" autocomplete="username" required autofocus></label>
      <label>Contraseña<input type="password" name="clave" autocomplete="current-password" required></label>
      <button>Entrar al sistema</button>
    </form>
  </section>
</main>
<footer>SimuBanco · Sistema de simulación con datos ficticios, solo con fines educativos.</footer>
</body>
</html>
