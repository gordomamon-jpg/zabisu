<?php
/*
    Diseño "Pizarrón de fonda" en vista previa (ver DESIGN.md).
    ?diseno=nuevo lo activa y se recuerda 30 días (cookie zb_diseno);
    ?diseno=actual lo quita. Deja $disenoNuevo en true o false.
*/
if (isset($_GET["diseno"])) {
    $disenoNuevo = $_GET["diseno"] === "nuevo";
    setcookie("zb_diseno", $disenoNuevo ? "nuevo" : "", [
        "expires"  => $disenoNuevo ? time() + 30 * 86400 : time() - 3600,
        "path"     => "/",
        "samesite" => "Lax",
    ]);
} else {
    $disenoNuevo = ($_COOKIE["zb_diseno"] ?? "") === "nuevo";
}
