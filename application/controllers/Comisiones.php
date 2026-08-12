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
        
        // Obtener IDs de vendedores y sus nombres
        $this->db->select('dv.vendedor as vendedor_id, v.nombre as vendedor_nombre, v.carnet, v.nro_cuenta, v.banco, v.ciudad as sucursal_id, d.nombre as sucursal_nombre, SUM(dv.comision * dv.cuantos) as total_comision, COUNT(dv.id) as cantidad_productos, MAX(dv.pagocomision) as fecha_pago');
        $this->db->from('detalleventas dv');
        $this->db->join('vendedores v', 'dv.vendedor = v.id', 'left');
        $this->db->join('depositos d', 'v.ciudad = d.id', 'left');
        
        // Solo las filas que generen comisión
        $this->db->where('dv.comision >', 0);
        // Excluir a los que tienen recibe_comision = 0
        $this->db->where('(v.recibe_comision IS NULL OR v.recibe_comision = 1)');

        $vendedor_id = $this->input->get('vendedor_id');
        if (!empty($vendedor_id)) {
            $this->db->where('dv.vendedor', $vendedor_id);
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
            $this->db->where("dv.vendedor NOT IN (SELECT vendedor_id FROM historial_pagos_comisiones WHERE estado = 'Pendiente_Confirmar')", NULL, FALSE);
        } elseif ($estado === 'confirmacion_pendiente') {
            $this->db->where('dv.pagocomision IS NULL');
            // Únicamente los vendedores que tienen un lote masivo pendiente de confirmación
            $this->db->where("dv.vendedor IN (SELECT vendedor_id FROM historial_pagos_comisiones WHERE estado = 'Pendiente_Confirmar')", NULL, FALSE);
        } else {
            $this->db->where('dv.pagocomision IS NOT NULL');
        }

        $this->db->group_by('dv.vendedor');
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

        $this->db->select('dv.id as id_detalle, dv.idprod, COALESCE(dv.descripcion, i.descripcion) as descripcion, dv.precioventa, dv.cuantos, dv.comision, (dv.comision * dv.cuantos) as subtotal_comision, v.fecha as fecha_venta, v.idventa as numero_venta, v.id as id_venta, v.idproforma as pedido_id, d.nombre as sucursal_nombre, dv.pagocomision as fecha_pago');
        $this->db->from('detalleventas dv');
        $this->db->join('ventas v', 'dv.idventa = v.idventa', 'left');
        $this->db->join('depositos d', 'v.idneg = d.id', 'left');
        $this->db->join('inventarios i', 'dv.idprod = i.id', 'left');
        $this->db->where('dv.vendedor', $vendedor_id);
        $this->db->where('dv.comision >', 0);

        if ($estado === 'pendientes') {
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

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Lotes de pago masivo registrados en estado Pendiente de Confirmar.',
                'fecha' => $fecha_generacion
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
        $this->db->order_by('nombre', 'ASC');
        
        $query = $this->db->get();
        $resultados = $query->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($resultados));
    }
}
