<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Inventario extends MY_Controller {

    public function __construct() {
        parent::__construct();
        
        $this->load->database();
    }

    /**
     * Obtiene el listado de inventario con paginación, filtros y búsqueda.
     */
    public function index() {
        $this->check_permission('Inventario', 'ver');
        $search = $this->input->get('q');
        $depId = $this->input->get('deposito');
        $stockFilter = $this->input->get('stock_status'); // 'all', 'disponible', 'agotado', 'bajo'
        $page = $this->input->get('page') ? intval($this->input->get('page')) : 1;
        $limit = $this->input->get('limit') ? intval($this->input->get('limit')) : 25;

        // 1. Construir las cláusulas de filtrado
        $this->apply_branch_filter('inventarios.deposito');
        $this->db->select('
            inventarios.id,
            p.idprod,
            p.descripcion AS descripcion,
            COALESCE(inventarios.categoria, p.categoria) AS categoria,
            COALESCE(inventarios.marca, p.marca) AS marca,
            inventarios.unidad,
            inventarios.proveedor,
            COALESCE(inventarios.cantidad, 0) AS cantidad,
            inventarios.precioventa,
            inventarios.preciolocal,
            inventarios.comision,
            inventarios.deposito,
            depositos.nombre as deposito_nombre,
            NULLIF(p.imagen, \'\') AS imagenes
        ', FALSE);
        $this->db->from('productos p');

        $join_condition = 'p.idprod = inventarios.idprod';
        if (!empty($depId) && $depId !== 'all') {
            $join_condition .= ' AND inventarios.deposito = ' . intval($depId);
        }
        $this->db->join('inventarios', $join_condition, 'left', FALSE);
        $this->db->join('depositos', 'inventarios.deposito = depositos.id', 'left');

        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $this->db->group_start();
            $this->db->like('p.descripcion', $search_escaped, 'both', FALSE);
            $this->db->or_like('p.idprod', $search_escaped, 'both', FALSE);
            $this->db->group_end();
        }

        if ($stockFilter === 'disponible') {
            $this->db->where('inventarios.cantidad >', 0);
        } elseif ($stockFilter === 'agotado') {
            $this->db->group_start();
            $this->db->where('inventarios.cantidad <=', 0);
            $this->db->or_where('inventarios.cantidad IS NULL');
            $this->db->group_end();
        } elseif ($stockFilter === 'bajo') {
            $this->db->where('inventarios.cantidad >', 0);
            $this->db->where('inventarios.cantidad <=', 5); // Umbral de stock bajo
        }

        // Clonar la consulta para contar el total antes de aplicar limit/offset
        $count_db = clone $this->db;
        $total_records = $count_db->count_all_results();

        // 2. Obtener los registros paginados
        $this->db->order_by('p.descripcion', 'ASC');
        $offset = ($page - 1) * $limit;
        $this->db->limit($limit, $offset);
        $inventario = $this->db->get()->result();

        $response = [
            'data' => $inventario,
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
     * Obtiene listas de marcas, proveedores, depósitos, categorías y unidades existentes en la base de datos.
     */
    public function opciones() {
        $depositos = $this->db->order_by('nombre', 'ASC')->get('depositos')->result();
        $marcas = $this->db->order_by('nombre', 'ASC')->get('marcas')->result();
        $proveedores = $this->db->order_by('nombre', 'ASC')->get('proveedores')->result();
        
        // Obtener categorías desde la nueva tabla
        $categorias_db = $this->db->where('estado', 'Activo')->order_by('descripcion', 'ASC')->get('categoria_producto')->result();
        
        // Obtener unidades desde la nueva tabla
        $unidades_db = $this->db->where('estado', 'Activo')->order_by('descripcion', 'ASC')->get('unidad_medida')->result();

        $response = [
            'depositos' => $depositos,
            'marcas' => $marcas,
            'proveedores' => $proveedores,
            'categorias' => $categorias_db,
            'unidades' => $unidades_db
        ];

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode($response));
    }

    /**
     * Guarda o actualiza un registro de inventario.
     */
    public function guardar() {
        $data = json_decode(file_get_contents('php://input'), true);

        $id = isset($data['id']) ? intval($data['id']) : null;
        if ($id) {
            $this->check_permission('Inventario', 'editar');
        } else {
            $this->check_permission('Inventario', 'crear');
        }
        $idprod = isset($data['idprod']) ? trim($data['idprod']) : '';
        $descripcion = isset($data['descripcion']) ? trim($data['descripcion']) : '';
        
        $marca = isset($data['marca']) ? trim($data['marca']) : '';
        $categoria = isset($data['categoria']) ? trim($data['categoria']) : '';
        $unidad = isset($data['unidad']) ? trim($data['unidad']) : 'unid';
        $proveedor = isset($data['proveedor']) ? trim($data['proveedor']) : '';
        $imagenes = isset($data['imagenes']) ? trim($data['imagenes']) : '';

        $precioventa = isset($data['precioventa']) ? floatval($data['precioventa']) : 0.0;
        $preciolocal = isset($data['preciolocal']) ? floatval($data['preciolocal']) : 0.0;
        $cantidad = isset($data['cantidad']) ? floatval($data['cantidad']) : 0.0;
        $comision = isset($data['comision']) ? floatval($data['comision']) : 0.0;
        $deposito = isset($data['deposito']) ? intval($data['deposito']) : 1;

        if (empty($idprod) || empty($descripcion)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El código y la descripción son obligatorios.']));
        }

        // Comprobación de duplicación de código en el mismo depósito
        $this->db->where('idprod', $idprod);
        $this->db->where('deposito', $deposito);
        if ($id) {
            $this->db->where('id !=', $id);
        }
        $existing = $this->db->get('inventarios')->row();
        if ($existing) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Ya existe un producto con el código "' . $idprod . '" en esta sucursal.']));
        }

        $invData = [
            'idprod' => $idprod,
            'descripcion' => $descripcion,
            'marca' => $marca,
            'categoria' => $categoria,
            'unidad' => $unidad,
            'proveedor' => $proveedor,
            'imagenes' => $imagenes,
            'precioventa' => $precioventa,
            'preciolocal' => $preciolocal,
            'cantidad' => $cantidad,
            'comision' => $comision,
            'deposito' => $deposito
        ];

        if ($id) {
            // Editar
            $this->db->where('id', $id);
            $this->db->update('inventarios', $invData);
            $message = 'Producto de inventario actualizado con éxito.';
        } else {
            // Crear nuevo
            $this->db->insert('inventarios', $invData);
            $message = 'Producto de inventario registrado con éxito.';
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => $message]));
    }

    /**
     * Elimina un producto de inventario si no tiene detalles de venta asociados.
     */
    public function eliminar($id = null) {
        $this->check_permission('Inventario', 'eliminar');
        if (!$id) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'ID de inventario no proporcionado.']));
        }

        // Verificar si tiene transacciones de venta asociadas
        $this->db->where('idprod', $id);
        $query_ventas = $this->db->get('detalleventas');
        if ($query_ventas->num_rows() > 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'No se puede eliminar este producto de inventario porque posee historial de ventas asociado.'
                ]));
        }

        // Eliminar
        $this->db->where('id', $id);
        $this->db->delete('inventarios');

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Producto eliminado del inventario con éxito.']));
    }

    /**
     * Transfiere mercancía de un depósito a otro.
     */
    public function transferir() {
        $data = json_decode(file_get_contents('php://input'), true);

        $idOrigen = isset($data['id_origen']) ? intval($data['id_origen']) : null;
        $depDestino = isset($data['deposito_destino']) ? intval($data['deposito_destino']) : null;
        $cantidadATransferir = isset($data['cantidad']) ? floatval($data['cantidad']) : 0.0;

        if (!$idOrigen || !$depDestino || $cantidadATransferir <= 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Datos de transferencia incompletos o cantidad inválida.']));
        }

        // Obtener el registro de origen
        $this->db->where('id', $idOrigen);
        $origen = $this->db->get('inventarios')->row();

        if (!$origen) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Producto de origen no encontrado.']));
        }

        if ($origen->deposito == $depDestino) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El depósito de destino debe ser diferente al de origen.']));
        }

        if ($origen->cantidad < $cantidadATransferir) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Stock insuficiente para la transferencia. Disponible: ' . $origen->cantidad
                ]));
        }

        $this->db->trans_start();

        // 1. Descontar del origen
        $this->db->set('cantidad', 'cantidad - ' . $cantidadATransferir, FALSE);
        $this->db->where('id', $idOrigen);
        $this->db->update('inventarios');

        // 2. Verificar si el producto ya existe en el depósito de destino
        $this->db->where('idprod', $origen->idprod);
        $this->db->where('deposito', $depDestino);
        $destino = $this->db->get('inventarios')->row();

        if ($destino) {
            // Sumar cantidad al destino existente
            $this->db->set('cantidad', 'cantidad + ' . $cantidadATransferir, FALSE);
            $this->db->where('id', $destino->id);
            $this->db->update('inventarios');
        } else {
            // Crear el registro en el destino
            $destinoData = [
                'idprod' => $origen->idprod,
                'descripcion' => $origen->descripcion,
                'marca' => $origen->marca,
                'categoria' => $origen->categoria,
                'unidad' => $origen->unidad,
                'proveedor' => $origen->proveedor,
                'imagenes' => $origen->imagenes,
                'precioventa' => $origen->precioventa,
                'preciolocal' => $origen->preciolocal,
                'cantidad' => $cantidadATransferir,
                'comision' => $origen->comision,
                'deposito' => $depDestino
            ];
            $this->db->insert('inventarios', $destinoData);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(500)
                ->set_output(json_encode(['error' => 'Ocurrió un error al procesar la transferencia de inventario.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Transferencia de mercancía realizada con éxito.']));
    }

    /**
     * Agrega una nueva unidad de medida a la base de datos
     */
    public function agregar_unidad() {
        $data = json_decode(file_get_contents('php://input'), true);
        $descripcion = isset($data['descripcion']) ? strtoupper(trim($data['descripcion'])) : '';

        if (empty($descripcion)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'La descripción de la unidad no puede estar vacía.']));
        }

        $this->db->where('descripcion', $descripcion);
        $existing = $this->db->get('unidad_medida')->row();
        
        if ($existing) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Ese nombre de unidad de medida ya se encuentra registrado.'
                ]));
        }

        $this->db->insert('unidad_medida', ['descripcion' => $descripcion, 'estado' => 'Activo']);
        $insert_id = $this->db->insert_id();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'idunidad' => $insert_id, 
                'descripcion' => $descripcion,
                'message' => 'Unidad creada correctamente.'
            ]));
    }

    /**
     * Agrega una nueva categoría a la base de datos
     */
    public function agregar_categoria() {
        $data = json_decode(file_get_contents('php://input'), true);
        // Capitalize first letter of each word
        $descripcion = isset($data['descripcion']) ? ucwords(strtolower(trim($data['descripcion']))) : '';

        if (empty($descripcion)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'La descripción de la categoría no puede estar vacía.']));
        }

        $this->db->where('descripcion', $descripcion);
        $existing = $this->db->get('categoria_producto')->row();
        
        if ($existing) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Ese nombre de categoría ya se encuentra registrado.'
                ]));
        }

        $this->db->insert('categoria_producto', ['descripcion' => $descripcion, 'estado' => 'Activo']);
        $insert_id = $this->db->insert_id();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'idcategoria' => $insert_id, 
                'descripcion' => $descripcion,
                'message' => 'Categoría creada correctamente.'
            ]));
    }

    /**
     * Agrega una nueva marca a la base de datos
     */
    public function agregar_marca() {
        $data = json_decode(file_get_contents('php://input'), true);
        $nombre = isset($data['nombre']) ? ucwords(strtolower(trim($data['nombre']))) : '';

        if (empty($nombre)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El nombre de la marca no puede estar vacío.']));
        }

        $this->db->where('nombre', $nombre);
        $existing = $this->db->get('marcas')->row();
        
        if ($existing) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Ese nombre de marca ya se encuentra registrado.'
                ]));
        }

        $this->db->insert('marcas', ['nombre' => $nombre, 'pais' => '']);
        $insert_id = $this->db->insert_id();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'id' => $insert_id, 
                'nombre' => $nombre,
                'message' => 'Marca creada correctamente.'
            ]));
    }
}
