<?php
/*
    Diseño "Pizarrón de fonda" (ver DESIGN.md), activo para todos.
    ?diseno=actual vuelve al diseño anterior y se recuerda 30 días
    (cookie zb_diseno); ?diseno=nuevo lo quita. Deja $disenoNuevo en true o false.
*/
if (isset($_GET["diseno"])) {
    $disenoNuevo = $_GET["diseno"] !== "actual";
    setcookie("zb_diseno", $disenoNuevo ? "" : "actual", [
        "expires"  => $disenoNuevo ? time() - 3600 : time() + 30 * 86400,
        "path"     => "/",
        "samesite" => "Lax",
    ]);
} else {
    $disenoNuevo = ($_COOKIE["zb_diseno"] ?? "") !== "actual";
}
