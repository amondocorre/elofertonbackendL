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
        
        $this->db->order_by('cf.fecha_factura', 'DESC');
        $query = $this->db->get();
        return $this->output
            ->set_status_header(200)
            ->set_content_type('application/json')
            ->set_output(json_encode($query->result_array()));
    }

    public function registrar_pago()
    {
        $this->check_permission('Cuentas por Pagar', 'eliminar');
        
        $cuentaId = intval($this->input->post('cuenta_id'));
        $montoPagado = floatval($this->input->post('monto_pagado'));
        $nota = trim($this->input->post('nota') ?? '');
        $metodo = trim($this->input->post('metodo_pago') ?? 'Efectivo');
        
        if (empty($cuentaId) || empty($montoPagado)) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!empty($data)) {
                $cuentaId = intval($data['cuenta_id'] ?? 0);
                $montoPagado = floatval($data['monto_pagado'] ?? 0);
                $nota = isset($data['nota']) ? trim($data['nota']) : '';
                $metodo = isset($data['metodo_pago']) ? trim($data['metodo_pago']) : 'Efectivo';
            }
        }
        
        $userId = $this->input->get_request_header('X-User-Id', TRUE) ?: 1;

        if (empty($cuentaId) || empty($montoPagado)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID de cuenta y monto del pago son requeridos.']));
        }

        $montoPagado = floatval($montoPagado);
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

        $comprobante = null;
        if (isset($_FILES['comprobante_file']) && !empty($_FILES['comprobante_file']['name'])) {
            $upload_path = FCPATH . 'uploads/comprobantes_pagos/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0777, true);
            }
            
            $config['upload_path'] = $upload_path;
            $config['allowed_types'] = 'gif|jpg|jpeg|png|webp|pdf';
            $config['max_size'] = 10240; // 10MB
            $config['file_name'] = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['comprobante_file']['name']);

            $this->load->library('upload', $config);

            if ($this->upload->do_upload('comprobante_file')) {
                $uploadData = $this->upload->data();
                $comprobante = $uploadData['file_name'];
            } else {
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(400)
                    ->set_output(json_encode(['error' => strip_tags($this->upload->display_errors())]));
            }
        }

        $this->db->trans_start();

        $historialData = [
            'cuenta_id' => $cuentaId,
            'monto_pagado' => $montoPagado,
            'fecha_pago' => date('Y-m-d H:i:s'),
            'metodo_pago' => $metodo,
            'nota' => $nota,
            'usuario_id' => $userId,
            'comprobante' => $comprobante
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
