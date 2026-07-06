<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'jampasnando_ferreteria');
$res = $mysqli->query('SELECT id, idproforma, comentario FROM proformas ORDER BY id DESC LIMIT 5');
while($row = $res->fetch_assoc()) {
    print_r($row);
}
echo "--- ventatransporte ---\n";
$res = $mysqli->query('SELECT * FROM ventatransporte ORDER BY id DESC LIMIT 5');
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>


