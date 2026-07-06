<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Controlador para la integración del sistema de pagos QR "SIP" de Banco BISA.
 * Sigue los estándares PSR-12 y las reglas de diseño del proyecto.
 */
class PasarelaQr extends CI_Controller
{
    // Credenciales de Basic Auth para el Callback del banco
    private const BASIC_AUTH_USER = 'bisa_sip_callback';
    private const BASIC_AUTH_PASS = 'BisaSipSecure2026!';

    public function __construct()
    {
        parent::__construct();

        // Habilitar CORS para peticiones desde el frontend
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }

        $this->load->database();
        $this->load->helper('url');

        // Inicializar tablas si no existen en el entorno actual
        $this->initializeDatabase();
    }

    /**
     * Asegura la creación de las tablas necesarias para la integración de QR.
     */
    private function initializeDatabase()
    {
        // Crear la tabla de configuración si no existe
        $this->db->query("CREATE TABLE IF NOT EXISTS `bisa_qr_config` (
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

        // Si la tabla está vacía, insertar credenciales por defecto de desarrollo
        $query = $this->db->get('bisa_qr_config');
        if ($query->num_rows() === 0) {
            $this->db->insert('bisa_qr_config', [
                'api_key' => 'd84cb47c4ed75374221a80641c9ed034754eaf0303ae8d26',
                'service_key' => 'f42909ed5cd3a34c9e2b15586fab5614104be4bafcb53afa',
                'username' => 'mamiersrlDesarrollo',
                'password' => 'Mamier.2026',
                'api_url' => 'https://dev-sip.mc4.com.bo:8443',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // Crear la tabla de transacciones si no existe
        $this->db->query("CREATE TABLE IF NOT EXISTS `bisa_qr_transacciones` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `alias` VARCHAR(100) NOT NULL UNIQUE,
            `monto` DECIMAL(10,2) NOT NULL,
            `id_venta` VARCHAR(50) DEFAULT NULL,
            `id_proforma` VARCHAR(100) DEFAULT NULL,
            `id_qr` VARCHAR(100) DEFAULT NULL,
            `qr_base64` LONGTEXT DEFAULT NULL,
            `estado` VARCHAR(50) DEFAULT 'PENDIENTE',
            `numero_orden_originante` VARCHAR(100) DEFAULT NULL,
            `fecha_registro` DATETIME NOT NULL,
            `fecha_pago` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");

        // Crear la tabla de logs de callbacks para diagnóstico
        $this->db->query("CREATE TABLE IF NOT EXISTS `bisa_qr_callback_logs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `alias` VARCHAR(100) DEFAULT NULL,
            `monto` DECIMAL(10,2) DEFAULT NULL,
            `headers` TEXT DEFAULT NULL,
            `raw_body` TEXT DEFAULT NULL,
            `ip_address` VARCHAR(50) DEFAULT NULL,
            `fecha_registro` DATETIME NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");
    }

    /**
     * FASE 1: Generación de QR desde el Carrito de compras.
     * Genera el cobro por QR, gestionando el token dinámico y guardando la transacción local.
     */
    public function generar_cobro()
    {
        // Leer datos JSON crudos si existen, si no usar POST convencional
        $rawInput = $this->input->raw_input_stream;
        $data = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            $data = $this->input->post();
        }

        $amount = floatval($data['monto'] ?? 0);
        $proformaId = $data['id_proforma'] ?? null;
        $alias = $data['alias'] ?? $proformaId;

        // Validar monto de cobro
        if ($amount <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El monto a cobrar debe ser mayor a 0']));
        }

        // Generar alias único si no fue proporcionado
        if (empty($alias)) {
            $alias = 'VENTA_' . time() . '_' . rand(100, 999);
        }

        // Obtener configuración activa
        $config = $this->db->get_where('bisa_qr_config', ['id' => 1])->row();
        if (!$config) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Configuración de pagos QR no encontrada']));
        }

        // Obtener token JWT
        $token = $this->getAccessToken($config);
        if (!$token) {
            // En desarrollo local sin internet, podemos simular la generación directamente
            $simulatedId = 'SIM_QR_' . uniqid();
            $simulatedQr = $this->generateSimulatedQrBase64($alias, $amount);
            $this->saveTransaction($alias, $amount, $proformaId, $simulatedId, $simulatedQr);

            return $this->output
                ->set_status_header(200)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'alias' => $alias,
                    'qr_base64' => $simulatedQr,
                    'imagenQr' => $simulatedQr,
                    'idQr' => $simulatedId,
                    'simulated' => true
                ]));
        }

        // Llamar a la API SIP para Generar QR
        $url = $config->api_url . '/api/v1/generaQr';
        $headers = [
            'Authorization: Bearer ' . $token,
            'apikeyServicio: ' . $config->service_key,
            'Content-Type: application/json'
        ];

        // Definir vencimiento del QR (1 día de validez)
        $expirationDate = date('d/m/Y', strtotime('+1 day'));

        // Generar URL del callback
        $callbackUrl = site_url('PasarelaQr/callback_pago');
        
        // Si el servidor está detrás de un proxy (como Dokploy/Traefik) y genera la IP privada del contenedor (ej: 10.0.1.94),
        // forzamos el uso del dominio público para que el banco pueda resolver el webhook.
        if (preg_match('/(localhost|127\.0\.0\.1|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)/', $callbackUrl)) {
            $callbackUrl = 'https://apidemo.mamier.cloud/index.php/PasarelaQr/callback_pago';
        }

        $body = [
            'alias' => $alias,
            'monto' => number_format($amount, 2, '.', ''),
            'callback' => $callbackUrl,
            'detalleGlosa' => 'Pago Ferreteria Oferton',
            'moneda' => 'BOB',
            'fechaVencimiento' => $expirationDate,
            'tipoSolicitud' => 'API',
            'unicoUso' => 'true'
        ];

        $response = $this->sendPostRequest($url, $headers, $body);
        $simulated = false;
        $idQr = null;
        $qrBase64 = null;

        if ($response) {
            $resData = json_decode($response, true);
            $code = $resData['codigo'] ?? '';
            $message = $resData['mensaje'] ?? '';

            if ($code === '0000' && isset($resData['objeto'])) {
                $idQr = $resData['objeto']['idQr'] ?? null;
                $qrBase64 = $resData['objeto']['imagenBase64'] ?? null;
                if (empty($qrBase64)) {
                    $qrBase64 = $this->generateSimulatedQrBase64($alias, $amount);
                }
            } elseif ($code === '9999' && strpos(strtolower($message), 'permisos') !== false) {
                // Modo simulación si hay error de permisos de IP
                $simulated = true;
                $idQr = 'SIM_QR_' . uniqid();
                $qrBase64 = $this->generateSimulatedQrBase64($alias, $amount);
            } else {
                return $this->output
                    ->set_status_header(500)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['error' => 'Error de la API del banco: ' . $message]));
            }
        } else {
            // Si la conexión falla, se activa la simulación para no bloquear la venta local
            $simulated = true;
            $idQr = 'SIM_QR_' . uniqid();
            $qrBase64 = $this->generateSimulatedQrBase64($alias, $amount);
        }

        // Registrar transacción en la BD
        $this->saveTransaction($alias, $amount, $proformaId, $idQr, $qrBase64);

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status' => 'success',
                'alias' => $alias,
                'qr_base64' => $qrBase64,
                'imagenQr' => $qrBase64,
                'idQr' => $idQr,
                'simulated' => $simulated
            ]));
    }

    /**
     * FASE 2: Exposición del Callback (Recepción del webhook de pago desde el Banco).
     * Procesa la confirmación del pago bajo Basic Auth y actualiza la proforma.
     */
    public function callback_pago()
    {
        // 0. Registrar log de diagnóstico en la base de datos (antes de Basic Auth)
        $rawInput = $this->input->raw_input_stream;
        $input = json_decode($rawInput, true);
        $alias = $input['alias'] ?? null;
        $amount = isset($input['monto']) ? floatval($input['monto']) : null;

        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        $this->db->insert('bisa_qr_callback_logs', [
            'alias' => $alias,
            'monto' => $amount,
            'headers' => json_encode($headers, JSON_PRETTY_PRINT),
            'raw_body' => $rawInput,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'fecha_registro' => date('Y-m-d H:i:s')
        ]);

        // 1. Validar autenticación básica (Basic Auth)
        if (!$this->validateBasicAuth()) {
            return $this->output
                ->set_status_header(401)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Acceso no autorizado']));
        }

        // 2. Leer cuerpo JSON recibido
        $rawInput = $this->input->raw_input_stream;
        $input = json_decode($rawInput, true);

        if (empty($input)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Datos vacíos o inválidos']));
        }

        $alias = $input['alias'] ?? null;
        $amount = floatval($input['monto'] ?? 0);
        $idQr = $input['idQr'] ?? null;
        $numeroOrdenOriginante = $input['numeroOrdenOriginante'] ?? null;

        if (empty($alias) || $amount <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Alias o monto inválido']));
        }

        // 3. Buscar la transacción en bisa_qr_transacciones o proformas
        $tx = $this->db->get_where('bisa_qr_transacciones', ['alias' => $alias])->row();
        $proforma = null;
        $expectedAmount = 0.0;

        if ($tx) {
            $expectedAmount = floatval($tx->monto);
            if (!empty($tx->id_proforma)) {
                $proforma = $this->db->get_where('proformas', ['idproforma' => $tx->id_proforma])->row();
            }
        } else {
            // Si no hay transacción registrada, buscar si el alias es el ID directo de una proforma
            $proforma = $this->db->get_where('proformas', ['idproforma' => $alias])->row();
            if ($proforma) {
                $expectedAmount = floatval($proforma->total);
            } else {
                return $this->output
                    ->set_status_header(404)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['error' => 'Transacción o proforma no encontrada']));
            }
        }

        // 4. Validar monto para evitar fraudes (tolerancia de centavos)
        if (abs($expectedAmount - $amount) > 0.05) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El monto pagado no coincide con el total registrado']));
        }

        // 5. Iniciar transacción en la base de datos
        $this->db->trans_start();

        // Actualizar tabla proformas si existe vinculación
        if ($proforma) {
            $this->db->where('idproforma', $proforma->idproforma)->update('proformas', [
                'estado' => 'PAGADO',
                'idQr' => $idQr,
                'numeroOrdenOriginante' => $numeroOrdenOriginante,
                'formapago' => 'qr_bisa',
                'pago' => $amount,
                'saldo' => 0
            ]);
        }

        // Actualizar tabla ventas si existe vinculación
        if ($tx && $tx->id_venta) {
            $this->db->where('idventa', $tx->id_venta)->update('ventas', [
                'pago' => $amount,
                'saldo' => 0
            ]);
        }

        // Sincronizar tabla de transacciones bisa_qr_transacciones
        if ($tx) {
            $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
                'estado' => 'PAGADO',
                'id_qr' => $idQr ? $idQr : $tx->id_qr,
                'numero_orden_originante' => $numeroOrdenOriginante,
                'fecha_pago' => date('Y-m-d H:i:s')
            ]);
        } else {
            $this->db->insert('bisa_qr_transacciones', [
                'alias' => $alias,
                'monto' => $amount,
                'id_proforma' => $proforma ? $proforma->idproforma : null,
                'id_qr' => $idQr,
                'estado' => 'PAGADO',
                'numero_orden_originante' => $numeroOrdenOriginante,
                'fecha_registro' => date('Y-m-d H:i:s'),
                'fecha_pago' => date('Y-m-d H:i:s')
            ]);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al guardar la confirmación del pago en la base de datos']));
        }

        // 6. Retornar respuesta obligatoria al banco
        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'codigo' => '0000',
                'mensaje' => 'Registro Exitoso'
            ]));
    }

    /**
     * FASE 3.1: Consulta manual de estado (Plan de respaldo).
     * Consulta el estado de transacción directamente en SIP y sincroniza en caso de éxito.
     */
    public function verificar_estado($alias = null)
    {
        if (empty($alias)) {
            $alias = $this->input->get_post('alias');
        }

        if (empty($alias)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Alias requerido']));
        }

        // Consultar transacción local primero
        $tx = $this->db->get_where('bisa_qr_transacciones', ['alias' => $alias])->row();

        // Si ya está pagada localmente, retornamos PAGADO de inmediato sin consultar al banco
        if ($tx && $tx->estado === 'PAGADO') {
            return $this->output
                ->set_status_header(200)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'estado' => 'PAGADO'
                ]));
        }

        // Validar si es una transacción simulada
        $isSimulated = $tx && (strpos($tx->id_qr, 'SIM_QR_') !== false);
        if ($isSimulated) {
            return $this->output
                ->set_status_header(200)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'estado' => $tx->estado
                ]));
        }

        // Consultar configuración bancaria
        $config = $this->db->get_where('bisa_qr_config', ['id' => 1])->row();
        if (!$config) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Configuración no encontrada']));
        }

        $token = $this->getAccessToken($config);
        if (!$token) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se pudo obtener el token de autenticación']));
        }

        $url = $config->api_url . '/api/v1/estadoTransaccion';
        $headers = [
            'Authorization: Bearer ' . $token,
            'apikeyServicio: ' . $config->service_key,
            'Content-Type: application/json'
        ];

        $body = [
            'alias' => $alias
        ];

        $response = $this->sendPostRequest($url, $headers, $body);
        if (!$response) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No hubo respuesta del banco al consultar el estado']));
        }

        $resData = json_decode($response, true);
        $state = isset($resData['objeto']['estado']) ? strtoupper($resData['objeto']['estado']) : 'PENDIENTE';
        $idQr = $resData['objeto']['idQr'] ?? null;
        $numeroOrdenOriginante = $resData['objeto']['numeroOrdenOriginante'] ?? null;

        // Sincronizar estado local si el pago se completó en el banco
        if ($state === 'PAGADO') {
            $this->db->trans_start();

            $proforma = $this->db->get_where('proformas', ['idproforma' => $alias])->row();
            if ($proforma && $proforma->estado !== 'PAGADO') {
                $this->db->where('idproforma', $alias)->update('proformas', [
                    'estado' => 'PAGADO',
                    'idQr' => $idQr,
                    'numeroOrdenOriginante' => $numeroOrdenOriginante,
                    'formapago' => 'qr_bisa',
                    'pago' => $proforma->total,
                    'saldo' => 0
                ]);
            }

            if ($tx) {
                $this->db->where('alias', $alias)->update('bisa_qr_transacciones', [
                    'estado' => 'PAGADO',
                    'id_qr' => $idQr ? $idQr : $tx->id_qr,
                    'numero_orden_originante' => $numeroOrdenOriginante,
                    'fecha_pago' => date('Y-m-d H:i:s')
                ]);

                if ($tx->id_venta) {
                    $this->db->where('idventa', $tx->id_venta)->update('ventas', [
                        'pago' => $tx->monto,
                        'saldo' => 0
                    ]);
                }
            } else {
                $this->db->insert('bisa_qr_transacciones', [
                    'alias' => $alias,
                    'monto' => $proforma ? $proforma->total : 0,
                    'id_proforma' => $proforma ? $proforma->idproforma : null,
                    'id_qr' => $idQr,
                    'estado' => 'PAGADO',
                    'numero_orden_originante' => $numeroOrdenOriginante,
                    'fecha_registro' => date('Y-m-d H:i:s'),
                    'fecha_pago' => date('Y-m-d H:i:s')
                ]);
            }

            $this->db->trans_complete();
        }

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status' => 'success',
                'estado' => $state
            ]));
    }

    /**
     * Obtiene el token de autenticación (JWT) desde la tabla de configuración.
     * Renueva el token si expiró o está próximo a expirar.
     */
    private function getAccessToken($config)
    {
        // Validar vigencia del token (más de 60 segundos)
        if (!empty($config->token) && !empty($config->token_expires_at)) {
            $expiresAt = strtotime($config->token_expires_at);
            if ($expiresAt > (time() + 60)) {
                return $config->token;
            }
        }

        $url = $config->api_url . '/autenticacion/v1/generarToken';
        $headers = [
            'apikey: ' . $config->api_key,
            'Content-Type: application/json'
        ];

        $body = [
            'username' => $config->username,
            'password' => $config->password
        ];

        $response = $this->sendPostRequest($url, $headers, $body);
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        $token = $data['objeto']['token'] ?? null;

        if ($token) {
            $validity = isset($data['objeto']['validez']) ? intval($data['objeto']['validez']) : 3600;
            $expiresAtStr = date('Y-m-d H:i:s', time() + $validity);

            // Guardar token y fecha de expiración
            $this->db->where('id', $config->id)->update('bisa_qr_config', [
                'token' => $token,
                'token_expires_at' => $expiresAtStr,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        return $token;
    }

    /**
     * Guarda de forma segura o actualiza la transacción en bisa_qr_transacciones.
     */
    private function saveTransaction($alias, $amount, $proformaId, $idQr, $qrBase64)
    {
        $txData = [
            'alias' => $alias,
            'monto' => $amount,
            'id_proforma' => $proformaId,
            'id_qr' => $idQr,
            'qr_base64' => $qrBase64,
            'fecha_registro' => date('Y-m-d H:i:s')
        ];

        $existing = $this->db->get_where('bisa_qr_transacciones', ['alias' => $alias])->row();
        if ($existing) {
            // Conservar el estado actual si ya existe
            $this->db->where('alias', $alias)->update('bisa_qr_transacciones', $txData);
        } else {
            $txData['estado'] = 'PENDIENTE';
            $this->db->insert('bisa_qr_transacciones', $txData);
        }
    }

    /**
     * Genera un código QR simulado en Base64 utilizando una API pública.
     */
    private function generateSimulatedQrBase64($alias, $amount)
    {
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

        // Fallback: un píxel transparente en base64
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    }

    /**
     * Valida las credenciales de Basic Auth de la petición.
     */
    private function validateBasicAuth()
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

        // Fallback si se ejecuta bajo CGI/FastCGI y no se puebla $_SERVER['PHP_AUTH_USER'] directamente
        if (empty($user)) {
            $authHeader = $this->input->get_request_header('Authorization', true);
            if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            }
            if (empty($authHeader) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }

            if (!empty($authHeader) && strpos(strtolower($authHeader), 'basic ') === 0) {
                $authData = base64_decode(substr($authHeader, 6));
                if ($authData !== false) {
                    $parts = explode(':', $authData, 2);
                    if (count($parts) === 2) {
                        $user = $parts[0];
                        $pass = $parts[1];
                    }
                }
            }
        }

        $isValid = ($user === self::BASIC_AUTH_USER && $pass === self::BASIC_AUTH_PASS);

        if ($isValid) {
            return true;
        }

        // Si falla la validación pero estamos en entorno de pruebas/sandbox, permitir el callback para no trabar las pruebas locales
        $config = $this->db->get_where('bisa_qr_config', ['id' => 1])->row();
        if ($config && (strpos($config->api_url, 'dev-sip') !== false || strpos($config->api_url, 'mc4') !== false)) {
            log_message('debug', "PasarelaQr Webhook: Basic Auth falló en desarrollo, pero se permite por ser entorno Sandbox de pruebas.");
            return true;
        }

        return false;
    }

    /**
     * Endpoint público para visualizar el código QR como imagen.
     * Útil para enviar por enlaces de WhatsApp.
     */
    public function ver_qr_imagen($alias = null)
    {
        if (empty($alias)) {
            show_404();
        }

        $tx = $this->db->get_where('bisa_qr_transacciones', ['alias' => $alias])->row();
        if (!$tx || empty($tx->qr_base64)) {
            show_404();
        }

        // Decodificar la imagen base64
        $imgData = base64_decode($tx->qr_base64);

        if ($imgData === false) {
            show_404();
        }

        // Retornar como imagen PNG
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($imgData));
        echo $imgData;
        exit();
    }

    /**
     * Realiza una solicitud HTTP POST mediante cURL.
     */
    private function sendPostRequest($url, $headers, $body)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }
}
