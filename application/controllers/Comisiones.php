<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Comisiones extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
        $this->load->helper('url');
        
        // CORS Headers
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Content-Length, Accept-Encoding");
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
    }

    /**
     * Lista las comisiones agrupadas por vendedor.
     * Permite filtrar por estado "pendientes" o "pagadas".
     */
    public function listar_comisiones() {
        $estado = $this->input->get('estado') ?: 'pendientes';
        
        // Obtener IDs de vendedores y sus nombres
        $this->db->select('dv.vendedor as vendedor_id, v.nombre as vendedor_nombre, SUM(dv.comision * dv.cuantos) as total_comision, COUNT(dv.id) as cantidad_productos, MAX(dv.pagocomision) as fecha_pago');
        $this->db->from('detalleventas dv');
        $this->db->join('vendedores v', 'dv.vendedor = v.id', 'left');
        
        // Solo las filas que generen comisión
        $this->db->where('dv.comision >', 0);

        $vendedor_id = $this->input->get('vendedor_id');
        if (!empty($vendedor_id)) {
            $this->db->where('dv.vendedor', $vendedor_id);
        }

        if ($estado === 'pendientes') {
            $this->db->where('dv.pagocomision IS NULL');
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
        $vendedor_id = $this->input->get('vendedor_id');
        $estado = $this->input->get('estado') ?: 'pendientes';

        if (!$vendedor_id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'Falta el ID del vendedor']));
        }

        $this->db->select('dv.id as id_detalle, dv.idprod, i.descripcion, dv.precioventa, dv.cuantos, dv.comision, (dv.comision * dv.cuantos) as subtotal_comision, v.fecha as fecha_venta, v.idventa as numero_venta, dv.pagocomision as fecha_pago');
        $this->db->from('detalleventas dv');
        $this->db->join('ventas v', 'dv.idventa = v.idventa', 'left');
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
     * Recibe un array de IDs de vendedores y marca sus comisiones pendientes como pagadas.
     */
    public function pagar_comisiones() {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !isset($data['vendedores_ids']) || !is_array($data['vendedores_ids'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Datos inválidos. Se requiere un array de IDs de vendedores.']));
        }

        $vendedores_ids = $data['vendedores_ids'];
        
        if (empty($vendedores_ids)) {
             return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'No se enviaron vendedores a pagar.']));
        }

        $this->db->trans_start();

        $fecha_pago = date('Y-m-d H:i:s');

        // Actualizar pagocomision para todos los registros pendientes de los vendedores seleccionados
        $this->db->where_in('vendedor', $vendedores_ids);
        $this->db->where('comision >', 0);
        $this->db->where('pagocomision IS NULL');
        $this->db->update('detalleventas', ['pagocomision' => $fecha_pago]);

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Hubo un error al registrar el pago de las comisiones.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'message' => 'Comisiones pagadas exitosamente.',
                'fecha_pago' => $fecha_pago,
                'vendedores_afectados' => count($vendedores_ids)
            ]));
    }
}
