<?php
require_once "../config/db.php";
require_once "../includes/seguridad.php";
iniciarSesionSegura();

/* Si ya está logueado, ir directo al panel */
if (!empty($_SESSION["restaurante_auth"])) {
    header("Location: panel_general.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verificarOrigenPeticion();

    $usuario  = trim($_POST["usuario"]  ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($usuario === "" || $password === "") {
        $error = "Ingresa tu usuario y contraseña.";
    } else {
        [$puedeIntentar, $segundosRestantes] = loginPuedeIntentar($usuario);

        if (!$puedeIntentar) {
            $minutos = max(1, (int)ceil($segundosRestantes / 60));
            $error = "Demasiados intentos fallidos. Intenta de nuevo en {$minutos} minuto" . ($minutos === 1 ? "" : "s") . ".";
        } else {
            $sql  = "SELECT id_usuario, usuario, nombre, password_hash
                     FROM usuarios_restaurante
                     WHERE usuario = :usuario AND activo = 1
                     LIMIT 1";
            $stmt = $conexion->prepare($sql);
            $stmt->execute([":usuario" => $usuario]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user["password_hash"])) {
                loginLimpiarIntentos($usuario);
                session_regenerate_id(true);
                $_SESSION["restaurante_auth"]    = true;
                $_SESSION["restaurante_usuario"] = $user["usuario"];
                $_SESSION["restaurante_nombre"]  = $user["nombre"];
                header("Location: panel_general.php");
                exit;
            } else {
                loginRegistrarFallo($usuario);
                $error = "Usuario o contraseña incorrectos.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Acceso | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_NARA.png">
    <meta name="theme-color" content="#151413">
    <link rel="preload" href="../assets/fonts/kaushan-script-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/alfa-slab-one-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="../assets/css/menu-pizarron.css?v=2">
    <link rel="stylesheet" href="../assets/css/restaurante-pizarron.css?v=4">
</head>
<body class="pz rz">
<?php require __DIR__ . "/../includes/pz_filtros.php"; ?>

<div class="rz-entrada">

<!-- Portada con la marca: solo en pantallas anchas (en el celular basta el encabezado) -->
<aside class="rz-portada" aria-hidden="true">
    <div class="rz-portada__marca">
        <img src="../assets/img/LOGO_N_128.png" alt="">
        <span class="rz-portada__nombre">Zabisu</span>
        <span class="rz-portada__lema">Sabor y Servicio</span>
    </div>
    <div class="pz-boleto rz-portada__boleto">
        <div class="pz-boleto__papel">
            <span class="pz-boleto__ojo"></span>
            <p class="pz-boleto__titulo">Todo el día en un solo lugar</p>
            <p>Pedidos, ruta, menús y gastos.</p>
        </div>
    </div>
</aside>

<main class="pz-pagina rz-login">

    <header class="pz-cab">
        <div class="pz-marca">
            <img src="../assets/img/LOGO_N_128.png" alt="">
            <div>
                <span class="pz-marca__nombre">Zabisu</span>
                <span class="pz-marca__lema">Sabor y Servicio</span>
            </div>
        </div>
    </header>

    <p class="rz-eyebrow">Panel del restaurante</p>
    <h1 class="pz-titulo"><span class="pz-pincel"><span>Iniciar sesión</span></span></h1>

    <?php if ($error !== ""): ?>
        <p class="rz-aviso rz-aviso--error" role="alert"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="POST" action="" class="pz-sec rz-form">
        <h2 class="pz-cinta pz-fibra">Solo personal</h2>

        <label class="rz-campo" for="usuario">
            <span>Usuario</span>
            <input type="text" id="usuario" name="usuario"
                   value="<?php echo htmlspecialchars($_POST["usuario"] ?? ""); ?>"
                   autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                   required <?php echo ($_POST["usuario"] ?? "") === "" ? "autofocus" : ""; ?>>
        </label>

        <label class="rz-campo" for="password">
            <span>Contraseña</span>
            <span class="rz-clave">
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required
                       <?php echo ($_POST["usuario"] ?? "") !== "" ? "autofocus" : ""; ?>>
                <button type="button" class="rz-ver" id="rz-ver" aria-label="Mostrar contraseña" aria-pressed="false">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                </button>
            </span>
        </label>

        <button type="submit" class="pz-btn rz-entrar" id="rz-entrar">Entrar</button>
    </form>

    <p class="pz-pie">© 2026 Zabisu - Sabor y Servicio. Todos los derechos reservados.</p>
</main>

</div>

<script>
(function () {
    const clave = document.getElementById('password');
    const ver   = document.getElementById('rz-ver');
    ver.addEventListener('click', () => {
        const mostrar = clave.type === 'password';
        clave.type = mostrar ? 'text' : 'password';
        ver.setAttribute('aria-pressed', mostrar);
        ver.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
        clave.focus();
    });
    document.querySelector('.rz-form').addEventListener('submit', () => {
        const b = document.getElementById('rz-entrar');
        b.disabled = true;
        b.textContent = 'Entrando…';
    });
})();
</script>
</body>
</html>
