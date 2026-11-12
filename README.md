# SimuBanco · Sistema bancario (simulación)

Sitio en PHP con MySQL. Todos los datos son ficticios.

## Cómo usarlo

- **Portal público** (`index.php`): los clientes entran con su número de cuenta y NIP, consultan saldos y movimientos, solicitan créditos y usan el simulador de pagos.
- **Panel del personal** (`login.php` → `admin.php`): cada usuario ve y modifica solo lo que su rol permite. El gerente aprueba o rechaza las solicitudes que llegan desde el portal.

### Accesos de prueba

| Rol | Usuario | Contraseña |
|---|---|---|
| Gerente | `gerente` (o `admin`) | `Gerente2026!` (`admin`: `Banco2026!`) |
| Asesor de ventas | `asesor` | `Asesor2026!` |
| Cajero | `cajero` | `Cajero2026!` |

| Cliente | Cuenta | NIP |
|---|---|---|
| C-001 | `A-1001` | `1111` |
| C-002 | `A-1002` | `2222` |
| C-003 | `A-1003` | `3333` |
| C-004 | `A-1004` | `4444` |
| C-005 | `A-1005` | `5555` |

### Qué puede hacer cada rol

| Tabla | Gerente | Asesor de ventas | Cajero |
|---|---|---|---|
| Clientes | ver, agregar, eliminar | ver, agregar | ver |
| Trabajadores | ver, agregar, eliminar | sin acceso | sin acceso |
| Cuentas y saldos | ver, agregar, eliminar | ver, agregar | ver |
| Movimientos | ver, agregar, eliminar | sin acceso | ver, agregar |
| Solicitudes de crédito | ver, agregar, eliminar, aprobar o rechazar | ver, agregar | sin acceso |

Los permisos se validan en el servidor. Al registrar un movimiento, el saldo de la cuenta se actualiza solo (el tipo define si suma o resta) y no se permite dejarla en negativo.

Cambia las contraseñas de prueba antes de publicar el sitio.

## Ejecutarlo localmente

### Opción 1: XAMPP o PHP y MySQL instalados

1. Enciende **MySQL** (en XAMPP, desde el panel de control).
2. Windows: doble clic en `iniciar.bat`. Mac o Linux: `./iniciar.sh`.
3. Abre http://localhost:8000

El script crea la base `banco_tabasco` con tablas y datos de ejemplo, y arranca el servidor de PHP.
Si tu MySQL tiene otro usuario o contraseña, cámbialos en `config.php`.

### Opción 2: Docker

    docker compose up --build

Abre http://localhost:8080. La base se crea e importa sola la primera vez.

### Si venías de la versión anterior

La base de datos cambió (tabla `usuarios` con roles y NIP de clientes). Ejecuta `php instalar.php --forzar` para recrearla. Esto borra los cambios hechos.

## Archivos

- `index.php`: portal público de clientes.
- `login.php`, `salir.php`: inicio y cierre de sesión.
- `admin.php`: panel del personal (requiere sesión).
- `comun.php`: sesión, protección CSRF y funciones compartidas.
- `config.php`: conexión a MySQL.
- `schema.sql`: tablas, llaves foráneas y datos de ejemplo.
- `instalar.php`: crea la base de datos e importa `schema.sql`.
- `estilos.css`: apariencia.

## Seguridad

Las contraseñas y los NIP se guardan con hash, las consultas usan sentencias preparadas, los formularios llevan token CSRF y hay bloqueo de 60 segundos tras 5 intentos fallidos. Es una simulación: antes de publicarla, usa HTTPS, borra `instalar.php` y cambia las contraseñas de prueba.

## Si el usuario y la contraseña no funcionan

1. Ejecuta `php instalar.php` (o abre `instalar.php` en el navegador). Si detecta una versión anterior de la base de datos, la actualiza con los usuarios nuevos.
2. Con Docker, la base anterior queda guardada en un volumen: ejecuta `docker compose down -v` y luego `docker compose up --build`.
3. Escribe el usuario y la contraseña tal cual, respetando mayúsculas y el signo de cierre de exclamación.

## Sobre el nombre y el aviso de simulación

El proyecto se llama **SimuBanco** a propósito: un nombre claramente ficticio y un aviso fijo en la parte superior de todas las pantallas evitan que se confunda con un banco real. Los correos de ejemplo usan dominios reservados (`.example`) y `robots.txt` pide a los buscadores no indexar el sitio.
Aun así, algunos hostings gratuitos bloquean automáticamente cualquier página con aspecto de banco y login, así que es más seguro usarlo en tu computadora o en un hosting que lo permita expresamente.
