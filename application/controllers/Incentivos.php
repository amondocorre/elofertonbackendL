<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Incentivos extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
        $this->load->model('Incentivo_model');
        $this->load->helper('url');

        // CORS Headers
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header('Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding, X-User-Id, X-Rol-Id, X-Active-Branch, Authorization');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
    }

    /**
     * Consulta el progreso mensual de incentivos de un vendedor específico.
     * GET /incentivos/progreso?vendedor_id=X&periodo=YYYY-MM
     */
    public function progreso() {
        $vendedor_id = $this->input->get('vendedor_id');
        $periodo = $this->input->get('periodo') ?: date('Y-m');

        // Si no viene en GET, intentar obtener del header X-User-Id
        if (!$vendedor_id) {
            $vendedor_id = $this->input->get_request_header('X-User-Id', TRUE);
        }

        if (!$vendedor_id) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Falta el ID del vendedor.']));
        }

        $progreso = $this->Incentivo_model->calcular_progreso_vendedor($vendedor_id, $periodo);
        $detalleVentas = $this->Incentivo_model->obtener_detalle_ventas_calificadas($vendedor_id, $periodo);

        $response = [
            'status'         => 'success',
            'periodo'        => $periodo,
            'progreso'       => $progreso,
            'detalle_ventas' => $detalleVentas
        ];

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($response));
    }

    /**
     * Obtiene el consolidado mensual de todos los vendedores para el Administrador.
     * GET /incentivos/consolidado?periodo=YYYY-MM&estado=TODOS&cumple_sucursales=TODOS
     */
    public function consolidado() {
        $this->check_permission('Incentivos', 'ver');

        $periodo = $this->input->get('periodo') ?: date('Y-m');
        $estado = $this->input->get('estado') ?: 'TODOS';
        $sucursal_filtro = $this->input->get('cumple_sucursales') ?: 'TODOS';

        $consolidado = $this->Incentivo_model->obtener_consolidado_admin($periodo, $estado, $sucursal_filtro);
        $escalas = $this->Incentivo_model->obtener_escalas();

        // Totales de resumen
        $totalLiquidacion = 0;
        $totalPagados = 0;
        $vendedoresActivos = 0;
        $vendedoresBloqueados = 0;

        foreach ($consolidado as $c) {
            if ($c['cumple_sucursales'] && $c['monto_bono'] > 0) {
                $vendedoresActivos++;
                if ($c['estado_pago'] === 'PAGADO') {
                    $totalPagados += floatval($c['monto_bono']);
                } else {
                    $totalLiquidacion += floatval($c['monto_bono']);
                }
            } elseif (!$c['cumple_sucursales'] && $c['total_unidades_calificadas'] >= 5) {
                $vendedoresBloqueados++;
            }
        }

        $response = [
            'status'       => 'success',
            'periodo'      => $periodo,
            'resumen'      => [
                'total_a_liquidar'      => $totalLiquidacion,
                'total_pagado'          => $totalPagados,
                'vendedores_con_bono'   => $vendedoresActivos,
                'vendedores_bloqueados' => $vendedoresBloqueados
            ],
            'escalas'      => $escalas,
            'consolidado'  => $consolidado
        ];

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($response));
    }

    /**
     * Obtiene la escala de incentivos configurada.
     * GET /incentivos/escalas
     */
    public function escalas() {
        $escalas = $this->Incentivo_model->obtener_escalas();
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $escalas]));
    }

    /**
     * Registra el pago individual de un bono.
     * POST /incentivos/pagar
     */
    public function pagar() {
        $this->check_permission('Incentivos', 'editar');

        $data = json_decode(file_get_contents('php://input'), true);
        $id_logro = $data['id_logro'] ?? null;
        $usuario_id = $this->input->get_request_header('X-User-Id', TRUE) ?: 1;

        if (!$id_logro) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Falta el ID del bono/logro.']));
        }

        $datos_pago = [
            'metodo_pago'     => $data['metodo_pago'] ?? 'TRANSFERENCIA',
            'nro_comprobante' => $data['nro_comprobante'] ?? '',
            'observaciones'   => $data['observaciones'] ?? ''
        ];

        $resultado = $this->Incentivo_model->registrar_pago_individual($id_logro, $datos_pago, $usuario_id);

        if (!$resultado['status']) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => $resultado['error']]));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'message' => $resultado['message']]));
    }

    /**
     * Procesa la liquidación masiva de bonos seleccionados y devuelve los datos para exportación bancaria.
     * POST /incentivos/pago_masivo
     */
    public function pago_masivo() {
        $this->check_permission('Incentivos', 'editar');

        $data = json_decode(file_get_contents('php://input'), true);
        $logros_ids = $data['logros_ids'] ?? [];
        $usuario_id = $this->input->get_request_header('X-User-Id', TRUE) ?: 1;

        if (empty($logros_ids) || !is_array($logros_ids)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Debe seleccionar al menos un bono para liquidar.']));
        }

        $datos_pago = [
            'metodo_pago'     => $data['metodo_pago'] ?? 'PAGO MASIVO BANCARIO',
            'nro_comprobante' => $data['nro_comprobante'] ?? 'LOTE-INC-' . date('YmdHis'),
            'observaciones'   => $data['observaciones'] ?? 'Liquidación masiva de bonos'
        ];

        $resultado = $this->Incentivo_model->registrar_pago_masivo($logros_ids, $datos_pago, $usuario_id);

        if (!$resultado['status']) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => $resultado['error']]));
        }

        // Consultar la tabla de bancos para obtener el mapa de códigos de otros bancos
        $bancos_db = $this->db->get('bancos')->result();
        $mapa_bancos = [];
        foreach ($bancos_db as $b) {
            $mapa_bancos[strtoupper(trim($b->nombrebanco))] = $b->codigo;
        }

        // Obtener detalles bancarios de los bonos pagados para exportación masiva
        $this->db->select("lmv.monto_bono, lmv.periodo, v.carnet, v.nombre, v.nro_cuenta, v.banco");
        $this->db->from('logros_mensuales_vendedores lmv');
        $this->db->join('vendedores v', 'lmv.vendedor_id = v.id', 'left');
        $this->db->where_in('lmv.id', $logros_ids);
        $bonosRows = $this->db->get()->result();

        $items_csv = [];
        foreach ($bonosRows as $bRow) {
            $banco_nombre = trim($bRow->banco ?? '');
            $banco_upper = strtoupper($banco_nombre);
            $es_bmsc = (strpos($banco_upper, 'MERCANTIL') !== false || strpos($banco_upper, 'BMSC') !== false || strpos($banco_upper, 'SANTA CRUZ') !== false);

            $cuenta_bmsc = '';
            $codigo_otro_banco = '';
            $cuenta_otro_banco = '';
            $nro_cuenta = trim($bRow->nro_cuenta ?? '');

            if ($es_bmsc) {
                $cuenta_bmsc = $nro_cuenta;
            } else {
                $cuenta_otro_banco = $nro_cuenta;
                if (isset($mapa_bancos[$banco_upper])) {
                    $codigo_otro_banco = $mapa_bancos[$banco_upper];
                } else {
                    foreach ($mapa_bancos as $b_nombre => $b_codigo) {
                        if (strpos($b_nombre, $banco_upper) !== false || strpos($banco_upper, $b_nombre) !== false) {
                            $codigo_otro_banco = $b_codigo;
                            break;
                        }
                    }
                }
            }

            $tipo_pago = $es_bmsc ? '1' : '3';

            $items_csv[] = [
                'ci_nit'              => $bRow->carnet ?? '',
                'nombre_beneficiario' => $bRow->nombre ?? '',
                'cuenta_bmsc'         => $cuenta_bmsc,
                'fecha_pago'          => date('d/m/Y'),
                'tipo_pago'           => $tipo_pago,
                'importe'             => number_format(floatval($bRow->monto_bono), 2, '.', ''),
                'codigo_otro_banco'   => $codigo_otro_banco,
                'cuenta_otro_banco'   => $cuenta_otro_banco,
                'detalle'             => 'Pago Bono Incentivos ' . ($bRow->periodo ?? '')
            ];
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'status'    => 'success',
                'message'   => $resultado['message'],
                'pagados'   => $resultado['pagados'],
                'items_csv' => $items_csv
            ]));
    }
}
