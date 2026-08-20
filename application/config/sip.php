<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| SIP Environment
|--------------------------------------------------------------------------
|
| Define el entorno a utilizar para la pasarela de pagos BISA/SIP.
| Valores soportados: 'development' o 'production'
|
*/
// Se determina dinámicamente según la cabecera HTTP del cliente o la constante ENVIRONMENT de CodeIgniter
$client_qr_env = isset($_POST['qr_env']) ? strtolower($_POST['qr_env']) : (isset($_SERVER['HTTP_X_QR_ENV']) ? strtolower($_SERVER['HTTP_X_QR_ENV']) : '');
if ($client_qr_env === 'sandbox' || $client_qr_env === 'development') {
    $config['sip_environment'] = 'development';
} else {
    $config['sip_environment'] = 'production';
}


/*
|--------------------------------------------------------------------------
| SIP Credentials
|--------------------------------------------------------------------------
|
| Aquí se definen las credenciales para los distintos entornos.
| Al pasar a producción, asegúrate de actualizar el entorno arriba
| y llenar los datos correspondientes en 'production'.
|
*/
$config['sip_credentials'] = [
    'development' => [
        'api_key'     => 'd84cb47c4ed75374221a80641c9ed034754eaf0303ae8d26',
        'service_key' => 'f42909ed5cd3a34c9e2b15586fab5614104be4bafcb53afa',
        'username'    => 'mamiersrlDesarrollo',
        'password'    => 'Mamier.2026',
        'api_url'     => 'https://dev-sip.mc4.com.bo:8443',
        'callback'    => 'https://apidemo.mamier.cloud/index.php/PasarelaQr/callback_pago'
    ],
    'production' => [
        'api_key'     => 'd4008dcf38d3aae9864b6efad53cb97ca35a59af1afe2fe8',
        'service_key' => '4acaaf89843185d6df4de5f4b5202716a0ef65b06849df17',
        'username'    => 'EMISORMIER',
        'password'    => 'oBRerito.2026',
        'api_url'     => 'https://sip.mc4.com.bo:8443', // Ejemplo: https://sip.mc4.com.bo:8443
        'callback'    => 'https://eloferton.mamier.cloud/index.php/PasarelaQr/callback_pago'
    ]
];
