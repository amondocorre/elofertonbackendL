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
        $activeBranchId = $this->get_active_branch_id();
        
        $this->db->select('
            MAX(inventarios.id) AS id,
            p.idprod,
            MAX(p.descripcion) AS descripcion,
            COALESCE(MAX(inventarios.categoria), MAX(p.categoria)) AS categoria,
            COALESCE(MAX(inventarios.marca), MAX(p.marca)) AS marca,
            COALESCE(MAX(inventarios.unidad), MAX(p.subunidad), "unid") AS unidad,
            COALESCE(MAX(prov.nombre), MAX(inventarios.proveedor), MAX(p.proveedor), "No especificado") AS proveedor,
            COALESCE(SUM(inventarios.cantidad), 0) AS cantidad,
            MAX(p.precioventa) AS precioventa,
            MAX(p.preciolocal) AS preciolocal,
            MAX(p.comision) AS comision,
            MAX(inventarios.deposito) AS deposito,
            MAX(depositos.nombre) as deposito_nombre,
            MAX(NULLIF(p.imagen, \'\')) AS imagenes
        ', FALSE);
        $this->db->from('productos p');

        $join_condition = 'p.idprod = inventarios.idprod';
        if (!empty($depId) && $depId !== 'all') {
            $join_condition .= ' AND inventarios.deposito = ' . intval($depId);
        }
        $this->db->join('inventarios', $join_condition, 'left', FALSE);
        $this->db->join('depositos', 'inventarios.deposito = depositos.id', 'left');
        $this->db->join('proveedores prov', 'p.proveedor = prov.id', 'left');

        if (!empty($search)) {
            $search_escaped = $this->db->escape_like_str(trim($search));
            $this->db->group_start();
            $this->db->like('p.descripcion', $search_escaped, 'both', FALSE);
            $this->db->or_like('p.idprod', $search_escaped, 'both', FALSE);
            $this->db->group_end();
        }

        $this->db->group_by('p.idprod, p.descripcion, p.categoria, p.marca, p.imagen, inventarios.deposito, depositos.nombre');

        if ($stockFilter === 'disponible') {
            $this->db->having('COALESCE(SUM(inventarios.cantidad), 0) >', 0);
        } elseif ($stockFilter === 'agotado') {
            $this->db->having('COALESCE(SUM(inventarios.cantidad), 0) <=', 0);
        } elseif ($stockFilter === 'bajo') {
            $this->db->having('COALESCE(SUM(inventarios.cantidad), 0) >', 0);
            $this->db->having('COALESCE(SUM(inventarios.cantidad), 0) <=', 5); // Umbral de stock bajo
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
        
        // Obtener subcategorías
        $subcategorias_db = $this->db->where('estado', 'Activo')->order_by('nombre', 'ASC')->get('subcategoria')->result();
        
        // Obtener unidades desde la nueva tabla
        $unidades_db = $this->db->where('estado', 'Activo')->order_by('descripcion', 'ASC')->get('unidad_medida')->result();

        $response = [
            'depositos' => $depositos,
            'marcas' => $marcas,
            'proveedores' => $proveedores,
            'categorias' => $categorias_db,
            'subcategorias' => $subcategorias_db,
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

        // Obtener el registro de origen para sacar el idprod y deposito
        $this->db->where('id', $idOrigen);
        $origen_base = $this->db->get('inventarios')->row();

        if (!$origen_base) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(404)
                ->set_output(json_encode(['error' => 'Producto de origen no encontrado.']));
        }

        if ($origen_base->deposito == $depDestino) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El depósito de destino debe ser diferente al de origen.']));
        }

        // Calcular stock total real del producto en esa sucursal
        $this->db->select_sum('cantidad');
        $this->db->where('idprod', $origen_base->idprod);
        $this->db->where('deposito', $origen_base->deposito);
        $total_stock = $this->db->get('inventarios')->row()->cantidad ?? 0;

        if ($total_stock < $cantidadATransferir) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Stock total insuficiente para la transferencia. Disponible: ' . $total_stock
                ]));
        }

        $this->db->trans_start();

        // Obtener todos los lotes con stock mayor a cero
        $this->db->where('idprod', $origen_base->idprod);
        $this->db->where('deposito', $origen_base->deposito);
        $this->db->where('cantidad >', 0);
        $this->db->order_by('id', 'ASC'); // FIFO
        $lotes = $this->db->get('inventarios')->result();

        $restante = $cantidadATransferir;

        foreach ($lotes as $lote) {
            if ($restante <= 0) break;

            $tomar = min($restante, $lote->cantidad);
            
            // 1. Descontar del origen
            $this->db->set('cantidad', 'cantidad - ' . $tomar, FALSE);
            $this->db->where('id', $lote->id);
            $this->db->update('inventarios');

            // 2. Verificar si el producto ya existe en el depósito de destino con este mismo precio
            // (Para no mezclar precios, buscaremos coincidencia por idprod, deposito y precioventa)
            $this->db->where('idprod', $lote->idprod);
            $this->db->where('deposito', $depDestino);
            $this->db->where('precioventa', $lote->precioventa);
            $destino = $this->db->get('inventarios')->row();

            if ($destino) {
                // Sumar cantidad al destino existente
                $this->db->set('cantidad', 'cantidad + ' . $tomar, FALSE);
                $this->db->where('id', $destino->id);
                $this->db->update('inventarios');
            } else {
                // Crear el registro en el destino copiando todo el lote
                $destinoData = [
                    'idprod' => $lote->idprod,
                    'descripcion' => $lote->descripcion,
                    'marca' => $lote->marca,
                    'categoria' => $lote->categoria,
                    'unidad' => $lote->unidad,
                    'proveedor' => $lote->proveedor,
                    'imagenes' => $lote->imagenes,
                    'precioventa' => $lote->precioventa,
                    'preciolocal' => $lote->preciolocal,
                    'cantidad' => $tomar,
                    'comision' => $lote->comision,
                    'deposito' => $depDestino,
                    'fecha_ingreso' => date('Y-m-d H:i:s')
                ];
                $this->db->insert('inventarios', $destinoData);
            }

            $restante -= $tomar;
        }

        // 1. Crear registro de transferencia
        $userId = $this->input->get_request_header('X-User-Id', TRUE);
        $transferencia_data = [
            'almacen_origen_id' => $origen_base->deposito,
            'almacen_destino_id' => $depDestino,
            'usuario_id' => intval($userId) ? intval($userId) : 1,
            'fecha' => date('Y-m-d H:i:s'),
            'estado' => 'Completado',
            'observaciones' => 'TRANSFERENCIA INDIVIDUAL'
        ];
        $this->db->insert('transferencias', $transferencia_data);
        $transferencia_id = $this->db->insert_id();

        // Obtener producto master por idprod
        $prodMaster = $this->db->where('idprod', $origen_base->idprod)->get('productos')->row();
        $prod_id = $prodMaster ? $prodMaster->id : 0;

        // 2. Crear detalle
        $this->db->insert('transferencia_detalles', [
            'transferencia_id' => $transferencia_id,
            'producto_id' => $prod_id,
            'codigo_producto' => $origen_base->idprod,
            'cantidad' => $cantidadATransferir
        ]);

        // 3. Actualizar inventario_stock
        if ($prod_id) {
            // Restar origen
            $this->db->where('producto_id', $prod_id)->where('almacen_id', $origen_base->deposito);
            $stockOrigen = $this->db->get('inventario_stock')->row();
            if ($stockOrigen) {
                $this->db->set('stock', 'stock - ' . $cantidadATransferir, FALSE)->where('id', $stockOrigen->id)->update('inventario_stock');
            } else {
                $this->db->insert('inventario_stock', ['producto_id' => $prod_id, 'almacen_id' => $origen_base->deposito, 'stock' => -$cantidadATransferir]);
            }

            // Sumar destino
            $this->db->where('producto_id', $prod_id)->where('almacen_id', $depDestino);
            $stockDestino = $this->db->get('inventario_stock')->row();
            if ($stockDestino) {
                $this->db->set('stock', 'stock + ' . $cantidadATransferir, FALSE)->where('id', $stockDestino->id)->update('inventario_stock');
            } else {
                $this->db->insert('inventario_stock', ['producto_id' => $prod_id, 'almacen_id' => $depDestino, 'stock' => $cantidadATransferir]);
            }
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
            ->set_output(json_encode([
                'message' => 'Transferencia de mercancía realizada con éxito.',
                'transferencia_id' => $transferencia_id
            ]));
    }

    /**
     * Transfiere múltiples productos de un depósito a otro en lote.
     */
    public function transferencia_multiple() {
        $data = json_decode(file_get_contents('php://input'), true);

        $depOrigen = isset($data['deposito_origen']) ? intval($data['deposito_origen']) : null;
        $depDestino = isset($data['deposito_destino']) ? intval($data['deposito_destino']) : null;
        $productos = isset($data['productos']) ? $data['productos'] : [];

        if (!$depOrigen || !$depDestino || empty($productos)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Datos de transferencia incompletos.']));
        }

        if ($depOrigen == $depDestino) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El depósito de destino debe ser diferente al de origen.']));
        }

        $this->db->trans_start();

        $userId = $this->input->get_request_header('X-User-Id', TRUE);
        $transferencia_data = [
            'almacen_origen_id' => $depOrigen,
            'almacen_destino_id' => $depDestino,
            'usuario_id' => intval($userId) ? intval($userId) : 1,
            'fecha' => date('Y-m-d H:i:s'),
            'estado' => 'Completado',
            'observaciones' => 'TRANSFERENCIA MULTIPLE'
        ];
        $this->db->insert('transferencias', $transferencia_data);
        $transferencia_id = $this->db->insert_id();

        foreach ($productos as $prod) {
            $idprod = $prod['idprod'];
            $cantidadATransferir = floatval($prod['cantidad']);

            if ($cantidadATransferir <= 0) continue;

            // Calcular stock total real del producto en la sucursal de origen
            $this->db->select_sum('cantidad');
            $this->db->where('idprod', $idprod);
            $this->db->where('deposito', $depOrigen);
            $total_stock = $this->db->get('inventarios')->row()->cantidad ?? 0;

            if ($total_stock < $cantidadATransferir) {
                // Rollback automatically happens if trans fails, but we can manually fail it here
                $this->db->trans_rollback();
                return $this->output
                    ->set_content_type('application/json')
                    ->set_status_header(400)
                    ->set_output(json_encode([
                        'error' => "Stock insuficiente para el producto $idprod. Disponible: $total_stock"
                    ]));
            }

            // Obtener todos los lotes con stock mayor a cero
            $this->db->where('idprod', $idprod);
            $this->db->where('deposito', $depOrigen);
            $this->db->where('cantidad >', 0);
            $this->db->order_by('id', 'ASC'); // FIFO
            $lotes = $this->db->get('inventarios')->result();

            $restante = $cantidadATransferir;

            foreach ($lotes as $lote) {
                if ($restante <= 0) break;

                $tomar = min($restante, $lote->cantidad);
                
                // 1. Descontar del origen
                $this->db->set('cantidad', 'cantidad - ' . $tomar, FALSE);
                $this->db->where('id', $lote->id);
                $this->db->update('inventarios');

                // 2. Verificar si el producto ya existe en el depósito de destino con este mismo precio
                $this->db->where('idprod', $lote->idprod);
                $this->db->where('deposito', $depDestino);
                $this->db->where('precioventa', $lote->precioventa);
                $destino = $this->db->get('inventarios')->row();

                if ($destino) {
                    // Sumar cantidad al destino existente
                    $this->db->set('cantidad', 'cantidad + ' . $tomar, FALSE);
                    $this->db->where('id', $destino->id);
                    $this->db->update('inventarios');
                } else {
                    // Crear el registro en el destino copiando todo el lote
                    $destinoData = [
                        'idprod' => $lote->idprod,
                        'descripcion' => $lote->descripcion,
                        'marca' => $lote->marca,
                        'categoria' => $lote->categoria,
                        'unidad' => $lote->unidad,
                        'proveedor' => $lote->proveedor,
                        'imagenes' => $lote->imagenes,
                        'precioventa' => $lote->precioventa,
                        'preciolocal' => $lote->preciolocal,
                        'cantidad' => $tomar,
                        'comision' => $lote->comision,
                        'deposito' => $depDestino,
                        'fecha_ingreso' => date('Y-m-d H:i:s')
                    ];
                    $this->db->insert('inventarios', $destinoData);
                }

                $restante -= $tomar;
            }

            // Obtener producto master por idprod
            $prodMaster = $this->db->where('idprod', $idprod)->get('productos')->row();
            $prod_id = $prodMaster ? $prodMaster->id : 0;

            // Registrar detalle
            $this->db->insert('transferencia_detalles', [
                'transferencia_id' => $transferencia_id,
                'producto_id' => $prod_id,
                'codigo_producto' => $idprod,
                'cantidad' => $cantidadATransferir
            ]);

            // Actualizar inventario_stock
            if ($prod_id) {
                // Restar origen
                $this->db->where('producto_id', $prod_id)->where('almacen_id', $depOrigen);
                $stockOrigen = $this->db->get('inventario_stock')->row();
                if ($stockOrigen) {
                    $this->db->set('stock', 'stock - ' . $cantidadATransferir, FALSE)->where('id', $stockOrigen->id)->update('inventario_stock');
                } else {
                    $this->db->insert('inventario_stock', ['producto_id' => $prod_id, 'almacen_id' => $depOrigen, 'stock' => -$cantidadATransferir]);
                }

                // Sumar destino
                $this->db->where('producto_id', $prod_id)->where('almacen_id', $depDestino);
                $stockDestino = $this->db->get('inventario_stock')->row();
                if ($stockDestino) {
                    $this->db->set('stock', 'stock + ' . $cantidadATransferir, FALSE)->where('id', $stockDestino->id)->update('inventario_stock');
                } else {
                    $this->db->insert('inventario_stock', ['producto_id' => $prod_id, 'almacen_id' => $depDestino, 'stock' => $cantidadATransferir]);
                }
            }
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(500)
                ->set_output(json_encode(['error' => 'Ocurrió un error al procesar la transferencia múltiple.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'message' => 'Transferencia múltiple realizada con éxito.',
                'transferencia_id' => $transferencia_id
            ]));
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
     * Agrega una nueva subcategoría a la base de datos
     */
    public function agregar_subcategoria() {
        $data = json_decode(file_get_contents('php://input'), true);
        $nombre = isset($data['nombre']) ? ucwords(strtolower(trim($data['nombre']))) : '';

        if (empty($nombre)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'El nombre de la subcategoría no puede estar vacío.']));
        }

        $this->db->where('nombre', $nombre);
        $existing = $this->db->get('subcategoria')->row();
        
        if ($existing) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode([
                    'error' => 'Ese nombre de subcategoría ya se encuentra registrado.'
                ]));
        }

        $this->db->insert('subcategoria', ['nombre' => $nombre, 'estado' => 'Activo']);
        $insert_id = $this->db->insert_id();

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode([
                'idsubcategoria' => $insert_id, 
                'nombre' => $nombre,
                'message' => 'Subcategoría creada correctamente.'
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
    public function carga_masiva_stock_inicial() {
        $this->check_permission('Inventario', 'crear');
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data || !is_array($data)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(400)
                ->set_output(json_encode(['error' => 'Datos inválidos o no proporcionados.']));
        }

        $this->db->trans_start();

        foreach ($data as $row) {
            $idprod = isset($row['IdProd']) ? trim($row['IdProd']) : '';
            if (empty($idprod)) continue;

            $descripcion = isset($row['Descripcion']) ? trim($row['Descripcion']) : '';
            $idmarca = isset($row['idmarca']) ? intval($row['idmarca']) : null;
            $marca = isset($row['Marca']) ? trim($row['Marca']) : '';
            $cantidad = isset($row['Cantidad']) ? floatval($row['Cantidad']) : (isset($row['CANTIDAD']) ? floatval($row['CANTIDAD']) : 0);
            $categoria = isset($row['CATEGORIA']) ? trim($row['CATEGORIA']) : (isset($row['Categoria']) ? trim($row['Categoria']) : '');
            $idcategoria = isset($row['IDCATEGORIA']) ? intval($row['IDCATEGORIA']) : (isset($row['idcategoria']) ? intval($row['idcategoria']) : null);
            $unidad = isset($row['Unidad']) ? trim($row['Unidad']) : 'unid';
            $preciolocal = isset($row['PrecioLocal']) ? floatval($row['PrecioLocal']) : 0;
            $precioventa = isset($row['PrecioVenta']) ? floatval($row['PrecioVenta']) : 0;
            $preciomayor = isset($row['preciomayor']) ? floatval($row['preciomayor']) : 0;
            $comision = isset($row['Comision']) ? floatval($row['Comision']) : 0;
            $deposito = isset($row['Deposito']) ? intval($row['Deposito']) : 1;
            $proveedor = isset($row['provedor']) ? trim($row['provedor']) : '';
            $imagenes = isset($row['imágenes']) ? trim($row['imágenes']) : '';

            // 1. Verificar/Crear en productos
            $this->db->where('idprod', $idprod);
            $producto = $this->db->get('productos')->row();
            
            if (!$producto) {
                $prodData = [
                    'idprod' => $idprod,
                    'descripcion' => $descripcion,
                    'idmarca' => $idmarca,
                    'marca' => $marca,
                    'idcategoria' => $idcategoria,
                    'subcategoria' => $categoria,
                    'subunidad' => $unidad,
                    'preciolocal' => $preciolocal,
                    'precioventa' => $precioventa,
                    'nuevoprecio' => $preciomayor,
                    'comision' => $comision,
                    'deposito' => $deposito,
                    'imagen' => $imagenes,
                    'estado' => 'Activo'
                ];
                $this->db->insert('productos', $prodData);
                $producto_id = $this->db->insert_id();
            } else {
                $producto_id = $producto->id;
            }

            // 2. Insertar o Actualizar en inventarios
            $this->db->where('idprod', $idprod);
            $this->db->where('deposito', $deposito);
            $inventario = $this->db->get('inventarios')->row();

            if ($inventario) {
                // Actualizar cantidad sumando
                $this->db->set('cantidad', 'cantidad + ' . $cantidad, FALSE);
                $this->db->set('precioventa', $precioventa);
                $this->db->set('preciolocal', $preciolocal);
                $this->db->where('id', $inventario->id);
                $this->db->update('inventarios');
                $lote_id = $inventario->id;
            } else {
                // Crear nuevo
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
                $this->db->insert('inventarios', $invData);
                $lote_id = $this->db->insert_id();
            }

            // 3. Actualizar inventario_stock
            $this->db->where('producto_id', $producto_id);
            $this->db->where('almacen_id', $deposito);
            $inv_stock = $this->db->get('inventario_stock')->row();
            
            if ($inv_stock) {
                $this->db->set('stock', 'stock + ' . $cantidad, FALSE);
                $this->db->where('producto_id', $producto_id);
                $this->db->where('almacen_id', $deposito);
                $this->db->update('inventario_stock');
            } else {
                $this->db->insert('inventario_stock', [
                    'producto_id' => $producto_id,
                    'almacen_id' => $deposito,
                    'stock' => $cantidad
                ]);
            }

            // 4. Registrar en kardex
            $this->db->insert('kardex', [
                'producto_id' => $producto_id,
                'almacen_id' => $deposito,
                'lote_id' => $lote_id,
                'cantidad' => $cantidad,
                'concepto' => 'STOCK INICIAL',
                'tipo_movimiento' => 'INGRESO'
            ]);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(500)
                ->set_output(json_encode(['error' => 'Error al procesar la carga masiva.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(['message' => 'Carga masiva procesada con éxito.']));
    }

    public function listar_transferencias_pendientes() {
        $sucursal_activa = $this->input->get_request_header('X-Active-Branch', TRUE);
        if (!$sucursal_activa) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'Sucursal activa requerida']));
        }

        $this->db->select('h.*, d_origen.nombre as sucursal_origen');
        $this->db->from('hist_traspasos h');
        $this->db->join('depositos d_origen', 'h.deposito = d_origen.id', 'left');
        $this->db->where('h.depdestino', $sucursal_activa);
        $this->db->where('h.estado', 'Pendiente');
        $this->db->order_by('h.fecha', 'DESC');
        $pendientes = $this->db->get()->result();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($pendientes));
    }

    public function confirmar_recepcion() {
        $data = json_decode(file_get_contents('php://input'), true);
        $id_traspaso = isset($data['id_traspaso']) ? intval($data['id_traspaso']) : null;
        $usuario_id = $this->input->get_request_header('X-User-Id', TRUE) ?: 1;

        if (!$id_traspaso) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'ID de traspaso requerido']));
        }

        $traspaso = $this->db->get_where('hist_traspasos', ['id' => $id_traspaso])->row();
        if (!$traspaso || $traspaso->estado !== 'Pendiente') {
            return $this->output->set_status_header(404)->set_output(json_encode(['error' => 'Transferencia no encontrada o ya procesada']));
        }

        $this->db->trans_start();

        // Extraer los lotes pendientes (que se guardaron en la transferencia)
        $lotesPendientes = json_decode($traspaso->lotes_pendientes, true);
        if (!$lotesPendientes) {
             // Fallback si por alguna razón no hay lotes serializados, lo intentamos procesar con la información básica
             // (Para compatibilidad con el sistema antiguo si llega a fallar la serialización)
             $lotesPendientes = [];
        }

        foreach ($lotesPendientes as $loteDestino) {
            // Verificar si el lote ya existe en el destino
            $loteExistente = $this->db->get_where('inventarios', [
                'idprod' => $loteDestino['idprod'],
                'deposito' => $loteDestino['deposito'],
                'preciolocal' => $loteDestino['preciolocal'],
                'precioventa' => $loteDestino['precioventa']
            ])->row();

            if ($loteExistente) {
                $this->db->set('cantidad', 'cantidad + ' . $loteDestino['cantidad'], FALSE)
                         ->where('id', $loteExistente->id)
                         ->update('inventarios');
            } else {
                $this->db->insert('inventarios', $loteDestino);
            }

            // Actualizar stock real total
            $stockDestino = $this->db->get_where('inventario_stock', [
                'producto_id' => $loteDestino['producto_id'], 
                'almacen_id' => $loteDestino['deposito']
            ])->row();

            if ($stockDestino) {
                $this->db->set('stock', 'stock + ' . $loteDestino['cantidad'], FALSE)
                         ->where('id', $stockDestino->id)
                         ->update('inventario_stock');
            } else {
                $this->db->insert('inventario_stock', [
                    'producto_id' => $loteDestino['producto_id'], 
                    'almacen_id' => $loteDestino['deposito'], 
                    'stock' => $loteDestino['cantidad']
                ]);
            }

            // Insertar kardex de ENTRADA
            $this->db->insert('kardex', [
                'producto_id' => $loteDestino['producto_id'],
                'almacen_id' => $loteDestino['deposito'],
                'tipo_movimiento' => 'ENTRADA',
                'cantidad' => $loteDestino['cantidad'],
                'motivo' => 'Recepción de transferencia ' . $id_traspaso,
                'usuario_id' => $usuario_id,
                'referencia_id' => $id_traspaso,
                'tipo_referencia' => 'recepcion_transferencia',
                'fecha_movimiento' => date('Y-m-d H:i:s')
            ]);
        }

        // Actualizar el estado en hist_traspasos
        $this->db->where('id', $id_traspaso)->update('hist_traspasos', [
            'estado' => 'Completado',
            'fecha_recepcion' => date('Y-m-d H:i:s'),
            'usuario_recepcion' => $usuario_id
        ]);

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output->set_status_header(500)->set_output(json_encode(['error' => 'Error al procesar la recepción']));
        }

        return $this->output->set_output(json_encode(['message' => 'Recepción confirmada con éxito']));
    }

    /**
     * Genera la información estructurada para reportes de inventario (Excel / PDF).
     */
    public function reporte() {
        $this->check_permission('Inventario', 'ver');
        $search = $this->input->get('q');
        $depId = $this->input->get('deposito');
        $stockFilter = $this->input->get('stock_status'); // 'all', 'disponible', 'agotado', 'bajo'

        // Obtener depósitos
        $depositos = $this->db->order_by('id', 'ASC')->get('depositos')->result();

        if (empty($depId) || $depId === 'all') {
            // Caso: Todas las sucursales (pivoteado por sucursal)
            $this->db->select('
                p.idprod,
                p.descripcion,
                COALESCE(c.descripcion, p.categoria) AS categoria,
                COALESCE(m.nombre, p.marca) AS marca,
                COALESCE(prov.nombre, p.proveedor, "No especificado") AS proveedor,
                p.precioventa,
                p.preciolocal
            ', FALSE);
            $this->db->from('productos p');
            $this->db->join('categoria_producto c', 'p.idcategoria = c.idcategoria', 'left');
            $this->db->join('marcas m', 'p.idmarca = m.id', 'left');
            $this->db->join('proveedores prov', 'p.proveedor = prov.id', 'left');

            if (!empty($search)) {
                $search_escaped = $this->db->escape_like_str(trim($search));
                $this->db->group_start();
                $this->db->like('p.descripcion', $search_escaped);
                $this->db->or_like('p.idprod', $search_escaped);
                $this->db->or_like('m.nombre', $search_escaped);
                $this->db->or_like('c.descripcion', $search_escaped);
                $this->db->group_end();
            }

            $this->db->order_by('p.descripcion', 'ASC');
            $productos = $this->db->get()->result();

            // Obtener stock agrupado por idprod y deposito desde inventarios
            $stocks_query = $this->db->select('idprod, deposito, SUM(cantidad) as total_deposito')
                ->group_by('idprod, deposito')
                ->get('inventarios')->result();

            $stocks_map = [];
            foreach ($stocks_query as $s) {
                if (!isset($stocks_map[$s->idprod])) {
                    $stocks_map[$s->idprod] = [];
                }
                $stocks_map[$s->idprod][$s->deposito] = floatval($s->total_deposito);
            }

            $reporte_items = [];
            foreach ($productos as $p) {
                $branch_stocks = [];
                $total_prod_stock = 0;

                foreach ($depositos as $d) {
                    $qty = isset($stocks_map[$p->idprod][$d->id]) ? $stocks_map[$p->idprod][$d->id] : 0;
                    $branch_stocks[$d->id] = $qty;
                    $total_prod_stock += $qty;
                }

                // Filtrar según stockFilter si corresponde
                if ($stockFilter === 'disponible' && $total_prod_stock <= 0) continue;
                if ($stockFilter === 'agotado' && $total_prod_stock > 0) continue;
                if ($stockFilter === 'bajo' && ($total_prod_stock <= 0 || $total_prod_stock > 5)) continue;

                $reporte_items[] = [
                    'idprod' => $p->idprod,
                    'descripcion' => $p->descripcion,
                    'categoria' => $p->categoria ? $p->categoria : '-',
                    'marca' => $p->marca ? $p->marca : '-',
                    'proveedor' => $p->proveedor ? $p->proveedor : 'No especificado',
                    'precioventa' => floatval($p->precioventa),
                    'preciolocal' => floatval($p->preciolocal),
                    'sucursales' => $branch_stocks,
                    'stock_total' => $total_prod_stock
                ];
            }

            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode([
                    'modo' => 'todas',
                    'depositos' => $depositos,
                    'items' => $reporte_items
                ]));
        } else {
            // Caso: Una sucursal específica
            $dep_row = $this->db->where('id', intval($depId))->get('depositos')->row();
            $dep_nombre = $dep_row ? $dep_row->nombre : 'Sucursal ' . $depId;

            $this->db->select('
                p.idprod,
                p.descripcion,
                COALESCE(c.descripcion, p.categoria) AS categoria,
                COALESCE(m.nombre, p.marca) AS marca,
                COALESCE(prov.nombre, p.proveedor, "No especificado") AS proveedor,
                COALESCE(SUM(i.cantidad), 0) AS cantidad,
                p.precioventa,
                p.preciolocal
            ', FALSE);
            $this->db->from('productos p');
            $this->db->join('categoria_producto c', 'p.idcategoria = c.idcategoria', 'left');
            $this->db->join('marcas m', 'p.idmarca = m.id', 'left');
            $this->db->join('proveedores prov', 'p.proveedor = prov.id', 'left');
            $this->db->join('inventarios i', 'p.idprod = i.idprod AND i.deposito = ' . intval($depId), 'left', FALSE);

            if (!empty($search)) {
                $search_escaped = $this->db->escape_like_str(trim($search));
                $this->db->group_start();
                $this->db->like('p.descripcion', $search_escaped);
                $this->db->or_like('p.idprod', $search_escaped);
                $this->db->group_end();
            }

            $this->db->group_by('p.idprod, p.descripcion, c.descripcion, m.nombre, prov.nombre, p.proveedor, p.precioventa, p.preciolocal');

            if ($stockFilter === 'disponible') {
                $this->db->having('COALESCE(SUM(i.cantidad), 0) >', 0);
            } elseif ($stockFilter === 'agotado') {
                $this->db->having('COALESCE(SUM(i.cantidad), 0) <=', 0);
            } elseif ($stockFilter === 'bajo') {
                $this->db->having('COALESCE(SUM(i.cantidad), 0) >', 0);
                $this->db->having('COALESCE(SUM(i.cantidad), 0) <=', 5);
            }

            $this->db->order_by('p.descripcion', 'ASC');
            $productos = $this->db->get()->result();

            $reporte_items = [];
            foreach ($productos as $p) {
                $qty = floatval($p->cantidad);
                $pv = floatval($p->precioventa);
                $reporte_items[] = [
                    'idprod' => $p->idprod,
                    'descripcion' => $p->descripcion,
                    'categoria' => $p->categoria ? $p->categoria : '-',
                    'marca' => $p->marca ? $p->marca : '-',
                    'proveedor' => $p->proveedor ? $p->proveedor : 'No especificado',
                    'deposito_nombre' => $dep_nombre,
                    'cantidad' => $qty,
                    'precioventa' => $pv,
                    'preciolocal' => floatval($p->preciolocal),
                    'total_valor' => $qty * $pv
                ];
            }

            return $this->output
                ->set_content_type('application/json')
                ->set_status_header(200)
                ->set_output(json_encode([
                    'modo' => 'unica',
                    'deposito_nombre' => $dep_nombre,
                    'items' => $reporte_items
                ]));
        }
    }
}
