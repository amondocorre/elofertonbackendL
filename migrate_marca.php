<?php
define('BASEPATH', __DIR__ . '/system/');
define('APPPATH', __DIR__ . '/application/');
define('ENVIRONMENT', 'development');

$active_group = 'default';
$query_builder = TRUE;
require_once APPPATH . 'config/database.php';

$mysqli = new mysqli(
    $db['default']['hostname'],
    $db['default']['username'],
    $db['default']['password'],
    $db['default']['database'],
    $db['default']['port'] ?? 3306
);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
echo "Connected successfully to DB.\n";

// 1. Añadir idmarca a productos si no existe
$sql = "SHOW COLUMNS FROM productos LIKE 'idmarca'";
$res = $mysqli->query($sql);
if ($res->num_rows == 0) {
    $sql = "ALTER TABLE productos ADD COLUMN idmarca INT NULL AFTER marca";
    if(!$mysqli->query($sql)) echo "Error adding column idmarca to productos: " . $mysqli->error . "\n";
    else echo "Added column idmarca to productos.\n";
} else {
    echo "Column idmarca already exists in productos.\n";
}

// 2. Actualizar idmarca en productos
$sql = "UPDATE productos p JOIN marcas m ON TRIM(p.marca) COLLATE utf8_general_ci = m.nombre COLLATE utf8_general_ci SET p.idmarca = m.id WHERE p.idmarca IS NULL";
if(!$mysqli->query($sql)) echo "Error updating idmarca in productos: " . $mysqli->error . "\n";
else echo "Updated " . $mysqli->affected_rows . " products with idmarca.\n";

// 3. Añadir idmarca a inventarios si no existe
$sql = "SHOW COLUMNS FROM inventarios LIKE 'idmarca'";
$res = $mysqli->query($sql);
if ($res->num_rows == 0) {
    $sql = "ALTER TABLE inventarios ADD COLUMN idmarca INT NULL AFTER marca";
    if(!$mysqli->query($sql)) echo "Error adding column idmarca to inventarios: " . $mysqli->error . "\n";
    else echo "Added column idmarca to inventarios.\n";
} else {
    echo "Column idmarca already exists in inventarios.\n";
}

// 4. Actualizar idmarca en inventarios
$sql = "UPDATE inventarios i JOIN marcas m ON TRIM(i.marca) = m.nombre SET i.idmarca = m.id WHERE i.idmarca IS NULL";
if(!$mysqli->query($sql)) echo "Error updating idmarca in inventarios: " . $mysqli->error . "\n";
else echo "Updated " . $mysqli->affected_rows . " inventory records with idmarca.\n";

$mysqli->close();
echo "Migration completed.\n";
?>
