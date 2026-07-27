<?php
define('BASEPATH', TRUE);
require_once 'application/config/database.php';

$mysqli = new mysqli($db['default']['hostname'], $db['default']['username'], $db['default']['password'], $db['default']['database'], $db['default']['port']);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$queries = [
    "CREATE TABLE IF NOT EXISTS subcategoria (
        idsubcategoria INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(255) NOT NULL,
        estado VARCHAR(50) DEFAULT 'Activo'
    )",
    "ALTER TABLE productos ADD COLUMN idsubcategoria INT DEFAULT NULL"
];

foreach ($queries as $query) {
    if ($mysqli->query($query)) {
        echo "Exito: $query\n";
    } else {
        echo "Error en $query: " . $mysqli->error . "\n";
    }
}
$mysqli->close();
?>
