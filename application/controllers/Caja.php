<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Caja extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
        $this->load->helper('url');
        
        // CORS Headers
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header('Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding, Authorization, X-User-Id, X-Rol-Id, X-Active-Branch');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
    }

    /**
     * Devuelve el estado de la caja para un usuario (si tiene una abierta).
     * También calcula el saldo esperado: Apertura + Ingresos + Ventas - Egresos.
     */
    public function estado_caja() {
        $usuario_id = $this->input->get('usuario_id');
        $sucursal_id = $this->input->get('sucursal_id');
        if (!$usuario_id || !$sucursal_id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'ID de usuario y sucursal requeridos']));
        }

        // Buscar caja abierta para este usuario en esta sucursal
        $caja = $this->db->where('usuario_id', $usuario_id)
                         ->where('sucursal_id', $sucursal_id)
                         ->where('estado', 'Abierta')
                         ->get('sesiones_caja')
                         ->row();

        if (!$caja) {
            $otra_caja = $this->db->where('sucursal_id', $sucursal_id)
                                  ->where('estado', 'Abierta')
                                  ->get('sesiones_caja')
                                  ->row();
            return $this->output->set_content_type('application/json')->set_output(json_encode([
                'estado' => 'Cerrada',
                'caja' => null,
                'abierta_por_otro_usuario' => $otra_caja ? true : false,
                'usuario_caja_abierta' => $otra_caja ? $otra_caja->usuario_id : null
            ]));
        }

        // Calcular Ingresos manuales
        $ingresos = $this->db->select_sum('monto')
                             ->where('caja_id', $caja->id)
                             ->where('tipo', 'Ingreso')
                             ->get('movimientos_caja')
                             ->row()->monto ?? 0;

        // Calcular Egresos manuales (excluyendo pagos a transportistas para evitar doble conteo)
        $egresos = $this->db->select_sum('monto')
                            ->where('caja_id', $caja->id)
                            ->where('tipo', 'Egreso')
                            ->where('detalle !=', 'Pago a Transportista')
                            ->get('movimientos_caja')
                            ->row()->monto ?? 0;

        // Calcular Ventas (total pagado) en efectivo realizadas por este usuario desde la fecha de apertura
        $sales = $this->db->select('v.idventa, v.total, v.formapago, v.pago, v.pagomixto, v.fecha, v.comentario, COALESCE(vt.precio_transporte, 0) AS precio_transporte')
                          ->from('ventas v')
                          ->join('ventatransporte vt', 'v.idventa = vt.idventa', 'left')
                          ->where('v.idusr', $usuario_id)
                          ->where('v.fecha >=', $caja->fecha_apertura)
                          ->get()
                          ->result();

        $cashSales = 0.0;
        foreach ($sales as $s) {
            // Ignorar ventas cobradas desde la web
            if (!empty($s->comentario) && stripos($s->comentario, '[WEB_PAGADO]') !== false) {
                continue;
            }

            $cashPaid = 0.0;
            $transportPrice = floatval($s->precio_transporte ?? 0);
            $productTotal = floatval($s->total);

            // Verificar si la forma de pago contiene 'MIXTO' y el desglose de pago mixto no esta vacio
            if ((stripos($s->formapago, 'MIXTO') !== false || $s->formapago === 'mixto') && !empty($s->pagomixto)) {
                $mix = json_decode($s->pagomixto, true);
                if (is_array($mix)) {
                    // Desglose en formato JSON estructurado
                    $cashPaid = floatval($mix['efectivo'] ?? 0);
                } else {
                    // Desglose en formato cadena de texto (ej: "EFECTIVO: 700.00 | TRANSFERENCIA: 499.00")
                    if (preg_match('/EFECTIVO:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                        $cashPaid = floatval($matches[1]);
                    }
                }
            } else if (strtolower($s->formapago) === 'efectivo') {
                $cashPaid = $productTotal;
            } else {
                $cashPaid = 0.0;
            }

            // Sumar cobro de transporte al mismo desglose de pago de la venta si corresponde
            if ($transportPrice > 0 && $productTotal > 0) {
                $cashRatio = $cashPaid / $productTotal;
                $cashPaid += $transportPrice * $cashRatio;
            } elseif ($transportPrice > 0) {
                if (strtolower($s->formapago) === 'efectivo') {
                    $cashPaid += $transportPrice;
                }
            }

            $cashSales += $cashPaid;
        }

        // Calcular salidas reales de efectivo por pagos a transportistas asociados a ventas de este usuario en el turno actual
        $transportPayments = $this->db->select_sum('vt.pago_transporte')
                                      ->from('ventatransporte vt')
                                      ->join('ventas v', 'v.idventa = vt.idventa', 'inner')
                                      ->where('v.idusr', $usuario_id)
                                      ->where('vt.fecha_pago >=', $caja->fecha_apertura)
                                      ->get()
                                      ->row()->pago_transporte ?? 0;

        $saldo_esperado = $caja->monto_apertura + $ingresos + $cashSales - $egresos - $transportPayments;

        // Obtener historial de la caja activa
        $movimientos = $this->db->where('caja_id', $caja->id)
                                ->order_by('fecha_registro', 'DESC')
                                ->get('movimientos_caja')
                                ->result();

        return $this->output->set_content_type('application/json')->set_output(json_encode([
            'estado' => 'Abierta',
            'caja' => $caja,
            'calculos' => [
                'monto_apertura' => (float) $caja->monto_apertura,
                'total_ingresos' => (float) $ingresos,
                'total_egresos'  => (float) $egresos,
                'total_ventas'   => (float) $cashSales,
                'saldo_esperado' => (float) $saldo_esperado
            ],
            'movimientos' => $movimientos
        ]));
    }

    /**
     * Abre una nueva sesión de caja para el usuario.
     */
    public function abrir_caja() {
        $data = json_decode(file_get_contents('php://input'), true);
        $usuario_id = $data['usuario_id'] ?? null;
        $sucursal_id = $data['sucursal_id'] ?? null;
        $monto_apertura = floatval($data['monto_apertura'] ?? 0);

        if (!$usuario_id || !$sucursal_id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'Usuario o sucursal inválido']));
        }

        // Adquirir un bloqueo (lock) en MySQL para evitar race conditions simultáneas
        $lock_name = 'abrir_caja_sucursal_' . $sucursal_id;
        $this->db->query("SELECT GET_LOCK(?, 10)", [$lock_name]);

        // Verificar si la sucursal ya tiene una caja abierta
        $caja_existente = $this->db->where('sucursal_id', $sucursal_id)->where('estado', 'Abierta')->get('sesiones_caja')->row();
        if ($caja_existente) {
            $this->db->query("SELECT RELEASE_LOCK(?)", [$lock_name]);
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'La sucursal ya tiene una caja abierta.']));
        }

        $cajaData = [
            'usuario_id' => $usuario_id,
            'sucursal_id' => $sucursal_id,
            'monto_apertura' => $monto_apertura,
            'estado' => 'Abierta',
            'fecha_apertura' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('sesiones_caja', $cajaData);
        $new_caja_id = $this->db->insert_id();

        // Liberar el bloqueo
        $this->db->query("SELECT RELEASE_LOCK(?)", [$lock_name]);

        return $this->output->set_content_type('application/json')->set_output(json_encode([
            'message' => 'Caja abierta exitosamente',
            'caja_id' => $new_caja_id
        ]));
    }

    /**
     * Cierra la caja actual del usuario guardando el monto de cierre real.
     */
    public function cerrar_caja() {
        $data = json_decode(file_get_contents('php://input'), true);
        $caja_id = $data['caja_id'] ?? null;
        $monto_cierre = floatval($data['monto_cierre'] ?? 0);

        if (!$caja_id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'ID de caja inválido']));
        }

        $this->db->where('id', $caja_id)->update('sesiones_caja', [
            'estado' => 'Cerrada',
            'monto_cierre' => $monto_cierre,
            'fecha_cierre' => date('Y-m-d H:i:s')
        ]);

        return $this->output->set_content_type('application/json')->set_output(json_encode([
            'message' => 'Caja cerrada exitosamente'
        ]));
    }

    /**
     * Cierra la caja actual del usuario sin necesitar el ID (usado desde Ventas.jsx).
     */
    public function cerrar_caja_usuario() {
        $data = json_decode(file_get_contents('php://input'), true);
        $usuario_id = $data['usuario_id'] ?? null;
        $monto_cierre = floatval($data['monto_cierre'] ?? 0);

        $sucursal_id = $data['sucursal_id'] ?? null;

        if (!$usuario_id || !$sucursal_id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'ID de usuario y sucursal inválidos']));
        }

        $caja = $this->db->where('usuario_id', $usuario_id)->where('sucursal_id', $sucursal_id)->where('estado', 'Abierta')->get('sesiones_caja')->row();
        
        if ($caja) {
            $this->db->where('id', $caja->id)->update('sesiones_caja', [
                'estado' => 'Cerrada',
                'monto_cierre' => $monto_cierre,
                'fecha_cierre' => date('Y-m-d H:i:s')
            ]);
        }

        return $this->output->set_content_type('application/json')->set_output(json_encode([
            'message' => 'Caja cerrada exitosamente'
        ]));
    }

    /**
     * Registra un ingreso o egreso manual.
     */
    public function registrar_movimiento() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $caja_id = $data['caja_id'] ?? null;
        $usuario_id = $data['usuario_id'] ?? null;
        $tipo = $data['tipo'] ?? null;
        $detalle = $data['detalle'] ?? null;
        $monto = floatval($data['monto'] ?? 0);
        $informacion_adicional = $data['informacion_adicional'] ?? '';

        if (!$caja_id || !$usuario_id || !$tipo || !$detalle || $monto <= 0) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'Datos inválidos para el movimiento']));
        }

        // Verificar que la caja esté abierta
        $caja = $this->db->where('id', $caja_id)->where('estado', 'Abierta')->get('sesiones_caja')->row();
        if (!$caja) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'La caja está cerrada o no existe']));
        }

        $movData = [
            'caja_id' => $caja_id,
            'usuario_id' => $usuario_id,
            'tipo' => $tipo,
            'detalle' => $detalle,
            'monto' => $monto,
            'informacion_adicional' => $informacion_adicional,
            'fecha_registro' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('movimientos_caja', $movData);

        return $this->output->set_content_type('application/json')->set_output(json_encode([
            'message' => 'Movimiento registrado exitosamente',
            'movimiento_id' => $this->db->insert_id()
        ]));
    }

    /**
     * Devuelve el reporte detallado de cierres de caja con filtros por sucursal y rango de fechas.
     */
    public function reporte_cierres() {
        $inicio = $this->input->get('inicio');
        $fin = $this->input->get('fin');
        $sucursal = $this->input->get('sucursal');

        $this->db->select('sc.*, v.nombre as cajero_nombre, d.nombre as sucursal_nombre, d.id as sucursal_id');
        $this->db->from('sesiones_caja sc');
        $this->db->join('vendedores v', 'sc.usuario_id = v.id', 'inner');
        $this->db->join('depositos d', 'v.ciudad = d.id', 'left');

        if (!empty($sucursal)) {
            $this->db->where('v.ciudad', $sucursal);
        }
        if (!empty($inicio)) {
            $this->db->where('sc.fecha_apertura >=', $inicio . ' 00:00:00');
        }
        if (!empty($fin)) {
            $this->db->where('sc.fecha_apertura <=', $fin . ' 23:59:59');
        }

        $this->db->order_by('sc.fecha_apertura', 'DESC');
        $cierres = $this->db->get()->result();

        $resultado = [];
        foreach ($cierres as $c) {
            $fecha_fin = $c->fecha_cierre ?? date('Y-m-d H:i:s');

            // Calcular Ingresos manuales
            $ingresos = $this->db->select_sum('monto')
                                 ->where('caja_id', $c->id)
                                 ->where('tipo', 'Ingreso')
                                 ->get('movimientos_caja')
                                 ->row()->monto ?? 0;

            // Calcular Egresos manuales
            $egresos = $this->db->select_sum('monto')
                                ->where('caja_id', $c->id)
                                ->where('tipo', 'Egreso')
                                ->where('detalle !=', 'Pago a Transportista')
                                ->get('movimientos_caja')
                                ->row()->monto ?? 0;

            // Calcular Ventas
            $sales = $this->db->select('v.total, v.formapago, v.pago, v.pagomixto, v.comentario, COALESCE(vt.precio_transporte, 0) AS precio_transporte')
                              ->from('ventas v')
                              ->join('ventatransporte vt', 'v.idventa = vt.idventa', 'left')
                              ->where('v.idusr', $c->usuario_id)
                              ->where('v.fecha >=', $c->fecha_apertura)
                              ->where('v.fecha <=', $fecha_fin)
                              ->get()
                              ->result();

            $cashSales = 0.0;
            foreach ($sales as $s) {
                // Ignorar ventas cobradas desde la web
                if (!empty($s->comentario) && stripos($s->comentario, '[WEB_PAGADO]') !== false) {
                    continue;
                }

                $cashPaid = 0.0;
                $transportPrice = floatval($s->precio_transporte ?? 0);
                $productTotal = floatval($s->total);

                if ((stripos($s->formapago, 'MIXTO') !== false || $s->formapago === 'mixto') && !empty($s->pagomixto)) {
                    $mix = json_decode($s->pagomixto, true);
                    if (is_array($mix)) {
                        $cashPaid = floatval($mix['efectivo'] ?? 0);
                    } else {
                        if (preg_match('/EFECTIVO:\s*([0-9.]+)/i', $s->pagomixto, $matches)) {
                            $cashPaid = floatval($matches[1]);
                        }
                    }
                } else if (strtolower($s->formapago) === 'efectivo') {
                    $cashPaid = $productTotal;
                }

                if ($transportPrice > 0 && $productTotal > 0) {
                    $cashRatio = $cashPaid / $productTotal;
                    $cashPaid += $transportPrice * $cashRatio;
                } elseif ($transportPrice > 0) {
                    if (strtolower($s->formapago) === 'efectivo') {
                        $cashPaid += $transportPrice;
                    }
                }

                $cashSales += $cashPaid;
            }

            // Pagos a transportistas
            $transportPayments = $this->db->select_sum('vt.pago_transporte')
                                          ->from('ventatransporte vt')
                                          ->join('ventas v', 'v.idventa = vt.idventa', 'inner')
                                          ->where('v.idusr', $c->usuario_id)
                                          ->where('vt.fecha_pago >=', $c->fecha_apertura)
                                          ->where('vt.fecha_pago <=', $fecha_fin)
                                          ->get()
                                          ->row()->pago_transporte ?? 0;

            $saldo_esperado = $c->monto_apertura + $ingresos + $cashSales - $egresos - $transportPayments;
            $diferencia = 0.0;
            if ($c->estado === 'Cerrada') {
                $diferencia = floatval($c->monto_cierre) - $saldo_esperado;
            }

            $resultado[] = [
                'id' => $c->id,
                'usuario_id' => $c->usuario_id,
                'cajero_nombre' => $c->cajero_nombre,
                'sucursal_nombre' => $c->sucursal_nombre ?? 'General',
                'monto_apertura' => (float) $c->monto_apertura,
                'monto_cierre' => $c->monto_cierre !== null ? (float) $c->monto_cierre : null,
                'estado' => $c->estado,
                'fecha_apertura' => $c->fecha_apertura,
                'fecha_cierre' => $c->fecha_cierre,
                'total_ingresos' => (float) $ingresos,
                'total_egresos' => (float) $egresos,
                'total_ventas' => (float) $cashSales,
                'total_transporte' => (float) $transportPayments,
                'saldo_esperado' => (float) $saldo_esperado,
                'diferencia' => (float) $diferencia
            ];
        }

        return $this->output->set_content_type('application/json')->set_output(json_encode($resultado));
    }
}
