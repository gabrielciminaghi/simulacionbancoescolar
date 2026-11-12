<?php
require __DIR__ . '/comun.php';
exigir_admin();

// Cada tabla: título, nota, consulta (con JOIN para mostrar nombres) y columnas [campo, título, tipo, tabla_referida]
$tablas = [
  'clientes' => [
    'Clientes', 'Cada cliente tiene un ejecutivo asignado (trabajador) y una o más cuentas.',
    "SELECT c.id, c.nombre, c.telefono, c.correo, c.ciudad, c.fecha_alta, c.ejecutivo_id, t.nombre AS ejecutivo_id_txt FROM clientes c JOIN trabajadores t ON t.id = c.ejecutivo_id ORDER BY c.id",
    [['id','ID'],['nombre','Nombre'],['telefono','Teléfono'],['correo','Correo'],['ciudad','Ciudad'],['fecha_alta','Fecha de alta','fecha'],['ejecutivo_id','Ejecutivo','ref','trabajadores']],
  ],
  'trabajadores' => [
    'Trabajadores', 'Aparecen como ejecutivos de clientes, cajeros de movimientos y analistas de solicitudes.',
    "SELECT * FROM trabajadores ORDER BY id",
    [['id','ID'],['nombre','Nombre'],['puesto','Puesto'],['sucursal','Sucursal'],['correo','Correo'],['fecha_ingreso','Fecha de ingreso','fecha']],
  ],
  'cuentas' => [
    'Cuentas y saldos', 'Cada cuenta pertenece a un cliente. Sus movimientos están en la tabla Movimientos.',
    "SELECT a.*, c.nombre AS cliente_id_txt FROM cuentas a JOIN clientes c ON c.id = a.cliente_id ORDER BY a.id",
    [['id','No. de cuenta'],['cliente_id','Cliente','ref','clientes'],['tipo','Tipo'],['fecha_apertura','Fecha de apertura','fecha'],['saldo','Saldo','dinero']],
  ],
  'movimientos' => [
    'Movimientos', 'Cada movimiento afecta una cuenta y lo registró un trabajador. Los retiros y transferencias van en negativo.',
    "SELECT m.*, c.nombre AS cuenta_id_txt, t.nombre AS registrado_por_txt
       FROM movimientos m
       JOIN cuentas a ON a.id = m.cuenta_id
       JOIN clientes c ON c.id = a.cliente_id
       JOIN trabajadores t ON t.id = m.registrado_por ORDER BY m.id",
    [['id','ID'],['cuenta_id','Cuenta','ref','cuentas'],['fecha','Fecha','fecha'],['tipo','Tipo'],['monto','Monto','dinero'],['razon','Razón'],['registrado_por','Registrado por','ref','trabajadores']],
  ],
  'solicitudes' => [
    'Solicitudes de crédito', 'Cada solicitud la presenta un cliente y la atiende un trabajador (analista o gerente).',
    "SELECT s.*, c.nombre AS cliente_id_txt, t.nombre AS analista_id_txt
       FROM solicitudes s
       JOIN clientes c ON c.id = s.cliente_id
       JOIN trabajadores t ON t.id = s.analista_id ORDER BY s.id",
    [['id','ID'],['cliente_id','Cliente','ref','clientes'],['monto','Monto','dinero'],['plazo_meses','Plazo','meses'],['razon','Razón'],['fecha','Fecha','fecha'],['estado','Estado','estado'],['analista_id','Atiende','ref','trabajadores']],
  ],
];

$t  = isset($_GET['t']) && (isset($tablas[$_GET['t']]) || $_GET['t'] === 'resumen') ? $_GET['t'] : 'resumen';
$q  = trim($_GET['q'] ?? '');
$hl = $_GET['id'] ?? '';
if ($t !== 'resumen' && !puede($t, 'v')) $t = 'resumen';
[$titulo, $nota, $sql, $cols] = $tablas[$t] ?? ['Resumen general', '', '', []];


// Campos del formulario "Agregar": [campo, etiqueta, tipo de input, opciones (lista fija) o tabla referida]
$formularios = [
  'clientes' => [['nombre','Nombre','text'],['telefono','Teléfono','text'],['correo','Correo','email'],['ciudad','Ciudad','text'],['fecha_alta','Fecha de alta','date'],['ejecutivo_id','Ejecutivo','select','trabajadores'],['nip','NIP del portal (4 a 6 dígitos)','password']],
  'trabajadores' => [['nombre','Nombre','text'],['puesto','Puesto','text'],['sucursal','Sucursal','text'],['correo','Correo','email'],['fecha_ingreso','Fecha de ingreso','date']],
  'cuentas' => [['cliente_id','Cliente','select','clientes'],['tipo','Tipo','select',['Nómina','Ahorro','Cheques','Inversión']],['fecha_apertura','Fecha de apertura','date'],['saldo','Saldo inicial','number']],
  'movimientos' => [['cuenta_id','Cuenta','select','cuentas'],['fecha','Fecha','date'],['tipo','Tipo','select',['Depósito','Retiro','Transferencia']],['monto','Monto (el tipo define si suma o resta)','number'],['razon','Razón','text'],['registrado_por','Registrado por','select','trabajadores']],
  'solicitudes' => [['cliente_id','Cliente','select','clientes'],['monto','Monto','number'],['plazo_meses','Plazo (meses)','number'],['razon','Razón','text'],['fecha','Fecha','date'],['estado','Estado','select',['Aprobada','En revisión','Rechazada']],['analista_id','Atiende','select','trabajadores']],
];

function opciones($ref) {
    $sql = $ref === 'cuentas'
        ? "SELECT a.id, CONCAT(a.id,' · ',c.nombre) AS txt FROM cuentas a JOIN clientes c ON c.id = a.cliente_id ORDER BY a.id"
        : "SELECT id, CONCAT(id,' · ',nombre) AS txt FROM $ref ORDER BY id";
    return db()->query($sql)->fetchAll();
}

$puedeEditar = $t !== 'resumen' && (puede($t, 'a') || puede($t, 'e') || puede($t, 's'));
$edit  = !empty($_GET['edit']) && $puedeEditar;
$colAcc = $edit && (puede($t, 'e') || puede($t, 's'));
$ed    = $edit ? '&amp;edit=1' : '';
$aviso = $_SESSION['aviso'] ?? '';
unset($_SESSION['aviso']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tp = $_POST['t'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '') || !isset($tablas[$tp])) {
        $_SESSION['aviso'] = 'Solicitud no válida.';
        $tp = 'clientes';
    } else {
        try {
            $necesario = ['borrar' => 'e', 'agregar' => 'a', 'estado' => 's'][$_POST['accion'] ?? ''] ?? '';
            if ($necesario === '' || !puede($tp, $necesario)) throw new InvalidArgumentException('Tu rol no tiene permiso para esta acción.');
            if (($_POST['accion'] ?? '') === 'borrar') {
                $pdo = db();
                $pdo->beginTransaction();
                if ($tp === 'movimientos') { // al eliminar un movimiento se revierte su efecto en el saldo
                    $mv = $pdo->prepare('SELECT cuenta_id, monto FROM movimientos WHERE id = ? FOR UPDATE');
                    $mv->execute([$_POST['id'] ?? '']);
                    if ($r = $mv->fetch()) $pdo->prepare('UPDATE cuentas SET saldo = saldo - ? WHERE id = ?')->execute([$r['monto'], $r['cuenta_id']]);
                }
                $pdo->prepare("DELETE FROM $tp WHERE id = ?")->execute([$_POST['id'] ?? '']);
                $pdo->commit();
                $_SESSION['aviso'] = 'Registro eliminado.';
            } elseif (($_POST['accion'] ?? '') === 'estado' && $tp === 'solicitudes') {
                $nuevoEstado = $_POST['estado'] ?? '';
                if (!in_array($nuevoEstado, ['Aprobada', 'En revisión', 'Rechazada'], true)) throw new InvalidArgumentException('Estado no válido.');
                db()->prepare("UPDATE solicitudes SET estado = ? WHERE id = ?")->execute([$nuevoEstado, $_POST['id'] ?? '']);
                $_SESSION['aviso'] = 'Estado actualizado.';
            } elseif (($_POST['accion'] ?? '') === 'agregar') {
                $vals = [];
                foreach ($formularios[$tp] as $c) {
                    $v = trim($_POST[$c[0]] ?? '');
                    if ($v === '') throw new InvalidArgumentException('Completa todos los campos.');
                    if ($c[2] === 'number' && !is_numeric($v)) throw new InvalidArgumentException('“' . $c[1] . '” debe ser un número.');
                    if ($c[2] === 'select' && is_array($c[3]) && !in_array($v, $c[3], true)) throw new InvalidArgumentException('Opción no válida en “' . $c[1] . '”.');
                    if ($c[0] === 'nip' && !preg_match('/^\d{4,6}$/', $v)) throw new InvalidArgumentException('El NIP debe tener de 4 a 6 dígitos.');
                    $vals[$c[0]] = $v;
                }
                if (isset($vals['nip'])) { $vals['nip_hash'] = password_hash($vals['nip'], PASSWORD_DEFAULT); unset($vals['nip']); }
                if ($tp === 'movimientos') { // el signo lo define el tipo: depósito suma; retiro y transferencia restan
                    $abs = abs((float)$vals['monto']);
                    $vals['monto'] = in_array($vals['tipo'], ['Retiro', 'Transferencia'], true) ? -$abs : $abs;
                }
                $nuevo = siguienteId($tp);
                $cols  = array_merge(['id'], array_keys($vals));
                $sqlI  = "INSERT INTO $tp (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
                $pdo = db();
                $pdo->beginTransaction();
                if ($tp === 'movimientos') {
                    $sd = $pdo->prepare('SELECT saldo FROM cuentas WHERE id = ? FOR UPDATE');
                    $sd->execute([$vals['cuenta_id']]);
                    if ((float)$sd->fetchColumn() + $vals['monto'] < 0) { $pdo->rollBack(); throw new InvalidArgumentException('Saldo insuficiente en la cuenta.'); }
                }
                $pdo->prepare($sqlI)->execute(array_merge([$nuevo], array_values($vals)));
                if ($tp === 'movimientos') $pdo->prepare('UPDATE cuentas SET saldo = saldo + ? WHERE id = ?')->execute([$vals['monto'], $vals['cuenta_id']]);
                $pdo->commit();
                $_SESSION['aviso'] = "Registro $nuevo agregado.";
            }
        } catch (InvalidArgumentException $ex) {
            $_SESSION['aviso'] = $ex->getMessage();
        } catch (PDOException $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $_SESSION['aviso'] = $ex->getCode() === '23000'
                ? 'No se pudo completar: hay registros que dependen de este. Elimina primero los relacionados.'
                : 'No se pudo guardar el cambio. Revisa los datos.';
        }
    }
    header('Location: ?t=' . urlencode($tp) . '&edit=1');
    exit;
}

try {
    $filas = $sql ? db()->query($sql)->fetchAll() : [];
    if ($t === 'resumen') {
        $kpi = db()->query("SELECT (SELECT COUNT(*) FROM clientes) AS clientes,
            (SELECT COALESCE(SUM(saldo),0) FROM cuentas) AS saldo,
            (SELECT COUNT(*) FROM solicitudes WHERE estado = 'En revisión') AS pendientes,
            (SELECT COALESCE(SUM(monto),0) FROM solicitudes WHERE estado = 'Aprobada') AS aprobado")->fetch();
        $ultimos = db()->query("SELECT m.*, c.nombre AS cliente FROM movimientos m
            JOIN cuentas a ON a.id = m.cuenta_id JOIN clientes c ON c.id = a.cliente_id
            ORDER BY m.fecha DESC, m.id DESC LIMIT 5")->fetchAll();
        $estados = db()->query("SELECT estado, COUNT(*) AS n FROM solicitudes GROUP BY estado ORDER BY estado")->fetchAll();
    }
    $totales = [];
    foreach ($tablas as $k => $def) {
        $totales[$k] = (int) db()->query("SELECT COUNT(*) FROM $k")->fetchColumn();
    }
} catch (PDOException $ex) {
    http_response_code(500);
    exit('No se pudo conectar a la base de datos. Enciende MySQL, revisa config.php y ejecuta "php instalar.php" (o abre instalar.php en el navegador).');
}

if ($q !== '') {
    $filas = array_filter($filas, fn($f) => stripos(implode(' ', $f), $q) !== false);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SimuBanco · Sistema interno (simulación)</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="estilos.css">
</head>
<body>
<?= banner_simulacion() ?>
<header class="top">
  <div class="brand">
    <div class="logo" aria-hidden="true">SB</div>
    <div><strong>SimuBanco</strong><small>Sistema de administración interna</small></div>
  </div>
  <div class="user">
    <b><?= e($_SESSION['admin']['nombre']) ?></b> · <?= e(ROLES[$_SESSION['admin']['rol']] ?? '') ?>
    <form method="post" action="salir.php" class="en-linea"><?= csrf_campo() ?><button class="salir">Cerrar sesión</button></form><br>
    <?= date('d/m/Y') ?> · <a href="index.php">Ver portal público</a>
  </div>
</header>

<nav class="tabs" aria-label="Módulos">
  <a href="?t=resumen" class="<?= $t === 'resumen' ? 'on' : '' ?>">Resumen</a>
<?php foreach ($tablas as $k => $def): if (!puede($k, 'v')) continue; ?>
  <a href="?t=<?= e($k) . $ed ?>" class="<?= $k === $t ? 'on' : '' ?>"><?= e($def[0]) ?><b><?= $totales[$k] ?></b></a>
<?php endforeach; ?>
</nav>

<main>
  <?php if ($aviso): ?><div class="aviso" role="status"><?= e($aviso) ?></div><?php endif; ?>
  <div class="crumb">Inicio / <?= e($titulo) ?><?= $edit ? ' / Edición' : '' ?></div>
  <?php if ($t === 'resumen'): ?>
  <div class="bar"><h2>Resumen general</h2></div>
  <div class="kpis">
    <div class="kpi"><span>Clientes registrados</span><strong><?= (int)$kpi['clientes'] ?></strong></div>
    <div class="kpi"><span>Saldo total en cuentas</span><strong>$<?= number_format($kpi['saldo'], 2) ?></strong></div>
    <div class="kpi"><span>Solicitudes en revisión</span><strong><?= (int)$kpi['pendientes'] ?></strong></div>
    <div class="kpi"><span>Crédito aprobado</span><strong>$<?= number_format($kpi['aprobado'], 2) ?></strong></div>
  </div>
  <div class="cols">
    <?php if (puede('movimientos', 'v')): ?>
    <section class="panel">
      <h3>Últimos movimientos</h3>
      <div class="wrap"><table>
        <thead><tr><th>ID</th><th>Fecha</th><th>Cliente</th><th>Razón</th><th>Monto</th></tr></thead>
        <tbody>
        <?php foreach ($ultimos as $m): ?>
          <tr>
            <td class="id"><a class="ref" href="?t=movimientos&amp;id=<?= e($m['id']) ?>#<?= e($m['id']) ?>"><?= e($m['id']) ?></a></td>
            <td><?= fecha($m['fecha']) ?></td>
            <td><?= e($m['cliente']) ?></td>
            <td><?= e($m['razon']) ?></td>
            <td class="n<?= $m['monto'] < 0 ? ' neg' : '' ?>">$<?= number_format($m['monto'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>
    <?php if (puede('solicitudes', 'v')): ?>
    <section class="panel">
      <h3>Solicitudes de crédito por estado</h3>
      <ul class="est">
      <?php foreach ($estados as $es): ?>
        <li><span class="tag <?= e(explode(' ', $es['estado'])[0]) ?>"><?= e($es['estado']) ?></span><strong><?= (int)$es['n'] ?></strong></li>
      <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="bar">
    <h2><?= e($titulo) ?></h2>
    <div class="tools">
    <?php if ($puedeEditar): ?><a class="btn <?= $edit ? 'on' : '' ?>" href="?t=<?= e($t) ?><?= $edit ? '' : '&amp;edit=1' ?>"><?= $edit ? 'Salir de edición' : 'Editar tabla' ?></a><?php endif; ?>
    <form method="get">
      <input type="hidden" name="t" value="<?= e($t) ?>">
      <?php if ($edit): ?><input type="hidden" name="edit" value="1"><?php endif; ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar en esta tabla" aria-label="Buscar en esta tabla">
      <button>Buscar</button>
    </form>
    </div>
  </div>

  <div class="wrap"><table>
    <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c[1]) ?></th><?php endforeach; ?><?php if ($colAcc): ?><th>Acciones</th><?php endif; ?></tr></thead>
    <tbody>
    <?php if (!$filas): ?>
      <tr><td colspan="<?= count($cols) + ($colAcc ? 1 : 0) ?>">No hay registros que coincidan con “<?= e($q) ?>”.</td></tr>
    <?php endif; ?>
    <?php foreach ($filas as $f): ?>
      <tr id="<?= e($f['id']) ?>" class="<?= $f['id'] === $hl ? 'hl' : '' ?>">
      <?php foreach ($cols as $i => $c):
        $v = $f[$c[0]]; $tipo = $c[2] ?? ''; ?>
        <?php if ($i === 0): ?><td class="id"><?= e($v) ?></td>
        <?php elseif ($tipo === 'fecha'): ?><td><?= fecha($v) ?></td>
        <?php elseif ($tipo === 'dinero'): ?><td class="n<?= $v < 0 ? ' neg' : '' ?>">$<?= number_format($v, 2) ?></td>
        <?php elseif ($tipo === 'meses'): ?><td><?= (int)$v ?> meses</td>
        <?php elseif ($tipo === 'estado'): ?><td><span class="tag <?= e(explode(' ', $v)[0]) ?>"><?= e($v) ?></span></td>
        <?php elseif ($tipo === 'ref'): ?>
          <td><a class="ref" href="?t=<?= e($c[3]) ?>&amp;id=<?= e($v) ?><?= $ed ?>#<?= e($v) ?>"><?= e($v) ?> · <?= e($f[$c[0] . '_txt']) ?></a></td>
        <?php else: ?><td><?= e($v) ?></td>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($colAcc): ?>
        <td>
          <?php if ($t === 'solicitudes' && puede($t, 's')): ?>
          <form method="post" class="en-linea">
            <?= csrf_campo() ?>
            <input type="hidden" name="t" value="solicitudes">
            <input type="hidden" name="accion" value="estado">
            <input type="hidden" name="id" value="<?= e($f['id']) ?>">
            <select name="estado" aria-label="Estado de <?= e($f['id']) ?>">
              <?php foreach (['Aprobada', 'En revisión', 'Rechazada'] as $o): ?>
                <option<?= $o === $f['estado'] ? ' selected' : '' ?>><?= e($o) ?></option>
              <?php endforeach; ?>
            </select>
            <button>Guardar</button>
          </form>
          <?php endif; ?>
          <?php if (puede($t, 'e')): ?>
          <form method="post" onsubmit="return confirm('¿Eliminar el registro <?= e($f['id']) ?>?')">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="t" value="<?= e($t) ?>">
            <input type="hidden" name="accion" value="borrar">
            <input type="hidden" name="id" value="<?= e($f['id']) ?>">
            <button class="del">Eliminar</button>
          </form>
          <?php endif; ?>
        </td>
      <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if ($edit && puede($t, 'a')): ?>
  <section class="add">
    <h3>Agregar a <?= e(mb_strtolower($titulo)) ?></h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <input type="hidden" name="t" value="<?= e($t) ?>">
      <input type="hidden" name="accion" value="agregar">
      <?php foreach ($formularios[$t] as $c): ?>
        <label><?= e($c[1]) ?>
        <?php if ($c[2] === 'select'): ?>
          <select name="<?= e($c[0]) ?>" required>
            <option value="">Elige una opción</option>
            <?php if (is_array($c[3])): foreach ($c[3] as $o): ?>
              <option value="<?= e($o) ?>"><?= e($o) ?></option>
            <?php endforeach; else: foreach (opciones($c[3]) as $o): ?>
              <option value="<?= e($o['id']) ?>"><?= e($o['txt']) ?></option>
            <?php endforeach; endif; ?>
          </select>
        <?php else: ?>
          <input type="<?= e($c[2]) ?>" name="<?= e($c[0]) ?>" <?= $c[2] === 'number' ? 'step="0.01"' : '' ?> required>
        <?php endif; ?>
        </label>
      <?php endforeach; ?>
      <button>Agregar registro</button>
    </form>
  </section>
  <?php endif; ?>

  <p class="note"><?= e($nota) ?></p>
  <?php endif; ?>
</main>
<footer>SimuBanco · Sistema de simulación con datos ficticios, solo con fines educativos.</footer>
</body>
</html>
