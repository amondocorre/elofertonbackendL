<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Clientes extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // Configuración de cabeceras CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        header('Access-Control-Max-Age: 86400');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit(0);
        }
        
        $this->load->database();
    }

    /**
     * Obtiene el listado de clientes paginado y filtrado de forma ultra-rápida.
     */
    public function index() {
        $search = $this->input->get('q');
        $page = $this->input->get('page') ? intval($this->input->get('page')) : null;
        $limit = $this->input->get('limit') ? intval($this->input->get('limit')) : 100;
        
        // 1. Obtener conteo de registros para paginación
        $count_sql = "SELECT COUNT(*) as total FROM clientes";
        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $count_sql .= " WHERE nombre LIKE '%$search_escaped%' 
                             OR nit LIKE '%$search_escaped%' 
                             OR telefono LIKE '%$search_escaped%' ";
        }
        $total_query = $this->db->query($count_sql);
        $total_records = intval($total_query->row()->total);

        // 2. Obtener registros limitados
        $sql = "
            SELECT clientes.*, 
                   vendedores.nombre as usuario_baja_nombre,
                   (SELECT COUNT(*) FROM ventas WHERE ventas.idcliente = clientes.id) as cant 
            FROM clientes 
            LEFT JOIN vendedores ON clientes.id_usuario_baja = vendedores.id
        ";
        
        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $sql .= " WHERE clientes.nombre LIKE '%$search_escaped%' 
                       OR clientes.nit LIKE '%$search_escaped%' 
                       OR clientes.telefono LIKE '%$search_escaped%' ";
        }
        
        $sql .= " ORDER BY clientes.nombre ASC";

        if ($page !== null) {
            $offset = ($page - 1) * $limit;
            $sql .= " LIMIT $offset, $limit";
        } else {
            $sql .= " LIMIT $limit";
        }
        
        $query = $this->db->query($sql);
        $clientes = $query->result();

        // Si se solicita por página, devolvemos formato estructurado
        if ($page !== null) {
            $response = [
                'data'  => $clientes,
                'total' => $total_records,
                'page'  => $page,
                'pages' => ceil($total_records / $limit)
            ];
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode($response));
        }

        // De lo contrario, formato plano heredado (retro-compatible)
        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($clientes));
    }

    /**
     * Obtiene el Kardex completo de un cliente (Historial de Compras detallado)
     */
    public function kardex($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de cliente no proporcionado']));
        }

        // Obtener historial de ventas (compras del cliente)
        $this->db->select("ventas.*, vendedores.nombre as vendedor_nombre");
        $this->db->from("ventas");
        $this->db->join("vendedores", "ventas.idusr = vendedores.id", "left");
        $this->db->where("ventas.idcliente", $id);
        $this->db->order_by("ventas.fecha", "DESC");
        $ventas = $this->db->get()->result_array();

        // Para cada venta, obtenemos los productos detallados desde detalleventas
        foreach ($ventas as &$v) {
            $this->db->select("detalleventas.idprod, detalleventas.preciofinal, detalleventas.cuantos, detalleventas.descripcion");
            $this->db->from("detalleventas");
            $this->db->where("detalleventas.idventa", $v['idventa']);
            $v['detalles'] = $this->db->get()->result_array();
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($ventas));
    }

    /**
     * Determina si un NIT o CI es un marcador genérico de "sin documento"
     */
    private function is_generic_nit($nit) {
        if (empty($nit)) return true;
        
        $nit_lower = strtolower(trim($nit));
        $generic_placeholders = [
            '0', 's/n', 'sn', 'n/a', 'na', 'sin nit', 'sin numero', 
            'sin número', 'sin nro', 'sin nmero', 'sin documento', 'ninguno'
        ];
        
        // Removemos caracteres no alfanuméricos para una validación más flexible
        $clean_nit = preg_replace('/[^a-z0-9]/', '', $nit_lower);
        $generic_cleans = ['sn', 'na', 'sinnit', 'sinnumero', 'sinnro', 'sindocumento', 'ninguno'];

        if (in_array($clean_nit, $generic_cleans)) {
            return true;
        }

        return in_array($nit_lower, $generic_placeholders);
    }

    /**
     * Verifica si existe un cliente con la misma combinación de NIT/CI y complemento
     */
    public function verify_identity() {
        $nit = $this->input->get('nit');
        $complement = $this->input->get('complemento');
        $id = $this->input->get('id');

        if (!$nit || !$complement) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'NIT/CI y complemento no proporcionados']));
        }

        $this->db->where('nit', trim($nit));
        $this->db->where('complemento', trim($complement));
        if ($id && $id !== 'null') {
            $this->db->where('id !=', intval($id));
        }
        $existing = $this->db->get('clientes')->row();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'exists' => $existing ? true : false,
                'nombre' => $existing ? $existing->nombre : ''
            ]));
    }

    /**
     * Guarda o edita un cliente con validación asertiva de NIT/CI único
     */
    public function guardar() {
        $raw_input = file_get_contents('php://input');
        $data = json_decode($raw_input, true);

        if (!$data) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Datos inválidos']));
        }

        $id = isset($data['id']) ? intval($data['id']) : null;
        $nit = isset($data['nit']) ? trim($data['nit']) : null;
        $telefono = isset($data['telefono']) ? trim($data['telefono']) : (isset($data['celular']) ? trim($data['celular']) : null);
        $complemento = isset($data['complemento']) ? trim($data['complemento']) : null;

        if (empty($data['nombre']) || empty($nit) || empty($telefono)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El nombre, NIT/CI y celular son obligatorios.']));
        }

        // Validar que el NIT/CI contenga solo números
        if (!preg_match('/^\d+$/', $nit)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El NIT/CI debe contener solo números.']));
        }

        // Validar que el celular tenga al menos 8 dígitos (solo números)
        $cleaned_telefono = preg_replace('/\D/', '', $telefono);
        if (strlen($cleaned_telefono) < 8) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El celular debe tener al menos 8 dígitos.']));
        }

        // Validación de combinación NIT/CI y Complemento Duplicados
        if (!empty($nit)) {
            $this->db->where('nit', $nit);
            $this->db->where('complemento', $complemento);
            if ($id) {
                // Si es edición, excluimos al propio cliente
                $this->db->where('id !=', $id);
            }
            $query = $this->db->get('clientes');
            
            if ($query->num_rows() > 0) {
                $cliente_existente = $query->row();
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(400)
                    ->set_output(json_encode([
                        'error' => "Ya existe un cliente registrado con el NIT o CI '$nit' y complemento '$complemento' (Cliente: {$cliente_existente->nombre})"
                    ]));
            }
        }

        $cliente_data = [
            'nombre'        => mb_strtoupper(trim($data['nombre']), 'UTF-8'),
            'telefono'      => $telefono,
            'telefono_fijo' => isset($data['telefono_fijo']) ? trim($data['telefono_fijo']) : null,
            'nit'           => $nit,
            'complemento'   => $complemento,
            'extension'     => isset($data['extension']) ? trim($data['extension']) : null,
            'ubicaciongps'  => isset($data['ubicaciongps']) ? trim($data['ubicaciongps']) : null,
            'cianverso'     => isset($data['cianverso']) ? trim($data['cianverso']) : null,
            'cireverso'     => isset($data['cireverso']) ? trim($data['cireverso']) : null
        ];

        if ($id) {
            // Edición
            $this->db->where('id', $id);
            $this->db->update('clientes', $cliente_data);
            $message = 'Cliente actualizado con éxito';
        } else {
            // Registro
            $this->db->insert('clientes', $cliente_data);
            $id = $this->db->insert_id();
            $message = 'Cliente registrado con éxito';
        }

        // Obtener el registro guardado
        $this->db->where('id', $id);
        $cliente_guardado = $this->db->get('clientes')->row();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message' => $message,
                'cliente' => $cliente_guardado
            ]));
    }

    /**
     * Elimina un cliente por su ID
     */
    public function eliminar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de cliente no proporcionado']));
        }

        // Validar si el cliente tiene ventas registradas antes de borrarlo por integridad referencial
        $this->db->where('idcliente', $id);
        $query = $this->db->get('ventas');
        if ($query->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'No se puede eliminar el cliente porque tiene ventas registradas en el sistema']));
        }

        $this->db->where('id', $id);
        $this->db->delete('clientes');

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Cliente eliminado con éxito']));
    }

    /**
     * Inactiva un cliente registrando el usuario que dio de baja
     */
    public function inactivar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de cliente no proporcionado']));
        }

        $raw_input = file_get_contents('php://input');
        $data = json_decode($raw_input, true);
        $id_usuario_baja = isset($data['id_usuario_baja']) ? intval($data['id_usuario_baja']) : null;

        $this->db->where('id', $id);
        $this->db->update('clientes', [
            'estado' => 'Inactivo',
            'id_usuario_baja' => $id_usuario_baja
        ]);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Cliente inactivado con éxito']));
    }

    /**
     * Reactiva un cliente
     */
    public function reactivar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de cliente no proporcionado']));
        }

        $this->db->where('id', $id);
        $this->db->update('clientes', [
            'estado' => 'Activo',
            'id_usuario_baja' => null
        ]);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Cliente reactivado con éxito']));
    }
}
