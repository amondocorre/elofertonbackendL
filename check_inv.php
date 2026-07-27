<?php
define('BASEPATH', TRUE);
require_once 'application/config/database.php';
$mysqli = new mysqli($db['default']['hostname'], $db['default']['username'], $db['default']['password'], $db['default']['database'], $db['default']['port']);

$res = $mysqli->query("SELECT i.* FROM inventarios i WHERE i.idprod = 'NA010A'");
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
print_r($rows);
?>
