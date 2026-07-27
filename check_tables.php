<?php
define('BASEPATH', TRUE);
require_once 'application/config/database.php';
$mysqli = new mysqli($db['default']['hostname'], $db['default']['username'], $db['default']['password'], $db['default']['database'], $db['default']['port']);

$res = $mysqli->query("SHOW TABLES");
$tables = [];
while ($row = $res->fetch_array()) {
    $tables[] = $row[0];
}
print_r($tables);
?>
