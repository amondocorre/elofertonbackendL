<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Productos extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // CORS Headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
        
        $this->load->database();
    }

    /**
     * Obtiene el listado de productos de la tabla productos con búsqueda y paginación.
     */
    public function index() {
        $search = $this->input->get('q');
        $marca = $this->input->get('marca');
        $categoria = $this->input->get('categoria');
        $estado = $this->input->get('estado');
        $page = $this->input->get('page') ? intval($this->input->get('page')) : 1;
        $limit = $this->input->get('limit') ? intval($this->input->get('limit')) : 25;

        $this->db->select('p.*, pr.nombre AS proveedor_nombre, d.nombre AS deposito_nombre, u.descripcion AS unidad, c.descripcion AS categoria, m.nombre AS marca');
        $this->db->from('productos p');
        $this->db->join('proveedores pr', 'p.proveedor = pr.id', 'left');
        $this->db->join('depositos d', 'p.deposito = d.id', 'left');
        $this->db->join('unidad_medida u', 'p.idunidad = u.idunidad', 'left');
        $this->db->join('categoria_producto c', 'p.idcategoria = c.idcategoria', 'left');
        $this->db->join('marcas m', 'p.idmarca = m.id', 'left');

        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $this->db->group_start();
            $this->db->like('p.descripcion', $search_escaped);
            $this->db->or_like('p.idprod', $search_escaped);
            $this->db->or_like('m.nombre', $search_escaped);
            $this->db->or_like('c.descripcion', $search_escaped);
            $this->db->group_end();
        }

        if (!empty($marca) && $marca !== 'Todas' && $marca !== 'undefined') {
            $this->db->where('m.nombre', $marca);
        }
        if (!empty($categoria) && $categoria !== 'Todas' && $categoria !== 'undefined') {
            $this->db->where('c.descripcion', $categoria);
        }
        if (!empty($estado) && $estado !== 'Todos' && $estado !== 'undefined') {
            $this->db->where('p.estado', $estado);
        }

        // Clonar consulta para total
        $count_db = clone $this->db;
        $total_records = $count_db->count_all_results();

        $this->db->order_by('p.id', 'DESC');
        $offset = ($page - 1) * $limit;
        $this->db->limit($limit, $offset);
        $productos = $this->db->get()->result();

        $response = [
            'data' => $productos,
            'total' => $total_records,
            'page' => $page,
            'pages' => ceil($total_records / $limit),
            'limit' => $limit
        ];

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($response));
    }

    /**
     * Guarda o actualiza un producto individual.
     */
    public function guardar() {
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
        if (strpos($contentType, 'application/json') !== false) {
            $data = json_decode(file_get_contents('php://input'), true);
        } else {
            $data = $this->input->post();
        }

        $id = isset($data['id']) && $data['id'] !== '' && $data['id'] !== 'null' ? intval($data['id']) : null;
        $idprod = isset($data['idprod']) ? trim($data['idprod']) : '';
        $descripcion = isset($data['descripcion']) ? trim($data['descripcion']) : '';
        $idmarca = isset($data['idmarca']) && $data['idmarca'] !== '' ? intval($data['idmarca']) : null;
        $marca = isset($data['marca']) ? trim($data['marca']) : '';
        if (!$idmarca && !empty($marca)) {
            $brand_row = $this->db->where('nombre', $marca)->get('marcas')->row();
            if ($brand_row) {
                $idmarca = $brand_row->id;
            }
        }
        if ($idmarca && empty($marca)) {
            $brand_row = $this->db->where('id', $idmarca)->get('marcas')->row();
            if ($brand_row) {
                $marca = $brand_row->nombre;
            }
        }
        $idcategoria = isset($data['idcategoria']) && $data['idcategoria'] !== '' ? intval($data['idcategoria']) : null;
        $subcategoria = isset($data['subcategoria']) ? trim($data['subcategoria']) : '';
        $idunidad = isset($data['idunidad']) && $data['idunidad'] !== '' ? intval($data['idunidad']) : null;
        $subunidad = isset($data['subunidad']) ? trim($data['subunidad']) : 'unid';
        $preciolocal = isset($data['preciolocal']) ? floatval($data['preciolocal']) : 0.0;
        $precioventa = isset($data['precioventa']) ? floatval($data['precioventa']) : 0.0;
        $nuevoprecio = isset($data['nuevoprecio']) && $data['nuevoprecio'] !== '' ? floatval($data['nuevoprecio']) : null;
        $deposito = isset($data['deposito']) && $data['deposito'] !== '' ? intval($data['deposito']) : null;
        $proveedor = isset($data['proveedor']) && $data['proveedor'] !== '' ? intval($data['proveedor']) : 1;
        $imagen = isset($data['imagen']) ? trim($data['imagen']) : null;
        $comision = isset($data['comision']) && $data['comision'] !== '' ? floatval($data['comision']) : null;
        if (empty($idprod) || empty($descripcion)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El código y la descripción del producto son obligatorios.']));
        }

        // Subir imagen si existe
        if (isset($_FILES['imagen_file']) && !empty($_FILES['imagen_file']['name'])) {
            $upload_path = FCPATH . 'uploads/productos/';
            if (!is_dir($upload_path)) {
                mkdir($upload_path, 0777, true);
            }
            
            $ext = pathinfo($_FILES['imagen_file']['name'], PATHINFO_EXTENSION);
            $safe_idprod = str_replace('/', '-', $idprod);
            $safe_idprod = preg_replace('/[^a-zA-Z0-9._-]/', '', $safe_idprod);
            $new_filename = $safe_idprod . '.' . $ext;

            $config['upload_path'] = $upload_path;
            $config['allowed_types'] = 'gif|jpg|jpeg|png';
            $config['max_size'] = 500; // 500 KB
            $config['file_name'] = $new_filename;
            $config['overwrite'] = TRUE;

            $this->load->library('upload', $config);
            $this->upload->initialize($config);

            if ($this->upload->do_upload('imagen_file')) {
                $uploadData = $this->upload->data();
                $imagen = $uploadData['file_name'];
            } else {
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(400)
                    ->set_output(json_encode(['error' => strip_tags($this->upload->display_errors())]));
            }
        }

        // Validar código duplicado
        $this->db->where('idprod', $idprod);
        if ($id) {
            $this->db->where('id !=', $id);
        }
        $existing = $this->db->get('productos')->row();
        if ($existing) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Ya existe un producto con el código "' . $idprod . '".']));
        }

        $estado = isset($data['estado']) ? trim($data['estado']) : 'Activo';

        $prodData = [
            'idprod' => $idprod,
            'descripcion' => $descripcion,
            'marca' => $marca,
            'idmarca' => $idmarca,
            'idcategoria' => $idcategoria,
            'subcategoria' => $subcategoria,
            'idunidad' => $idunidad,
            'subunidad' => $subunidad,
            'preciolocal' => $preciolocal,
            'precioventa' => $precioventa,
            'nuevoprecio' => $nuevoprecio,
            'deposito' => $deposito,
            'proveedor' => $proveedor,
            'imagen' => $imagen,
            'comision' => $comision,
            'estado' => $estado
        ];

        if ($id) {
            $this->db->where('id', $id);
            $this->db->update('productos', $prodData);
            $message = 'Producto actualizado con éxito.';
        } else {
            $this->db->insert('productos', $prodData);
            $message = 'Producto registrado con éxito.';
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => $message]));
    }

    /**
     * Verifica si un código de producto ya existe
     */
    public function verificar_codigo() {
        $idprod = $this->input->get('idprod');
        $id = $this->input->get('id');

        if (!$idprod) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Código no proporcionado']));
        }

        $this->db->where('idprod', trim($idprod));
        if ($id && $id !== 'null') {
            $this->db->where('id !=', intval($id));
        }
        $existing = $this->db->get('productos')->row();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['exists' => $existing ? true : false]));
    }

    /**
     * Guarda una lista de productos en lote desde importación masiva.
     */
    public function guardar_masivo() {
        $data = json_decode(file_get_contents('php://input'), true);
        $productos = isset($data['productos']) ? $data['productos'] : [];

        if (empty($productos) || !is_array($productos)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'No se recibieron productos para importar.']));
        }

        $this->db->trans_start();

        $creados = 0;
        $actualizados = 0;

        foreach ($productos as $p) {
            $idprod = isset($p['idprod']) ? trim($p['idprod']) : '';
            $descripcion = isset($p['descripcion']) ? trim($p['descripcion']) : '';
            
            if (empty($idprod) || empty($descripcion)) {
                continue; // Saltar registros incompletos
            }

            $prodData = [
                'descripcion' => $descripcion,
                'marca' => isset($p['marca']) ? trim($p['marca']) : '',
                'categoria' => isset($p['categoria']) ? trim($p['categoria']) : '',
                'subcategoria' => isset($p['subcategoria']) ? trim($p['subcategoria']) : '',
                'unidad' => isset($p['unidad']) ? trim($p['unidad']) : 'unid',
                'subunidad' => isset($p['subunidad']) ? trim($p['subunidad']) : 'unid',
                'preciolocal' => isset($p['preciolocal']) ? floatval($p['preciolocal']) : 0.0,
                'precioventa' => isset($p['precioventa']) ? floatval($p['precioventa']) : 0.0,
                'nuevoprecio' => isset($p['nuevoprecio']) && $p['nuevoprecio'] !== '' ? floatval($p['nuevoprecio']) : null,
                'deposito' => isset($p['deposito']) && $p['deposito'] !== '' ? intval($p['deposito']) : null,
                'proveedor' => isset($p['proveedor']) ? intval($p['proveedor']) : 1,
                'imagen' => isset($p['imagen']) ? trim($p['imagen']) : null,
                'comision' => isset($p['comision']) && $p['comision'] !== '' ? floatval($p['comision']) : null,
                'estado' => isset($p['estado']) ? trim($p['estado']) : 'Activo'
            ];

            // Comprobar si existe
            $existing = $this->db->where('idprod', $idprod)->get('productos')->row();
            if ($existing) {
                $this->db->where('id', $existing->id);
                $this->db->update('productos', $prodData);
                $actualizados++;
            } else {
                $prodData['idprod'] = $idprod;
                $this->db->insert('productos', $prodData);
                $creados++;
            }
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(500)
                ->set_output(json_encode(['error' => 'Ocurrió un error en la base de datos al importar los productos.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message' => 'Importación masiva completada con éxito.',
                'creados' => $creados,
                'actualizados' => $actualizados
            ]));
    }

    /**
     * Elimina un producto.
     */
    public function eliminar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID no especificado.']));
        }

        $producto = $this->db->where('id', $id)->get('productos')->row();
        if (!$producto) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Producto no encontrado.']));
        }

        $this->db->where('id', $id);
        $this->db->update('productos', ['estado' => 'Inactivo']);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Producto desactivado con éxito.']));
    }

    /**
     * Activa un producto (cambia estado a Activo).
     */
    public function reactivar($id = null) {
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID no especificado.']));
        }

        $producto = $this->db->where('id', $id)->get('productos')->row();
        if (!$producto) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Producto no encontrado.']));
        }

        $this->db->where('id', $id);
        $this->db->update('productos', ['estado' => 'Activo']);

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Producto reactivado con éxito.']));
    }
}
