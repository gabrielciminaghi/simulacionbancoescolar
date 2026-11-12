<?php
require __DIR__ . '/comun.php';

$aviso = $_SESSION['aviso'] ?? '';
unset($_SESSION['aviso']);
$error = '';
$plazos = [12, 24, 36, 48, 60];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = $_POST['accion'] ?? '';
        if (!csrf_ok()) {
            $error = 'La sesión expiró. Intenta de nuevo.';
        } elseif ($accion === 'entrar') {
            $espera = bloqueado('cliente');
            if ($espera > 0) {
                $error = "Demasiados intentos. Espera $espera segundos.";
            } else {
                $st = db()->prepare('SELECT a.cliente_id, c.nip_hash FROM cuentas a JOIN clientes c ON c.id = a.cliente_id WHERE a.id = ?');
                $st->execute([strtoupper(trim($_POST['cuenta'] ?? ''))]);
                $r = $st->fetch();
                if ($r && $r['nip_hash'] && password_verify($_POST['nip'] ?? '', $r['nip_hash'])) {
                    session_regenerate_id(true);
                    acierto('cliente');
                    $_SESSION['cliente'] = $r['cliente_id'];
                    header('Location: index.php');
                    exit;
                }
                fallar('cliente');
                $error = 'Número de cuenta o NIP incorrectos.';
            }
        } elseif ($accion === 'solicitar' && !empty($_SESSION['cliente'])) {
            $monto = $_POST['monto'] ?? '';
            $plazo = (int)($_POST['plazo'] ?? 0);
            $razon = trim($_POST['razon'] ?? '');
            if (!is_numeric($monto) || $monto < 1000 || $monto > 1000000) {
                $error = 'El monto debe estar entre $1,000 y $1,000,000.';
            } elseif (!in_array($plazo, $plazos, true)) {
                $error = 'Elige un plazo válido.';
            } elseif (mb_strlen($razon) < 5 || mb_strlen($razon) > 150) {
                $error = 'Describe el motivo en 5 a 150 caracteres.';
            } else {
                $ej = db()->prepare('SELECT ejecutivo_id FROM clientes WHERE id = ?');
                $ej->execute([$_SESSION['cliente']]);
                $nuevo = siguienteId('solicitudes');
                db()->prepare("INSERT INTO solicitudes (id, cliente_id, monto, plazo_meses, razon, fecha, estado, analista_id) VALUES (?,?,?,?,?,CURDATE(),'En revisión',?)")
                    ->execute([$nuevo, $_SESSION['cliente'], $monto, $plazo, $razon, $ej->fetchColumn()]);
                $_SESSION['aviso'] = "Solicitud $nuevo enviada. Un ejecutivo la revisará.";
                header('Location: index.php');
                exit;
            }
        }
    }

    $cliente = null;
    if (!empty($_SESSION['cliente'])) {
        $st = db()->prepare('SELECT c.id, c.nombre, t.nombre AS ejecutivo FROM clientes c JOIN trabajadores t ON t.id = c.ejecutivo_id WHERE c.id = ?');
        $st->execute([$_SESSION['cliente']]);
        $cliente = $st->fetch();
        if (!$cliente) { unset($_SESSION['cliente']); }
    }
    if ($cliente) {
        $st = db()->prepare('SELECT id, tipo, fecha_apertura, saldo FROM cuentas WHERE cliente_id = ? ORDER BY id');
        $st->execute([$cliente['id']]);
        $cuentas = $st->fetchAll();
        $st = db()->prepare('SELECT m.* FROM movimientos m JOIN cuentas a ON a.id = m.cuenta_id WHERE a.cliente_id = ? ORDER BY m.fecha DESC, m.id DESC LIMIT 10');
        $st->execute([$cliente['id']]);
        $movs = $st->fetchAll();
        $st = db()->prepare('SELECT * FROM solicitudes WHERE cliente_id = ? ORDER BY fecha DESC, id DESC');
        $st->execute([$cliente['id']]);
        $solis = $st->fetchAll();
    }
} catch (PDOException $ex) {
    http_response_code(500);
    exit('No se pudo conectar a la base de datos. Enciende MySQL, revisa config.php y ejecuta "php instalar.php".');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Banca en línea · SimuBanco</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="estilos.css">
</head>
<body>
<?= banner_simulacion() ?>
<header class="top">
  <div class="brand"><div class="logo" aria-hidden="true">SB</div>
  <div><strong>SimuBanco</strong><small>Banca en línea</small></div></div>
  <div class="user">
  <?php if ($cliente): ?>
    Hola, <b><?= e($cliente['nombre']) ?></b>
    <form method="post" action="salir.php" class="en-linea"><?= csrf_campo() ?><button class="salir">Cerrar sesión</button></form>
  <?php else: ?>
    <a href="login.php">Acceso del personal</a>
  <?php endif; ?>
  </div>
</header>

<main>
  <?php if ($aviso): ?><div class="aviso" role="status"><?= e($aviso) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="aviso" role="alert"><?= e($error) ?></div><?php endif; ?>

<?php if (!$cliente): ?>
  <section class="hero">
    <div>
      <h1>Tu dinero,<br><span class="grad">claro y al instante.</span></h1>
      <p class="lead">Consulta tus saldos, revisa tus movimientos y solicita un crédito desde un solo lugar, sin filas ni papeleo.</p>
      <div class="chips"><span>Consulta 24/7</span><span>Créditos en línea</span><span>Acceso seguro con NIP</span></div>
    </div>
    <section class="panel">
      <h3>Entra a tu cuenta</h3>
      <form method="post" class="vertical">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion" value="entrar">
        <label>Número de cuenta<input name="cuenta" placeholder="A-1001" autocomplete="username" required></label>
        <label>NIP<input type="password" name="nip" inputmode="numeric" autocomplete="current-password" required></label>
        <button>Consultar mi cuenta</button>
      </form>
      <p class="note">Simulación: prueba con la cuenta A-1001 y el NIP 1111.</p>
    </section>
  </section>

  <section class="features">
    <div class="feat"><div class="ico">$</div><h3>Saldos al día</h3><p>Ve cuánto tienes en cada cuenta en cuanto entras.</p></div>
    <div class="feat"><div class="ico">↗</div><h3>Tus movimientos</h3><p>Depósitos, retiros y transferencias, con fecha y motivo.</p></div>
    <div class="feat"><div class="ico">%</div><h3>Créditos en línea</h3><p>Envía tu solicitud y consulta en qué estado va.</p></div>
  </section>

  <section class="panel">
    <h3>Simulador de crédito</h3>
    <div class="sim-row">
      <label class="vertical">Monto<input type="number" id="m" value="100000" min="1000" step="1000"></label>
      <label class="vertical">Plazo<select id="p"><?php foreach ($plazos as $p): ?><option value="<?= $p ?>"><?= $p ?> meses</option><?php endforeach; ?></select></label>
    </div>
    <p class="sim">Pago mensual aproximado<strong id="pago"></strong></p>
    <p class="note">Cálculo ilustrativo con una tasa fija de 18% anual. No es una oferta real.</p>
  </section>

<?php else: ?>
  <div class="bar"><h2>Mi banca en línea</h2></div>
  <div class="kpis">
    <div class="kpi"><span>Saldo total</span><strong><?= dinero(array_sum(array_column($cuentas, 'saldo'))) ?></strong></div>
    <div class="kpi"><span>Cuentas activas</span><strong><?= count($cuentas) ?></strong></div>
    <div class="kpi"><span>Tu ejecutivo</span><strong class="chico"><?= e($cliente['ejecutivo']) ?></strong></div>
  </div>

  <div class="cuentas-grid">
  <?php foreach ($cuentas as $c): ?>
    <article class="cuenta">
      <span class="tipo"><?= e($c['tipo']) ?></span>
      <div class="num"><?= e($c['id']) ?></div>
      <div class="saldo"><?= dinero($c['saldo']) ?></div>
      <small>Abierta el <?= fecha($c['fecha_apertura']) ?></small>
    </article>
  <?php endforeach; ?>
  </div>

  <div class="dash">
    <div class="stack">
      <section class="panel">
        <h3>Últimos movimientos</h3>
        <div class="wrap"><table>
          <thead><tr><th>Fecha</th><th>Cuenta</th><th>Razón</th><th>Monto</th></tr></thead>
          <tbody>
          <?php if (!$movs): ?><tr><td colspan="4">Aún no tienes movimientos.</td></tr><?php endif; ?>
          <?php foreach ($movs as $m): ?>
            <tr><td><?= fecha($m['fecha']) ?></td><td><?= e($m['cuenta_id']) ?></td><td><?= e($m['razon']) ?><br><small><?= e($m['tipo']) ?></small></td>
            <td class="n<?= $m['monto'] < 0 ? ' neg' : '' ?>"><?= dinero($m['monto']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </section>
      <section class="panel">
        <h3>Mis solicitudes de crédito</h3>
        <div class="wrap"><table>
          <thead><tr><th>ID</th><th>Fecha</th><th>Monto</th><th>Plazo</th><th>Razón</th><th>Estado</th></tr></thead>
          <tbody>
          <?php if (!$solis): ?><tr><td colspan="6">No has presentado solicitudes.</td></tr><?php endif; ?>
          <?php foreach ($solis as $s): ?>
            <tr><td class="id"><?= e($s['id']) ?></td><td><?= fecha($s['fecha']) ?></td><td class="n"><?= dinero($s['monto']) ?></td><td><?= (int)$s['plazo_meses'] ?> meses</td><td><?= e($s['razon']) ?></td>
            <td><span class="tag <?= e(explode(' ', $s['estado'])[0]) ?>"><?= e($s['estado']) ?></span></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </section>
    </div>

    <div class="stack">
      <section class="panel">
        <h3>Solicitar un crédito</h3>
        <form method="post" class="vertical">
          <?= csrf_campo() ?>
          <input type="hidden" name="accion" value="solicitar">
          <label>Monto<input type="number" name="monto" min="1000" max="1000000" step="100" required></label>
          <label>Plazo<select name="plazo" required><?php foreach ($plazos as $p): ?><option value="<?= $p ?>"><?= $p ?> meses</option><?php endforeach; ?></select></label>
          <label>¿Para qué lo necesitas?<input name="razon" maxlength="150" required></label>
          <button>Enviar solicitud</button>
        </form>
      </section>
      <section class="panel">
        <h3>Simulador de crédito</h3>
        <div class="sim-row">
          <label class="vertical">Monto<input type="number" id="m" value="100000" min="1000" step="1000"></label>
          <label class="vertical">Plazo<select id="p"><?php foreach ($plazos as $p): ?><option value="<?= $p ?>"><?= $p ?> meses</option><?php endforeach; ?></select></label>
        </div>
        <p class="sim">Pago mensual aproximado<strong id="pago"></strong></p>
        <p class="note">Tasa ilustrativa fija de 18% anual. No es una oferta real.</p>
      </section>
    </div>
  </div>
<?php endif; ?>
</main>
<footer>SimuBanco · Sistema de simulación con datos ficticios, solo con fines educativos.</footer>
<script>
(function(){
  var m=document.getElementById('m'),p=document.getElementById('p'),o=document.getElementById('pago');
  var f=new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'});
  function calc(){var P=+m.value,n=+p.value,r=0.18/12;
    o.textContent=P>0?f.format(P*r/(1-Math.pow(1+r,-n))):'—';}
  m.oninput=p.onchange=calc;calc();
})();
</script>
</body>
</html>
