<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Impuestos extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // Habilitar CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
        
        $this->load->database();
    }

    public function activo() {
        $this->db->where('estado', 'activo');
        $this->db->order_by('fecha_creacion', 'DESC');
        $impuesto = $this->db->get('porcentaje_impuesto')->row();

        if ($impuesto) {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'porcentaje' => floatval($impuesto->porcentaje),
                    'id' => $impuesto->id
                ]));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode([
                'porcentaje' => 0,
                'id' => null
            ]));
    }

    public function index() {
        $this->db->select('porcentaje_impuesto.*, vendedores.nombre as usuario_nombre');
        $this->db->from('porcentaje_impuesto');
        $this->db->join('vendedores', 'porcentaje_impuesto.usuario_id = vendedores.id', 'left');
        $this->db->order_by('fecha_creacion', 'DESC');
        $impuestos = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($impuestos));
    }

    public function guardar() {
        $data = json_decode(file_get_contents('php://input'), true);

        $porcentaje = isset($data['porcentaje']) ? floatval($data['porcentaje']) : 0;
        $usuario_id = isset($data['usuario_id']) ? intval($data['usuario_id']) : 1;

        if ($porcentaje <= 0) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'El porcentaje debe ser mayor a 0']));
        }

        // Iniciar transacción
        $this->db->trans_start();

        // Desactivar cualquier impuesto actualmente activo
        $this->db->where('estado', 'activo');
        $this->db->update('porcentaje_impuesto', ['estado' => 'inactivo']);

        // Insertar el nuevo como activo
        $insertData = [
            'porcentaje' => $porcentaje,
            'fecha_creacion' => date('Y-m-d H:i:s'),
            'usuario_id' => $usuario_id,
            'estado' => 'activo'
        ];
        $this->db->insert('porcentaje_impuesto', $insertData);

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Error al guardar el porcentaje de impuesto']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Porcentaje actualizado correctamente']));
    }

    public function cambiar_estado() {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
        $estado = $data['estado'] ?? '';

        if (!$id || empty($estado)) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'ID y Estado son obligatorios']));
        }

        if ($estado === 'activo') {
            // Desactivar todos primero si vamos a activar uno
            $this->db->where('estado', 'activo');
            $this->db->update('porcentaje_impuesto', ['estado' => 'inactivo']);
        }

        $this->db->where('id', $id);
        $this->db->update('porcentaje_impuesto', ['estado' => $estado]);

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Estado actualizado exitosamente']));
    }
}
