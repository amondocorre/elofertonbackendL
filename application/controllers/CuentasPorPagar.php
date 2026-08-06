<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class CuentasPorPagar extends MY_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function listar()
    {
        $this->check_permission('Cuentas por Pagar', 'ver');
        $this->db->select('cpp.*, cf.nro_comprobante, cf.monto_total, cf.fecha_factura, p.id as pedido_id, pr.nombre as proveedor_nombre, pr.id as proveedor_id');
        $this->db->from('cuentas_por_pagar cpp');
        $this->db->join('compras_facturas cf', 'cpp.factura_id = cf.id', 'left');
        $this->db->join('pedidos p', 'cf.pedido_id = p.id', 'left');
        $this->db->join('proveedores pr', 'p.proveedor_id = pr.id', 'left');
        
        $query = $this->db->get();
        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($query->result_array()));
    }

    public function registrar_pago()
    {
        $this->check_permission('Cuentas por Pagar', 'eliminar');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['cuenta_id']) || empty($data['monto_pagado'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de cuenta y monto del pago son requeridos.']));
        }

        $cuentaId = intval($data['cuenta_id']);
        $montoPagado = floatval($data['monto_pagado']);
        $nota = isset($data['nota']) ? trim($data['nota']) : '';
        $metodo = isset($data['metodo_pago']) ? trim($data['metodo_pago']) : 'Efectivo';
        $userId = $this->input->get_request_header('X-User-Id', TRUE) ?: 1;

        if ($montoPagado <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El monto del pago debe ser mayor a 0.']));
        }

        $cuenta = $this->db->get_where('cuentas_por_pagar', ['id' => $cuentaId])->row_array();
        if (!$cuenta) {
            return $this->output
                ->set_status_header(404)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'La cuenta por pagar especificada no existe.']));
        }

        if (floatval($cuenta['saldo_pendiente']) <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Esta cuenta por pagar ya ha sido cancelada en su totalidad.']));
        }

        if ($montoPagado > floatval($cuenta['saldo_pendiente'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'error' => 'El monto ingresado excede el saldo pendiente actual.'
                ]));
        }

        $this->db->trans_start();

        $historialData = [
            'cuenta_id' => $cuentaId,
            'monto_pagado' => $montoPagado,
            'fecha_pago' => date('Y-m-d H:i:s'),
            'metodo_pago' => $metodo,
            'nota' => $nota,
            'usuario_id' => $userId
        ];
        $this->db->insert('cuentas_por_pagar_pagos', $historialData);
        $pagoId = $this->db->insert_id();

        $nuevoSaldo = floatval($cuenta['saldo_pendiente']) - $montoPagado;
        $estado = ($nuevoSaldo <= 0) ? 'Pagado' : 'Activo';

        $this->db->where('id', $cuentaId);
        $this->db->update('cuentas_por_pagar', [
            'saldo_pendiente' => $nuevoSaldo,
            'estado' => $estado,
            'fecha_actualizacion' => date('Y-m-d H:i:s')
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al registrar el pago en la base de datos.']));
        }

        $cuentaActualizada = $this->db->get_where('cuentas_por_pagar', ['id' => $cuentaId])->row_array();

        return $this->output
            ->set_status_header(201)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Abono registrado con éxito.',
                'pago_id' => $pagoId,
                'saldo_pendiente' => floatval($cuentaActualizada['saldo_pendiente']),
                'estado' => $cuentaActualizada['estado']
            ]));
    }

    public function historial_pagos()
    {
        $this->check_permission('Cuentas por Pagar', 'ver');
        $cuentaId = $this->input->get('cuenta_id');
        if (empty($cuentaId)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El parámetro cuenta_id es obligatorio.']));
        }

        $this->db->select('hp.*, u.nombre as usuario_nombre');
        $this->db->from('cuentas_por_pagar_pagos hp');
        $this->db->join('vendedores u', 'hp.usuario_id = u.id', 'left');
        $this->db->where('hp.cuenta_id', $cuentaId);
        $this->db->order_by('hp.fecha_pago', 'DESC');
        $query = $this->db->get();

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($query->result_array()));
    }

    public function pago_general()
    {
        $this->check_permission('Cuentas por Pagar', 'eliminar');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['proveedor_id']) || empty($data['monto_total_pagar'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de proveedor y monto total son requeridos.']));
        }

        $proveedorId = intval($data['proveedor_id']);
        $montoTotalPagar = floatval($data['monto_total_pagar']);
        $nota = isset($data['nota']) ? trim($data['nota']) : 'Pago General (Global)';
        $metodo = isset($data['metodo_pago']) ? trim($data['metodo_pago']) : 'Efectivo';
        $userId = $this->input->get_request_header('X-User-Id', TRUE) ?: 1;

        if ($montoTotalPagar <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El monto debe ser mayor a 0.']));
        }

        $this->db->select('cpp.*');
        $this->db->from('cuentas_por_pagar cpp');
        $this->db->join('compras_facturas cf', 'cpp.factura_id = cf.id', 'left');
        $this->db->join('pedidos p', 'cf.pedido_id = p.id', 'left');
        $this->db->where('p.proveedor_id', $proveedorId);
        $this->db->where('cpp.saldo_pendiente >', 0);
        $this->db->order_by('cf.fecha_factura', 'ASC'); 
        $cuentasPendientes = $this->db->get()->result_array();

        if (empty($cuentasPendientes)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El proveedor no tiene deudas pendientes.']));
        }

        $deudaTotal = 0;
        foreach ($cuentasPendientes as $c) {
            $deudaTotal += floatval($c['saldo_pendiente']);
        }

        if ($montoTotalPagar > $deudaTotal) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => "El monto ($montoTotalPagar) excede la deuda total ($deudaTotal)."]));
        }

        $this->db->trans_start();

        $montoRestante = $montoTotalPagar;
        $pagosRealizados = [];

        foreach ($cuentasPendientes as $cuenta) {
            if ($montoRestante <= 0) break;

            $saldo = floatval($cuenta['saldo_pendiente']);
            $montoAAbonar = min($saldo, $montoRestante);

            $historialData = [
                'cuenta_id' => $cuenta['id'],
                'monto_pagado' => $montoAAbonar,
                'fecha_pago' => date('Y-m-d H:i:s'),
                'metodo_pago' => $metodo,
                'nota' => $nota,
                'usuario_id' => $userId
            ];
            $this->db->insert('cuentas_por_pagar_pagos', $historialData);
            
            $nuevoSaldo = $saldo - $montoAAbonar;
            $estado = ($nuevoSaldo <= 0) ? 'Pagado' : 'Activo';

            $this->db->where('id', $cuenta['id']);
            $this->db->update('cuentas_por_pagar', [
                'saldo_pendiente' => $nuevoSaldo,
                'estado' => $estado,
                'fecha_actualizacion' => date('Y-m-d H:i:s')
            ]);

            $pagosRealizados[] = [
                'cuenta_id' => $cuenta['id'],
                'monto_abonado' => $montoAAbonar
            ];

            $montoRestante -= $montoAAbonar;
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al procesar el pago general.']));
        }

        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Pago general aplicado con éxito.',
                'pagos_realizados' => $pagosRealizados
            ]));
    }
}
