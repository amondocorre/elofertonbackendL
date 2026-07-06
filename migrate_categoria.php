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

// 1. Crear tabla categoria_producto
$sql = "CREATE TABLE IF NOT EXISTS categoria_producto (
    idcategoria INT AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(100) NOT NULL UNIQUE,
    estado ENUM('Activo', 'Inactivo') DEFAULT 'Activo'
)";
if(!$mysqli->query($sql)) echo "Error creating table: " . $mysqli->error . "\n";
else echo "Table categoria_producto ensured.\n";

// 2. Insertar categorías existentes de la tabla productos
$sql = "INSERT IGNORE INTO categoria_producto (descripcion) SELECT DISTINCT TRIM(categoria) FROM productos WHERE categoria IS NOT NULL AND TRIM(categoria) != ''";
if(!$mysqli->query($sql)) echo "Error inserting categories from productos: " . $mysqli->error . "\n";
else echo "Inserted " . $mysqli->affected_rows . " distinct categories from productos.\n";

// Ensure some basic categories exist if none
$basic = ['Herramientas', 'Ferretería', 'Eléctricos', 'Pinturas', 'Plomería', 'Construcción', 'Accesorios', 'Otros'];
foreach ($basic as $b) {
    $mysqli->query("INSERT IGNORE INTO categoria_producto (descripcion) VALUES ('$b')");
}

// 3. Añadir idcategoria a productos si no existe
$sql = "SHOW COLUMNS FROM productos LIKE 'idcategoria'";
$res = $mysqli->query($sql);
if ($res->num_rows == 0) {
    $sql = "ALTER TABLE productos ADD COLUMN idcategoria INT NULL AFTER categoria";
    if(!$mysqli->query($sql)) echo "Error adding column idcategoria to productos: " . $mysqli->error . "\n";
    else echo "Added column idcategoria to productos.\n";
} else {
    echo "Column idcategoria already exists in productos.\n";
}

// 4. Actualizar idcategoria en productos
$sql = "UPDATE productos p JOIN categoria_producto c ON TRIM(p.categoria) = c.descripcion SET p.idcategoria = c.idcategoria WHERE p.idcategoria IS NULL";
if(!$mysqli->query($sql)) echo "Error updating idcategoria in productos: " . $mysqli->error . "\n";
else echo "Updated " . $mysqli->affected_rows . " products with idcategoria.\n";

// Same for inventarios just in case inventarios is used heavily directly
$sql = "SHOW COLUMNS FROM inventarios LIKE 'idcategoria'";
$res = $mysqli->query($sql);
if ($res->num_rows == 0) {
    $sql = "ALTER TABLE inventarios ADD COLUMN idcategoria INT NULL AFTER categoria";
    if(!$mysqli->query($sql)) echo "Error adding column idcategoria to inventarios: " . $mysqli->error . "\n";
    else echo "Added column idcategoria to inventarios.\n";
}

$sql = "UPDATE inventarios i JOIN categoria_producto c ON TRIM(i.categoria) = c.descripcion SET i.idcategoria = c.idcategoria WHERE i.idcategoria IS NULL";
if(!$mysqli->query($sql)) echo "Error updating idcategoria in inventarios: " . $mysqli->error . "\n";
else echo "Updated " . $mysqli->affected_rows . " inventory records with idcategoria.\n";


$mysqli->close();
echo "Migration completed.\n";
?>
