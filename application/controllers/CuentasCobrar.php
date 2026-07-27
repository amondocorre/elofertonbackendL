<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class CuentasCobrar extends CI_Controller {
    
    public function __construct() {
        parent::__construct();
        $this->load->database();
        $this->check_table();
    }

    private function check_table() {
        $sql = "CREATE TABLE IF NOT EXISTS pagos_ventas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            idventa VARCHAR(50) NOT NULL,
            monto DECIMAL(10,2) NOT NULL,
            fecha_pago DATETIME DEFAULT CURRENT_TIMESTAMP,
            metodo_pago VARCHAR(50),
            usuario_id INT,
            comentario TEXT
        )";
        $this->db->query($sql);
    }

    public function lista() {
        $this->db->select('v.idventa, v.id AS nro_venta, v.fecha, v.cliente, v.total, v.pago, v.saldo, v.comentario, d.nombre AS sucursal');
        $this->db->from('ventas v');
        $this->db->join('depositos d', 'v.idneg = d.id', 'left');
        $this->db->where('v.saldo >', 0);
        $query = $this->db->get();
        return $this->output->set_content_type('application/json')->set_status_header(200)->set_output(json_encode($query->result()));
    }

    public function registrar_pago() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['idventa']) || empty($data['monto'])) {
            return $this->output->set_content_type('application/json')->set_status_header(400)->set_output(json_encode(['error'=>'Datos incompletos']));
        }
        $monto = floatval($data['monto']);
        $venta = $this->db->where('idventa', $data['idventa'])->get('ventas')->row();
        if (!$venta) {
            return $this->output->set_content_type('application/json')->set_status_header(404)->set_output(json_encode(['error'=>'Venta no encontrada']));
        }
        if ($monto > floatval($venta->saldo)) {
            return $this->output->set_content_type('application/json')->set_status_header(400)->set_output(json_encode(['error'=>'El abono no puede superar el saldo actual']));
        }

        $this->db->trans_start();
        $this->db->insert('pagos_ventas', [
            'idventa' => $data['idventa'],
            'monto' => $monto,
            'metodo_pago' => $data['metodo_pago'] ?? 'Efectivo',
            'usuario_id' => $data['usuario_id'] ?? 1,
            'comentario' => $data['comentario'] ?? ''
        ]);
        $pago_id = $this->db->insert_id();

        $nuevo_pago = floatval($venta->pago) + $monto;
        $nuevo_saldo = floatval($venta->saldo) - $monto;
        $this->db->where('idventa', $data['idventa'])->update('ventas', [
            'pago' => $nuevo_pago,
            'saldo' => $nuevo_saldo
        ]);
        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output->set_content_type('application/json')->set_status_header(500)->set_output(json_encode(['error'=>'Error al procesar pago']));
        }
        return $this->output->set_content_type('application/json')->set_status_header(200)->set_output(json_encode(['message'=>'Pago registrado exitosamente', 'pago_id'=>$pago_id]));
    }

    public function historial($idventa) {
        $query = $this->db->where('idventa', $idventa)->order_by('fecha_pago', 'DESC')->get('pagos_ventas');
        return $this->output->set_content_type('application/json')->set_status_header(200)->set_output(json_encode($query->result()));
    }
}
