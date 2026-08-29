<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Comisiones extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
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
     * Lista las comisiones agrupadas por vendedor.
     * Permite filtrar por estado "pendientes" o "pagadas".
     */
    public function listar_comisiones() {
        $this->check_permission('Comisiones', 'ver');
        $estado = $this->input->get('estado') ?: 'pendientes';
        
        $vendedor_expr = "COALESCE(NULLIF(CONVERT(dv.vendedor USING utf8mb4), '0'), NULLIF(CONVERT(v_sale.vendedor USING utf8mb4), '0'), CONVERT(v_sale.idusr USING utf8mb4))";

        // Obtener IDs de vendedores y sus nombres
        $this->db->select($vendedor_expr . ' as vendedor_id, MAX(v.nombre) as vendedor_nombre, MAX(v.carnet) as carnet, MAX(v.nro_cuenta) as nro_cuenta, MAX(v.banco) as banco, MAX(v.ciudad) as sucursal_id, MAX(d.nombre) as sucursal_nombre, SUM(dv.comision * dv.cuantos) as total_comision, COUNT(dv.id) as cantidad_productos, DATE_FORMAT(MAX(dv.pagocomision), "%d/%m/%Y %H:%i") as fecha_pago', FALSE);
        $this->db->from('detalleventas dv');
        $this->db->join('ventas v_sale', 'CONVERT(dv.idventa USING utf8mb4) = CONVERT(v_sale.idventa USING utf8mb4)', 'left', FALSE);
        $this->db->join('vendedores v', $vendedor_expr . ' = v.id', 'left', FALSE);
        $this->db->join('depositos d', 'v.ciudad = d.id', 'left');
        
        // Solo las filas que generen comisión
        $this->db->where('dv.comision >', 0);
        // Excluir a los que tienen recibe_comision = 0
        $this->db->where('(v.recibe_comision IS NULL OR v.recibe_comision = 1)');
        // Filtrar únicamente a los usuarios que tengan el rol de Vendedor / Vendedores
        $this->db->where("(TRIM(LOWER(v.rol)) IN ('vendedores', 'vendedor') OR v.id IN (SELECT vendedor_id FROM vendedores_roles WHERE TRIM(LOWER(rol)) IN ('vendedores', 'vendedor')))", NULL, FALSE);

        $vendedor_id = $this->input->get('vendedor_id');
        if (!empty($vendedor_id)) {
            $this->db->where($vendedor_expr . ' = ' . $this->db->escape($vendedor_id), NULL, FALSE);
        }

        $sucursal_id = $this->input->get('sucursal_id');
        if (!empty($sucursal_id)) {
            if ($sucursal_id === 'sin_sucursal') {
                $this->db->group_start();
                $this->db->where('v.ciudad IS NULL');
                $this->db->or_where('v.ciudad', '');
                $this->db->or_where('v.ciudad', '0');
                $this->db->group_end();
            } else {
                $this->db->where('v.ciudad', $sucursal_id);
            }
        }

        if ($estado === 'pendientes') {
            $this->db->where('dv.pagocomision IS NULL');
            // Excluir vendedores que ya están en un lote pendiente de confirmación en el historial
            $this->db->where($vendedor_expr . " NOT IN (SELECT vendedor_id FROM historial_pagos_comisiones WHERE estado = 'Pendiente_Confirmar')", NULL, FALSE);
        } elseif ($estado === 'confirmacion_pendiente') {
            $this->db->where('dv.pagocomision IS NULL');
            // Únicamente los vendedores que tienen un lote masivo pendiente de confirmación
            $this->db->where($vendedor_expr . " IN (SELECT vendedor_id FROM historial_pagos_comisiones WHERE estado = 'Pendiente_Confirmar')", NULL, FALSE);
        } else {
            $this->db->where('dv.pagocomision IS NOT NULL');
        }

        $this->db->group_by($vendedor_expr, FALSE);
        $this->db->order_by('total_comision', 'DESC');
        
        $query = $this->db->get();
        $resultados = $query->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($resultados));
    }

    /**
     * Muestra el detalle de los productos vendidos por un vendedor específico
     * que componen la comisión (pendiente o pagada).
     */
    public function detalle_vendedor() {
        // $this->check_permission('Comisiones', 'ver');
        $vendedor_id = $this->input->get('vendedor_id');
        $estado = $this->input->get('estado') ?: 'pendientes';

        if (!$vendedor_id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'Falta el ID del vendedor']));
        }

        $vendedor_expr = "COALESCE(NULLIF(CONVERT(dv.vendedor USING utf8mb4), '0'), NULLIF(CONVERT(v.vendedor USING utf8mb4), '0'), CONVERT(v.idusr USING utf8mb4))";

        $this->db->select("dv.id as id_detalle, dv.idprod, COALESCE(dv.descripcion, i.descripcion) as descripcion, dv.precioventa, dv.cuantos, dv.comision, (dv.comision * dv.cuantos) as subtotal_comision, DATE_FORMAT(v.fecha, '%Y-%m-%d %H:%i:%s') as fecha_venta, v.id as id_venta, v.idventa as pedido_id, prof.id as proforma_id, COALESCE(prof.id, v.id) as pedido_num_id, d.nombre as sucursal_nombre, DATE_FORMAT(dv.pagocomision, '%d/%m/%Y %H:%i') as fecha_pago, vend.nombre as vendedor_nombre, vend.telefono as vendedor_telefono, v.cliente, v.telefono as cliente_telefono", FALSE);
        $this->db->from('detalleventas dv');
        $this->db->join('ventas v', 'CONVERT(dv.idventa USING utf8mb4) = CONVERT(v.idventa USING utf8mb4)', 'left', FALSE);
        $this->db->join('proformas prof', 'CONVERT(v.idventa USING utf8mb4) = CONVERT(prof.idproforma USING utf8mb4) OR CONVERT(v.idventa USING utf8mb4) = CONVERT(prof.id USING utf8mb4) OR v.id = prof.id', 'left', FALSE);
        $this->db->join('vendedores vend', $vendedor_expr . ' = vend.id', 'left', FALSE);
        $this->db->join('depositos d', 'v.idneg = d.id', 'left');
        $this->db->join('inventarios i', 'dv.idprod = i.id', 'left');
        $this->db->where($vendedor_expr . ' = ' . $this->db->escape((string)$vendedor_id), NULL, FALSE);
        $this->db->where('dv.comision >', 0);

        if ($estado === 'pendientes' || $estado === 'confirmacion_pendiente') {
            $this->db->where('dv.pagocomision IS NULL');
        } else {
            $this->db->where('dv.pagocomision IS NOT NULL');
        }

        $this->db->order_by('v.fecha', 'DESC');

        $query = $this->db->get();
        $detalles = $query->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($detalles));
    }

    /**
     * Muestra las ventas realizadas por encargados de tienda o sin vendedor asignado de todas las sucursales.
     * GET /comisiones/ventas_sin_vendedor
     */
    public function ventas_sin_vendedor() {
        $vendedor_expr = "COALESCE(NULLIF(CONVERT(dv.vendedor USING utf8mb4), '0'), NULLIF(CONVERT(v.vendedor USING utf8mb4), '0'), CONVERT(v.idusr USING utf8mb4))";

        $this->db->select("dv.id as id_detalle, dv.idprod, COALESCE(dv.descripcion, i.descripcion) as descripcion, dv.precioventa, dv.cuantos, dv.comision, (dv.comision * dv.cuantos) as subtotal_comision, DATE_FORMAT(v.fecha, '%Y-%m-%d %H:%i:%s') as fecha_venta, COALESCE(d.nombre, 'Sucursal') as sucursal_nombre, COALESCE(v.cliente, vend.nombre, 'SIN CLIENTE') as cliente, COALESCE(v.telefono, vend.telefono, '') as cliente_telefono, COALESCE(vend.nombre, v.cliente, 'ENCARGADO DE TIENDA') as encargado_nombre, COALESCE(vend.telefono, v.telefono, '') as encargado_telefono", FALSE);
        $this->db->from('detalleventas dv');
        $this->db->join('ventas v', 'CONVERT(dv.idventa USING utf8mb4) = CONVERT(v.idventa USING utf8mb4)', 'left', FALSE);
        $this->db->join('vendedores vend', $vendedor_expr . ' = vend.id', 'left', FALSE);
        $this->db->join('depositos d', 'v.idneg = d.id', 'left');
        $this->db->join('inventarios i', 'dv.idprod = i.id', 'left');

        // Filtrar ventas realizadas por encargados de tienda (recibe_comision = 0 o rol != vendedores)
        $this->db->group_start();
        $this->db->where('vend.recibe_comision', 0);
        $this->db->or_where("TRIM(LOWER(vend.rol)) NOT IN ('vendedores', 'vendedor')", NULL, FALSE);
        $this->db->or_where('vend.id IS NULL', NULL, FALSE);
        $this->db->group_end();

        $this->db->where('dv.comision >', 0);
        $this->db->order_by('v.fecha', 'DESC');
        $this->db->limit(500);

        $query = $this->db->get();
        $detalles = $query->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($detalles));
    }

    /**
     * Paso 1: Registra las comisiones como "Pendiente_Confirmar" en historial_pagos_comisiones
     * y genera la respuesta con los datos de cuenta de los vendedores para descargar el archivo de texto.
     */
    public function generar_pago_masivo() {
        $this->check_permission('Comisiones', 'editar');
        $data = json_decode(file_get_contents('php://input'), true);

        $vendedores = $data['vendedores'] ?? [];
        $usuario_id = $this->input->get_request_header('X-User-Id', TRUE) ?: 1;

        if (empty($vendedores)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se enviaron datos de vendedores para pagar.']));
        }

        $this->db->trans_start();

        $fecha_generacion = date('Y-m-d H:i:s');
        $historial_rows = [];

        foreach ($vendedores as $v) {
            $historial_rows[] = [
                'vendedor_id'      => intval($v['vendedor_id']),
                'monto'            => floatval($v['monto']),
                'nro_cuenta'       => $v['nro_cuenta'] ?? null,
                'banco'            => $v['banco'] ?? null,
                'estado'           => 'Pendiente_Confirmar',
                'usuario_genero'   => $usuario_id,
                'fecha_generacion' => $fecha_generacion
            ];
        }

        if (!empty($historial_rows)) {
            $this->db->insert_batch('historial_pagos_comisiones', $historial_rows);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al generar el registro de pago masivo.']));
        }

        // Consultar la tabla de bancos para obtener el mapa de código de otros bancos por nombre
        $bancos_db = $this->db->get('bancos')->result();
        $mapa_bancos = [];
        foreach ($bancos_db as $b) {
            $mapa_bancos[strtoupper(trim($b->nombrebanco))] = $b->codigo;
        }

        $items_csv = [];
        foreach ($vendedores as $v) {
            $v_id = intval($v['vendedor_id']);
            $v_row = $this->db->get_where('vendedores', ['id' => $v_id])->row();
            
            $banco_nombre = trim($v_row->banco ?? $v['banco'] ?? '');
            $banco_upper = strtoupper($banco_nombre);
            
            $es_bmsc = (strpos($banco_upper, 'MERCANTIL') !== false || strpos($banco_upper, 'BMSC') !== false || strpos($banco_upper, 'SANTA CRUZ') !== false);
            
            $cuenta_bmsc = '';
            $codigo_otro_banco = '';
            $cuenta_otro_banco = '';
            $nro_cuenta = trim($v_row->nro_cuenta ?? $v['nro_cuenta'] ?? '');

            if ($es_bmsc) {
                $cuenta_bmsc = $nro_cuenta;
            } else {
                $cuenta_otro_banco = $nro_cuenta;
                // Buscar el código del otro banco en la tabla bancos
                if (isset($mapa_bancos[$banco_upper])) {
                    $codigo_otro_banco = $mapa_bancos[$banco_upper];
                } else {
                    // Intento de búsqueda por coincidencia parcial si el string no coincide 100% exacto
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
                'ci_nit'              => $v_row->carnet ?? '',
                'nombre_beneficiario' => $v_row->nombre ?? '',
                'cuenta_bmsc'         => $cuenta_bmsc,
                'fecha_pago'          => date('d/m/Y', strtotime($fecha_generacion)),
                'tipo_pago'           => $tipo_pago,
                'importe'             => number_format(floatval($v['monto']), 2, '.', ''),
                'codigo_otro_banco'   => $codigo_otro_banco,
                'cuenta_otro_banco'   => $cuenta_otro_banco,
                'detalle'             => 'pago comision'
            ];
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message'   => 'Lotes de pago masivo registrados en estado Pendiente de Confirmar.',
                'fecha'     => $fecha_generacion,
                'items_csv' => $items_csv
            ]));
    }

    /**
     * Paso 2: Confirma el pago de comisiones de forma selectiva.
     * Marca los confirmados como 'Confirmado' en el historial, y en detalleventas actualiza pagocomision con la fecha/hora.
     * Los no confirmados se eliminan del historial (volviendo a quedar pendientes en el listado general).
     */
    public function confirmar_pago_masivo() {
        $this->check_permission('Comisiones', 'editar');
        $data = json_decode(file_get_contents('php://input'), true);

        $confirmados = $data['confirmados'] ?? []; // Array de {vendedor_id, monto}
        $rechazados = $data['rechazados'] ?? []; // Array de {vendedor_id}
        $usuario_id = $this->input->get_request_header('X-User-Id', TRUE) ?: 1;

        if (empty($confirmados) && empty($rechazados)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se enviaron datos para confirmar o rechazar.']));
        }

        $this->db->trans_start();
        $hoy = date('Y-m-d H:i:s');

        // Procesar confirmados
        foreach ($confirmados as $c) {
            $vend_id = intval($c['vendedor_id']);
            
            // 1. Actualizar el registro del historial a 'Confirmado'
            $this->db->where('vendedor_id', $vend_id);
            $this->db->where('estado', 'Pendiente_Confirmar');
            $this->db->update('historial_pagos_comisiones', [
                'estado' => 'Confirmado',
                'usuario_confirmo' => $usuario_id,
                'fecha_confirmacion' => $hoy
            ]);

            // 2. Marcar como pagados los productos en detalleventas
            $this->db->where('vendedor', $vend_id);
            $this->db->where('comision >', 0);
            $this->db->where('pagocomision IS NULL');
            $this->db->update('detalleventas', ['pagocomision' => $hoy]);
        }

        // Procesar rechazados (se eliminan del historial de pagos masivos para volver a listarse en pendientes)
        foreach ($rechazados as $r) {
            $vend_id = intval($r['vendedor_id']);
            $this->db->where('vendedor_id', $vend_id);
            $this->db->where('estado', 'Pendiente_Confirmar');
            $this->db->delete('historial_pagos_comisiones');
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al procesar la confirmación de pago masivo.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Confirmación de pago procesada correctamente.']));
    }

    /**
     * Obtiene el listado de todos los pagos registrados (tanto Confirmados como Pendientes)
     */
    public function listar_historial_pagos() {
        $this->check_permission('Comisiones', 'ver');
        
        $this->db->select('h.*, v.nombre as vendedor_nombre, ug.nombre as usuario_genero_nombre, uc.nombre as usuario_confirmo_nombre');
        $this->db->from('historial_pagos_comisiones h');
        $this->db->join('vendedores v', 'h.vendedor_id = v.id', 'left');
        $this->db->join('vendedores ug', 'h.usuario_genero = ug.id', 'left');
        $this->db->join('vendedores uc', 'h.usuario_confirmo = uc.id', 'left');
        $this->db->order_by('h.fecha_generacion', 'DESC');
        
        $query = $this->db->get();
        $resultados = $query->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($resultados));
    }

    /**
     * Obtiene el listado completo de vendedores activos
     */
    public function index_vendedores() {
        $this->check_permission('Comisiones', 'ver');
        $this->db->select('id, nombre, ciudad as sucursal_id, recibe_comision');
        $this->db->from('vendedores');
        $this->db->where('estado', 'activo');
        $this->db->where('(recibe_comision IS NULL OR recibe_comision = 1)');
        $this->db->where("(TRIM(LOWER(rol)) IN ('vendedores', 'vendedor') OR id IN (SELECT vendedor_id FROM vendedores_roles WHERE TRIM(LOWER(rol)) IN ('vendedores', 'vendedor')))", NULL, FALSE);
        $this->db->order_by('nombre', 'ASC');
        
        $query = $this->db->get();
        $resultados = $query->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($resultados));
    }
}
