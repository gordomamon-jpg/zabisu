<?php
require_once "../config/db.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Si ya está logueado, ir directo al panel */
if (!empty($_SESSION["restaurante_auth"])) {
    header("Location: panel_general.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usuario  = trim($_POST["usuario"]  ?? "");
    $password = trim($_POST["password"] ?? "");

    if ($usuario === "" || $password === "") {
        $error = "Ingresa tu usuario y contraseña.";
    } else {
        $sql  = "SELECT id_usuario, nombre, password_hash
                 FROM usuarios_restaurante
                 WHERE usuario = :usuario AND activo = 1
                 LIMIT 1";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([":usuario" => $usuario]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user["password_hash"])) {
            $_SESSION["restaurante_auth"]    = true;
            $_SESSION["restaurante_usuario"] = $user["usuario"];
            $_SESSION["restaurante_nombre"]  = $user["nombre"];
            header("Location: panel_general.php");
            exit;
        } else {
            $error = "Usuario o contraseña incorrectos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso | Restaurante Zabisu</title>
    <link rel="icon" type="image/png" href="../assets/img/LOGO_N.png">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="contenedor" style="max-width:440px;">

    <div class="hero-zabisu">
        <div class="hero-zabisu__glow"></div>
        <div class="hero-zabisu__contenido">
            <p class="hero-zabisu__eyebrow">RESTAURANTE</p>
            <div class="hero-zabisu__marca-grupo">
                <img class="hero-zabisu__logo" src="../assets/img/LOGO_BLANCO.png" alt="Zabisu">
                <h1 class="hero-zabisu__titulo">Zabisu</h1>
            </div>
            <p class="hero-zabisu__texto">Panel de administración</p>
        </div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="mensaje-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="bloque-formulario">
        <h2>Iniciar sesión</h2>

        <form method="POST" action="">
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario"
                   placeholder="Tu usuario"
                   value="<?php echo htmlspecialchars($_POST["usuario"] ?? ""); ?>"
                   autocomplete="username" autofocus>

            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password"
                   placeholder="Tu contraseña"
                   autocomplete="current-password">

            <button type="submit" class="btn-principal" style="margin-top:8px;">
                Entrar
            </button>
        </form>
    </div>

</div>

</body>
</html>
