<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Sip_service
{
    private $apiKey;
    private $serviceKey;
    private $username;
    private $password;
    private $apiUrl;

    public function __construct()
    {
        // Crear la tabla si no existe e inicializar
        $CI =& get_instance();
        if ($CI) {
            $CI->load->database();
            
            // Crear la tabla de configuración si no existe (usada para guardar el token temporal)
            $CI->db->query("CREATE TABLE IF NOT EXISTS `bisa_qr_config` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `api_key` VARCHAR(255) DEFAULT NULL,
                `service_key` VARCHAR(255) DEFAULT NULL,
                `username` VARCHAR(255) DEFAULT NULL,
                `password` VARCHAR(255) DEFAULT NULL,
                `api_url` VARCHAR(255) DEFAULT NULL,
                `token` TEXT DEFAULT NULL,
                `token_expires_at` DATETIME DEFAULT NULL,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");

            // Si la tabla está vacía, insertar un registro para almacenar el token
            $query = $CI->db->get('bisa_qr_config');
            if ($query->num_rows() == 0) {
                $CI->db->insert('bisa_qr_config', [
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * Carga la configuración desde el archivo de configuración (config/sip.php)
     */
    private function loadConfig()
    {
        $CI =& get_instance();
        $configDb = null;
        if ($CI) {
            // Obtenemos el registro de la DB solo para la gestión del Token
            $configDb = $CI->db->get('bisa_qr_config')->row();
            
            // Cargar configuración de archivo sip.php
            $CI->config->load('sip', true, true);
            $env = $CI->config->item('sip_environment', 'sip');
            $creds = $CI->config->item('sip_credentials', 'sip');
            
            if ($env && isset($creds[$env])) {
                $activeCreds = $creds[$env];
                $this->apiKey = $activeCreds['api_key'];
                $this->serviceKey = $activeCreds['service_key'];
                $this->username = $activeCreds['username'];
                $this->password = $activeCreds['password'];
                $this->apiUrl = $activeCreds['api_url'];
            }
        }

        return $configDb;
    }

    /**
     * Obtiene el token JWT del servicio SIP, con soporte para caché en BD
     *
     * @return string|null El token o null si ocurre un error
     */
    public function getToken()
    {
        $config = $this->loadConfig();

        // Validar si el token actual sigue vigente
        if ($config && !empty($config->token) && !empty($config->token_expires_at)) {
            $expiresAt = strtotime($config->token_expires_at);
            // Si falta más de 1 minuto para expirar, reutilizar
            if ($expiresAt > (time() + 60)) {
                return $config->token;
            }
        }

        $url = $this->apiUrl . '/autenticacion/v1/generarToken';
        
        $headers = [
            'apikey: ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        $body = [
            'username' => $this->username,
            'password' => $this->password
        ];

        $response = $this->sendPostRequest($url, $headers, $body);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        $token = $data['objeto']['token'] ?? null;

        if ($token) {
            // Guardar el token en la base de datos con su respectivo tiempo de expiración
            $validez = isset($data['objeto']['validez']) ? intval($data['objeto']['validez']) : 3600;
            $expiresAtStr = date('Y-m-d H:i:s', time() + $validez);

            $CI =& get_instance();
            if ($CI && $config) {
                $CI->db->where('id', $config->id)->update('bisa_qr_config', [
                    'token' => $token,
                    'token_expires_at' => $expiresAtStr,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }

        return $token;
    }

    /**
     * Solicita la generación de un QR a la pasarela SIP
     *
     * @param string $alias Identificador único de transacción
     * @param float $amount Monto a cobrar
     * @param string $detail Glosa de descripción
     * @return array Array asociativo con resultado o error
     */
    public function generateQr($alias, $amount, $detail)
    {
        $token = $this->getToken();
        if (!$token) {
            log_message('error', "SIP BISA: No se pudo obtener el token de autenticación. Se generará un QR simulado de contingencia.");
            return [
                'status' => 'success',
                'simulated' => true,
                'idQr' => 'SIM_QR_' . uniqid(),
                'qr_base64' => $this->getSimulatedQrBase64($alias, $amount)
            ];
        }

        $url = $this->apiUrl . '/api/v1/generaQr';

        $headers = [
            'apikeyServicio: ' . $this->serviceKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        // Cargar configuración de URL Callback
        $CI =& get_instance();
        $CI->load->helper('url');
        $CI->config->load('sip', true, true);
        $env = $CI->config->item('sip_environment', 'sip');
        $creds = $CI->config->item('sip_credentials', 'sip');
        
        $callbackUrl = site_url('PasarelaQr/callback_pago');
        if ($env && isset($creds[$env]) && !empty($creds[$env]['callback'])) {
            $callbackUrl = $creds[$env]['callback'];
        }

        // Vencimiento del QR en 2 días
        $expirationDate = date('d/m/Y', strtotime('+2 days'));

        $body = [
            'alias' => $alias,
            'callback' => $callbackUrl,
            'detalleGlosa' => $detail,
            'monto' => number_format($amount, 2, '.', ''),
            'moneda' => 'BOB',
            'fechaVencimiento' => $expirationDate,
            'tipoSolicitud' => 'API'
        ];

        $response = $this->sendPostRequest($url, $headers, $body);

        if (!$response) {
            log_message('error', "SIP BISA: Error de conexión con el servidor al generar QR. Se usará contingencia simulada.");
            return [
                'status' => 'success',
                'simulated' => true,
                'idQr' => 'SIM_QR_' . uniqid(),
                'qr_base64' => $this->getSimulatedQrBase64($alias, $amount)
            ];
        }

        $data = json_decode($response, true);
        file_put_contents(APPPATH . 'logs/debug_bisa_response.txt', "TIME: " . date('Y-m-d H:i:s') . "\nRESPONSE: " . var_export($response, true) . "\n", FILE_APPEND);
        log_message('error', 'Respuesta Sip_service generaQr: ' . $response);

        // Si el usuario no tiene permisos en el entorno de desarrollo, simular el QR
        $code = $data['codigo'] ?? '';
        $message = $data['mensaje'] ?? '';

        if ($code === '9999' && strpos(strtolower($message), 'permisos') !== false) {
            log_message('debug', "SIP BISA: Servidor devolvió error de permisos. Usando QR simulado.");
            return [
                'status' => 'success',
                'simulated' => true,
                'idQr' => 'SIM_QR_' . uniqid(),
                'qr_base64' => $this->getSimulatedQrBase64($alias, $amount)
            ];
        }

        if ($code === '0000' || (isset($data['objeto']) && $data['objeto'] !== null)) {
            // La API de BISA devuelve el base64 en el campo 'imagenQr' (no 'imagenBase64')
            $qrBase64 = $data['objeto']['imagenQr'] ?? $data['objeto']['imagenBase64'] ?? null;
            if (empty($qrBase64)) {
                $qrBase64 = $this->getSimulatedQrBase64($alias, $amount);
            }
            return [
                'status' => 'success',
                'simulated' => false,
                'idQr' => $data['objeto']['idQr'] ?? ('QR_' . uniqid()),
                'qr_base64' => $qrBase64
            ];
        }

        log_message('error', "SIP BISA: Error devuelto por la API del banco ($message). Usando contingencia simulada.");
        return [
            'status' => 'success',
            'simulated' => true,
            'idQr' => 'SIM_QR_' . uniqid(),
            'qr_base64' => $this->getSimulatedQrBase64($alias, $amount)
        ];
    }

    /**
     * Consulta el estado de una transacción QR en la pasarela SIP
     *
     * @param string $alias Identificador único de transacción
     * @return string Estado retornado por el banco
     */
    public function checkStatus($alias)
    {
        $token = $this->getToken();
        if (!$token) {
            log_message('error', "SIP BISA: No se pudo obtener el token para verificar estado. Retornando PENDIENTE.");
            return 'PENDIENTE';
        }

        $url = $this->apiUrl . '/api/v1/estadoTransaccion';

        $headers = [
            'apikeyServicio: ' . $this->serviceKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        $body = [
            'alias' => $alias
        ];

        $response = $this->sendPostRequest($url, $headers, $body);

        if (!$response) {
            return 'ERROR_CONEXION';
        }

        $data = json_decode($response, true);
        
        // Retornar estado si existe
        if (isset($data['objeto']['estado'])) {
            return strtoupper($data['objeto']['estado']);
        }

        return 'PENDIENTE';
    }

    /**
     * Inhabilita un QR generado en SIP
     * 
     * @param string $alias Identificador único de transacción
     * @return bool True si se inhabilitó correctamente, False caso contrario
     */
    public function inhabilitarQr($alias)
    {
        $token = $this->getToken();
        if (!$token) {
            log_message('error', "SIP BISA: No se pudo obtener el token para inhabilitar QR.");
            return false;
        }

        $url = $this->apiUrl . '/api/v1/inhabilitarPago';

        $headers = [
            'apikeyServicio: ' . $this->serviceKey,
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        $body = [
            'alias' => $alias
        ];

        $response = $this->sendPostRequest($url, $headers, $body);

        if (!$response) {
            log_message('error', "SIP BISA: Error de conexión al inhabilitar QR.");
            return false;
        }

        $data = json_decode($response, true);
        
        // El manual indica: "codigo": "0000" para éxito
        if (isset($data['codigo']) && $data['codigo'] === '0000') {
            return true;
        }

        log_message('error', "SIP BISA: Error al inhabilitar QR. Respuesta: " . $response);
        return false;
    }

    /**
     * Realiza una solicitud HTTP POST mediante cURL
     */
    private function sendPostRequest($url, $headers, $body)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        
        if ($response === false) {
            $err = curl_error($ch);
            log_message('error', "SIP BISA cURL Error to $url: " . $err);
        }
        
        curl_close($ch);

        return $response;
    }

    /**
     * Retorna una imagen QR simulada en base64 de prueba
     */
    private function getSimulatedQrBase64($alias, $amount)
    {
        // Generar URL externa para QR con texto descriptivo
        $text = "Ferreteria Oferton - PAGO QR SIMULADO\n";
        $text .= "Alias: " . $alias . "\n";
        $text .= "Monto: Bs. " . number_format($amount, 2);
        
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($text);
        
        $ch = curl_init($qrUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $qrImage = curl_exec($ch);
        curl_close($ch);

        if ($qrImage) {
            return base64_encode($qrImage);
        }

        // Fallback: pixel transparente
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    }
}
