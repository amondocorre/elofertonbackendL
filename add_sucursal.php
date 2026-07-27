<?php
define('BASEPATH', TRUE);
require_once 'application/config/database.php';
$mysqli = new mysqli($db['default']['hostname'], $db['default']['username'], $db['default']['password'], $db['default']['database'], $db['default']['port']);

$res = $mysqli->query("SHOW COLUMNS FROM sesiones_caja LIKE 'sucursal_id'");
if ($res->num_rows == 0) {
    echo "Agregando sucursal_id...\n";
    $mysqli->query("ALTER TABLE sesiones_caja ADD COLUMN sucursal_id INT DEFAULT NULL AFTER usuario_id");
    echo $mysqli->error;
} else {
    echo "sucursal_id ya existe.\n";
}
?>
