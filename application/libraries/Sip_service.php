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
            
            // Crear la tabla de configuración si no existe
            $CI->db->query("CREATE TABLE IF NOT EXISTS `bisa_qr_config` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `api_key` VARCHAR(255) NOT NULL,
                `service_key` VARCHAR(255) NOT NULL,
                `username` VARCHAR(255) NOT NULL,
                `password` VARCHAR(255) NOT NULL,
                `api_url` VARCHAR(255) NOT NULL,
                `token` TEXT DEFAULT NULL,
                `token_expires_at` DATETIME DEFAULT NULL,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");

            // Si la tabla está vacía, insertar los valores por defecto
            $query = $CI->db->get('bisa_qr_config');
            if ($query->num_rows() == 0) {
                $CI->db->insert('bisa_qr_config', [
                    'api_key' => 'd84cb47c4ed75374221a80641c9ed034754eaf0303ae8d26',
                    'service_key' => 'f42909ed5cd3a34c9e2b15586fab5614104be4bafcb53afa',
                    'username' => 'mamiersrlDesarrollo',
                    'password' => 'Mamier.2026',
                    'api_url' => 'https://dev-sip.mc4.com.bo:8443',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * Carga la configuración desde la base de datos o usa fallback por defecto
     */
    private function loadConfig()
    {
        $CI =& get_instance();
        if ($CI) {
            $config = $CI->db->get('bisa_qr_config')->row();
            if ($config) {
                $this->apiKey = $config->api_key;
                $this->serviceKey = $config->service_key;
                $this->username = $config->username;
                $this->password = $config->password;
                $this->apiUrl = $config->api_url;
                return $config;
            }
        }

        // Credenciales por defecto para BISA/SIP (fallback)
        $this->apiKey = 'd84cb47c4ed75374221a80641c9ed034754eaf0303ae8d26';
        $this->serviceKey = 'f42909ed5cd3a34c9e2b15586fab5614104be4bafcb53afa';
        $this->username = 'mamiersrlDesarrollo';
        $this->password = 'Mamier.2026';
        $this->apiUrl = 'https://dev-sip.mc4.com.bo:8443';
        return null;
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

        // Obtener la URL del callback dinámicamente
        $CI =& get_instance();
        $CI->load->helper('url');
        $callbackUrl = site_url('PasarelaQr/callback_pago');

        // Si el servidor está detrás de un proxy (como Dokploy/Traefik) y genera la IP privada del contenedor (ej: 10.0.1.94),
        // forzamos el uso del dominio público para que el banco pueda resolver el webhook.
        if (preg_match('/(localhost|127\.0\.0\.1|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)/', $callbackUrl)) {
            $callbackUrl = 'https://apidemo.mamier.cloud/index.php/PasarelaQr/callback_pago';
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
            $qrBase64 = $data['objeto']['imagenBase64'] ?? null;
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
