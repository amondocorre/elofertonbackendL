<?php
$ch = curl_init('http://localhost/elofertondev/backend/index.php/ventas/search_products?q=VC1600&dep=9');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
echo "API RESPONSE:\n";
print_r(json_decode($response, true));
?>
