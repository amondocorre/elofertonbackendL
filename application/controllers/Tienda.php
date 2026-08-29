<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Tienda extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // Habilitar CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-User-Id, X-Rol-Id, X-Active-Branch, X-QR-Env, X-QR-ENV');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
        
        $this->load->database();
    }

    /**
     * Obtiene el listado de productos públicos
     * GET /tienda/productos?sucursal=1&q=clavos&marca=Truper
     */
    public function productos() {
        try {
            $sucursal = $this->input->get('sucursal');
            if (empty($sucursal) || $sucursal === '0') {
                $header_sucursal = $this->input->get_request_header('X-Active-Branch', TRUE);
                if (!empty($header_sucursal)) {
                    $sucursal = $header_sucursal;
                }
            }
            $q = $this->input->get('q');
            $marca = $this->input->get('marca');
            $categoria = $this->input->get('categoria');
            $subcategoria = $this->input->get('subcategoria');
            $vendedor_id = $this->input->get('vendedor_id');

            // Check if the vendedor is valid
            $is_vendedor = false;
            if (!empty($vendedor_id)) {
                $this->db->where('id', $vendedor_id);
                $vendedor = $this->db->get('vendedores')->row();
                if ($vendedor && strtolower(trim($vendedor->estado)) === 'activo') {
                    // Obtener roles múltiples
                    $roles_lower = array();
                    if ($this->db->table_exists('vendedores_roles')) {
                        $rolesQuery = $this->db->get_where('vendedores_roles', ['vendedor_id' => $vendedor->id])->result();
                        foreach ($rolesQuery as $rq) {
                            if (!empty($rq->rol)) {
                                $roles_lower[] = trim(strtolower($rq->rol));
                            }
                        }
                    }
                    if (empty($roles_lower) && !empty($vendedor->rol)) {
                        $roles_lower[] = trim(strtolower($vendedor->rol));
                    }
                    
                    if (in_array('vendedores', $roles_lower) || in_array('vendedor', $roles_lower) || in_array('admin', $roles_lower) || in_array('administrador', $roles_lower) || in_array('administradores', $roles_lower) || in_array('enc. tienda y caja', $roles_lower) || in_array('encargado de tienda', $roles_lower) || in_array('editor', $roles_lower)) {
                        $is_vendedor = true;
                        
                        if ((empty($sucursal) || $sucursal === '0') && !empty($vendedor->ciudad)) {
                            $sucursal = $vendedor->ciudad;
                        }
                    }
                }
            }

            // Obtener marcas únicas (antes de iniciar la consulta principal)
            $marcas = [];
            if ($this->db->table_exists('marcas') && $this->db->table_exists('productos')) {
                $this->db->select('m.nombre as marca, m.logo');
                $this->db->from('productos p');
                $this->db->join('marcas m', 'p.idmarca = m.id', 'inner');
                $this->db->join('inventarios i', 'p.idprod = i.idprod', 'left');
                $this->db->where('p.estado', 'Activo');
                if ($is_vendedor) {
                    $this->db->where('p.comision >', 0);
                }
                $this->db->distinct();
                $this->db->order_by('m.nombre', 'ASC');
                $marcas_result = $this->db->get()->result_array();
                
                foreach ($marcas_result as $row) {
                    if (!empty($row['marca'])) {
                        $marcas[] = [
                            'nombre' => $row['marca'],
                            'logo' => $row['logo'] ?? null
                        ];
                    }
                }
            }

            // Obtener categorías únicas
            $categorias = [];
            if ($this->db->table_exists('categoria_producto') && $this->db->table_exists('productos')) {
                $this->db->select('c.descripcion as categoria');
                $this->db->from('productos p');
                $this->db->join('categoria_producto c', 'p.idcategoria = c.idcategoria', 'inner');
                $this->db->where('p.estado', 'Activo');
                if ($is_vendedor) {
                    $this->db->where('p.comision >', 0);
                }
                $this->db->distinct();
                $this->db->order_by('c.descripcion', 'ASC');
                $cat_res = $this->db->get()->result_array();
                foreach ($cat_res as $cr) {
                    if (!empty($cr['categoria'])) {
                        $categorias[] = $cr['categoria'];
                    }
                }
            }

            // Obtener subcategorías únicas (agrupando para evitar nombres repetidos)
            $subcategorias = [];
            if ($this->db->table_exists('subcategoria') && $this->db->table_exists('productos')) {
                $this->db->select('sc.nombre as subcategoria, MAX(c.descripcion) as categoria');
                $this->db->from('productos p');
                $this->db->join('subcategoria sc', 'p.idsubcategoria = sc.idsubcategoria', 'inner');
                $this->db->join('categoria_producto c', 'p.idcategoria = c.idcategoria', 'left');
                $this->db->where('p.estado', 'Activo');
                if ($is_vendedor) {
                    $this->db->where('p.comision >', 0);
                }
                $this->db->group_by('sc.nombre');
                $this->db->order_by('sc.nombre', 'ASC');
                $subcat_res = $this->db->get()->result_array();
                foreach ($subcat_res as $scr) {
                    if (!empty($scr['subcategoria'])) {
                        $subcategorias[] = [
                            'nombre' => $scr['subcategoria'],
                            'categoria' => $scr['categoria'] ?? ''
                        ];
                    }
                }
            }

            // Campos base: JOIN con tabla 'inventarios' para obtener stock y precio por sucursal
            $select_fields = '
                COALESCE(MAX(inventarios.id), MAX(p.id)) as id,
                p.idprod,
                MAX(p.descripcion) AS descripcion,
                MAX(c.descripcion) AS categoria,
                MAX(sc.nombre) AS subcategoria,
                MAX(m.nombre) AS marca,
                MAX(p.idcategoria) AS idcategoria,
                MAX(p.idsubcategoria) AS idsubcategoria,
                MAX(p.idmarca) AS idmarca,
                COALESCE(MAX(inventarios.unidad), MAX(p.subunidad), "unid") AS unidad,
                MAX(p.precioventa) AS precioventa,
                COALESCE(NULLIF(MAX(inventarios.preciolocal), 0), MAX(p.preciolocal), 0) AS preciolocal,
                MAX(p.nuevoprecio) AS preciomayor,
                COALESCE(MAX(inventarios.deposito), 1) AS sucursal,
                COALESCE(MAX(dep.nombre), "Sucursal 1") AS sucursal_nombre,
                COALESCE(SUM(inventarios.cantidad), 0) AS cantidad,
                NULLIF(MAX(p.imagen), "") AS imagen,
                COALESCE(MAX(p.comision), 0) AS comision
            ';
            
            if ($is_vendedor) {
                if ($this->db->table_exists('vendedor_favoritos')) {
                    $select_fields .= ', MAX((SELECT COUNT(*) FROM vendedor_favoritos WHERE id_producto = COALESCE(inventarios.id, p.id) AND id_vendedor = ' . (int)$vendedor_id . ')) as is_favorito';
                } else {
                    $select_fields .= ', 0 as is_favorito';
                }
            }

            $this->db->select($select_fields, FALSE);
            $this->db->from('productos p');
            if (!empty($sucursal) && $sucursal !== '0') {
                $this->db->join('inventarios', 'p.idprod = inventarios.idprod AND inventarios.deposito = ' . (int)$sucursal, 'left', FALSE);
            } else {
                $this->db->join('inventarios', 'p.idprod = inventarios.idprod AND inventarios.deposito = 1', 'left', FALSE);
            }
            $this->db->join('depositos dep', 'inventarios.deposito = dep.id', 'left', FALSE);
            $this->db->join('categoria_producto c', 'p.idcategoria = c.idcategoria', 'left', FALSE);
            $this->db->join('subcategoria sc', 'p.idsubcategoria = sc.idsubcategoria', 'left', FALSE);
            $this->db->join('marcas m', 'p.idmarca = m.id', 'left', FALSE);
            $this->db->where('p.estado', 'Activo');

            if (!empty($marca) && $marca !== 'Todas') {
                $marcas_arr = is_array($marca) ? $marca : explode(',', $marca);
                $marcas_arr = array_filter(array_map('trim', $marcas_arr));
                if (!empty($marcas_arr) && !in_array('Todas', $marcas_arr)) {
                    $this->db->where_in('m.nombre', $marcas_arr);
                }
            }

            if (!empty($categoria) && $categoria !== 'Todas') {
                $cats_arr = is_array($categoria) ? $categoria : explode(',', $categoria);
                $cats_arr = array_filter(array_map('trim', $cats_arr));
                if (!empty($cats_arr) && !in_array('Todas', $cats_arr)) {
                    $this->db->where_in('c.descripcion', $cats_arr);
                }
            }

            if (!empty($subcategoria) && $subcategoria !== 'Todas') {
                $subcats_arr = is_array($subcategoria) ? $subcategoria : explode(',', $subcategoria);
                $subcats_arr = array_filter(array_map('trim', $subcats_arr));
                if (!empty($subcats_arr) && !in_array('Todas', $subcats_arr)) {
                    $this->db->where_in('sc.nombre', $subcats_arr);
                }
            }

            if (!empty($q)) {
                $search_escaped = $this->db->escape_like_str(trim($q));
                $this->db->group_start();
                $this->db->like('p.descripcion', $search_escaped, 'both', FALSE);
                $this->db->or_like('p.idprod', $search_escaped, 'both', FALSE);
                $this->db->or_like('m.nombre', $search_escaped, 'both', FALSE);
                $this->db->or_like('c.descripcion', $search_escaped, 'both', FALSE);
                $this->db->or_like('sc.nombre', $search_escaped, 'both', FALSE);
                $this->db->group_end();
            }

            $this->db->group_by('p.idprod');

            // Ocultar productos con stock 0 si el usuario está logueado o si se solicita explícitamente (salvo que se envíe incluir_sin_stock=1)
            $user_id_param = $this->input->get('user_id');
            $user_id_header = $this->input->get_request_header('X-User-Id', TRUE);
            $ocultar_sin_stock = $this->input->get('ocultar_sin_stock');
            $incluir_sin_stock = $this->input->get('incluir_sin_stock');

            if ($incluir_sin_stock !== '1' && $incluir_sin_stock !== 'true') {
                if (!empty($vendedor_id) || !empty($user_id_param) || !empty($user_id_header) || $ocultar_sin_stock === '1' || $ocultar_sin_stock === 'true') {
                    $this->db->having('COALESCE(SUM(inventarios.cantidad), 0) > 0');
                }
            }

            $this->db->order_by('CASE WHEN COALESCE(SUM(inventarios.cantidad), 0) > 0 THEN 1 ELSE 2 END', 'ASC', FALSE);
            $this->db->order_by('CASE WHEN COALESCE(MAX(p.comision), 0) > 0 THEN 1 ELSE 2 END', 'ASC', FALSE);
            $this->db->order_by('COALESCE(MAX(p.comision), 0)', 'DESC', FALSE);
            $this->db->order_by('MAX(p.descripcion)', 'ASC', FALSE);

            $this->db->limit(1000); // Límite amplio para ver todos los productos sin crashear
            $productos = $this->db->get()->result();

            // Enriquecer productos con promociones vigentes aplicables (Regla del Mayor Valor y Protección Financiera)
            $promociones = [];
            if ($this->db->table_exists('promociones_descuentos')) {
                $promociones = $this->db->query("
                    SELECT p.*, m.nombre as marca_nombre, c.descripcion as categoria_nombre 
                    FROM promociones_descuentos p 
                    LEFT JOIN marcas m ON p.marca_id = m.id 
                    LEFT JOIN categoria_producto c ON p.categoria_id = c.idcategoria 
                    WHERE p.activo = 1 AND DATE(p.fecha_inicio) <= CURDATE() AND DATE(p.fecha_fin) >= CURDATE() 
                    ORDER BY p.porcentaje_descuento DESC
                ")->result_array();
            }

            // Mapeos auxiliares de marcas y categorías por ID
            $marcas_by_id = [];
            $cats_by_id = [];
            foreach ($promociones as $pr) {
                if (!empty($pr['marca_id']) && empty($marcas_by_id[$pr['marca_id']])) {
                    $mrow = $this->db->get_where('marcas', ['id' => $pr['marca_id']])->row();
                    if ($mrow) $marcas_by_id[$pr['marca_id']] = strtolower(trim($mrow->nombre));
                }
                if (!empty($pr['categoria_id']) && empty($cats_by_id[$pr['categoria_id']])) {
                    $crow = $this->db->get_where('categoria_producto', ['idcategoria' => $pr['categoria_id']])->row();
                    if ($crow) $cats_by_id[$pr['categoria_id']] = strtolower(trim($crow->descripcion));
                }
            }

            foreach ($productos as &$prod) {
                $max_descuento_porcentaje = 0;
                $nombre_promo = '';
                foreach ($promociones as $promo) {
                    $match = false;
                    if ($promo['tipo_filtro'] === 'todos') {
                        $match = true;
                    } else if ($promo['tipo_filtro'] === 'comision') {
                        $min_com = floatval($promo['comision_minima'] ?? 0);
                        if ($min_com > 0) {
                            if (floatval($prod->comision ?? 0) >= $min_com) {
                                $match = true;
                            }
                        } else if (floatval($prod->comision ?? 0) > 0) {
                            $match = true;
                        }
                    } else if ($promo['tipo_filtro'] === 'productos' && !empty($promo['productos_ids'])) {
                        $prod_list = json_decode($promo['productos_ids'], true);
                        if (!is_array($prod_list)) {
                            $prod_list = array_map('trim', explode(',', $promo['productos_ids']));
                        }
                        $prod_list_str = array_map('strval', $prod_list);
                        if (in_array((string)($prod->idprod ?? ''), $prod_list_str) || in_array((string)($prod->id ?? ''), $prod_list_str)) {
                            $match = true;
                        }
                    } else if ($promo['tipo_filtro'] === 'marca') {
                        $prod_marca_str = strtolower(trim($prod->marca ?? ''));
                        if (!empty($promo['marca_id']) && !empty($prod->idmarca) && (int)$promo['marca_id'] === (int)$prod->idmarca) {
                            $match = true;
                        } else if (!empty($promo['marca_nombre']) && $prod_marca_str === strtolower(trim($promo['marca_nombre']))) {
                            $match = true;
                        } else if (!empty($promo['marca_id']) && isset($marcas_by_id[$promo['marca_id']]) && $prod_marca_str === $marcas_by_id[$promo['marca_id']]) {
                            $match = true;
                        }
                    } else if ($promo['tipo_filtro'] === 'categoria') {
                        $prod_cat_str = strtolower(trim($prod->categoria ?? ''));
                        if (!empty($promo['categoria_id']) && !empty($prod->idcategoria) && (int)$promo['categoria_id'] === (int)$prod->idcategoria) {
                            $match = true;
                        } else if (!empty($promo['categoria_nombre']) && $prod_cat_str === strtolower(trim($promo['categoria_nombre']))) {
                            $match = true;
                        } else if (!empty($promo['categoria_id']) && isset($cats_by_id[$promo['categoria_id']]) && $prod_cat_str === $cats_by_id[$promo['categoria_id']]) {
                            $match = true;
                        }
                    }

                    if ($match) {
                        $pct_eval = intval($promo['porcentaje_descuento']);
                        $costo_compra = floatval($prod->preciolocal ?? 0);
                        $pv_orig = floatval($prod->precioventa ?? 0);
                        $comision_val = floatval($prod->comision ?? 0);

                        if ($costo_compra > 0 && $pv_orig > 0) {
                            $monto_desc_eval = $pv_orig * ($pct_eval / 100.0);
                            $pv_desc_eval = $pv_orig - $monto_desc_eval;
                            $neto_eval = $pv_desc_eval - $comision_val;

                            // Excluir de la promoción si genera pérdida financiera
                            if ($neto_eval <= $costo_compra) {
                                $match = false;
                            }
                        }
                    }

                    if ($match) {
                        $pct = intval($promo['porcentaje_descuento']);
                        if ($pct > $max_descuento_porcentaje) {
                            $max_descuento_porcentaje = $pct;
                            $nombre_promo = $promo['nombre'];
                        }
                    }
                }

                if ($max_descuento_porcentaje > 0) {
                    $pv_orig = floatval($prod->precioventa);
                    // Redondear el monto de descuento a entero para evitar decimales en el catálogo WEB
                    $monto_desc = round($pv_orig * ($max_descuento_porcentaje / 100.0));
                    $prod->precio_original = $pv_orig;
                    $prod->precioventa = round($pv_orig - $monto_desc);
                    $prod->descuento_porcentaje = $max_descuento_porcentaje;
                    $prod->descuento_monto = intval($monto_desc);
                    $prod->tiene_promocion = 1;
                    $prod->nombre_promocion = $nombre_promo;
                } else {
                    $prod->precio_original = floatval($prod->precioventa);
                    $prod->descuento_porcentaje = 0;
                    $prod->descuento_monto = 0;
                    $prod->tiene_promocion = 0;
                    $prod->nombre_promocion = '';
                }
            }

            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'data' => $productos,
                    'marcas' => $marcas,
                    'categorias' => $categorias,
                    'subcategorias' => $subcategorias
                ]));
        } catch (\Throwable $e) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]));
        }
    }

    /**
     * Obtiene la configuración pública (bancos, qr, etc)
     * GET /tienda/configuracion
     */
    public function configuracion() {
        try {
            // Obtener sucursales (depósitos) reales de la base de datos
            $sucursales = [];
            if ($this->db->table_exists('depositos')) {
                $this->db->select('id, nombre');
                if ($this->db->field_exists('estado', 'depositos')) {
                    $this->db->where('LOWER(estado)', 'activo');
                }
                if ($this->db->field_exists('tipo_almacen', 'depositos')) {
                    $this->db->where('tipo_almacen', 'Sucursal_Venta');
                }
                $this->db->order_by('id', 'ASC');
                $sucursales = $this->db->get('depositos')->result_array();
            }

            // Configuración de la aplicación
            $config_db = [];
            if ($this->db->table_exists('configapp')) {
                $config_db_row = $this->db->get('configapp')->row_array();
                if ($config_db_row) {
                    $config_db = $config_db_row;
                }
            }

            $config = [
                'nrocuenta' => $config_db['nrocuenta'] ?? '1234567890',
                'banco' => $config_db['banco'] ?? 'Banco Mercantil Santa Cruz',
                'titularcuenta' => $config_db['titularcuenta'] ?? 'Ferretería Ofertón SRL',
                'whatsapp' => '+591' . ($config_db['whatsapp'] ?? '77939732'),
                'metodo_transferencia' => $config_db['metodo_transferencia'] ?? 1,
                'metodo_qrbisa' => $config_db['metodo_qrbisa'] ?? 1,
                'metodo_qrmercantil' => $config_db['metodo_qrmercantil'] ?? 1,
                'sucursales' => $sucursales
            ];
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'success', 'data' => $config]));
        } catch (\Throwable $e) {
            return $this->output
                ->set_status_header(500)
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]));
        }
    }

    /**
     * Obtiene la información completa del perfil del vendedor
     * GET /tienda/obtener_perfil_vendedor?vendedor_id=X
     */
    public function obtener_perfil_vendedor() {
        $vendedor_id = $this->input->get('vendedor_id');
        if (empty($vendedor_id)) {
            return $this->output->set_status_header(400)->set_content_type('application/json')->set_output(json_encode(['error' => 'ID de vendedor requerido']));
        }

        // Auto-migración columna foto en vendedores
        if (!$this->db->field_exists('foto', 'vendedores')) {
            $this->db->query("ALTER TABLE vendedores ADD COLUMN foto VARCHAR(255) NULL AFTER email");
        }

        $this->db->select("v.*, d.nombre as sucursal_nombre");
        $this->db->from("vendedores v");
        $this->db->join("depositos d", "v.ciudad = d.id", "left");
        $this->db->where("v.id", $vendedor_id);
        $vendedor = $this->db->get()->row_array();

        if (!$vendedor) {
            return $this->output->set_status_header(444)->set_content_type('application/json')->set_output(json_encode(['error' => 'Vendedor no encontrado']));
        }

        unset($vendedor['password']);

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $vendedor]));
    }

    /**
     * Subir/Actualizar la foto de perfil del vendedor
     * POST /tienda/actualizar_foto_vendedor
     */
    public function actualizar_foto_vendedor() {
        $vendedor_id = $this->input->post('vendedor_id');
        if (empty($vendedor_id)) {
            return $this->output->set_status_header(400)->set_content_type('application/json')->set_output(json_encode(['error' => 'ID de vendedor requerido']));
        }

        if (!$this->db->field_exists('foto', 'vendedores')) {
            $this->db->query("ALTER TABLE vendedores ADD COLUMN foto VARCHAR(255) NULL AFTER email");
        }

        $upload_path = FCPATH . 'uploads/vendedores/';
        if (!is_dir($upload_path)) {
            mkdir($upload_path, 0777, true);
        }

        if (empty($_FILES['foto']['name'])) {
            return $this->output->set_status_header(400)->set_content_type('application/json')->set_output(json_encode(['error' => 'No se adjuntó ningún archivo de imagen']));
        }

        $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
        $filename = 'vendedor_' . $vendedor_id . '_' . time() . '.' . $ext;
        $target_file = $upload_path . $filename;

        if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_file)) {
            $this->db->where('id', $vendedor_id);
            $this->db->update('vendedores', ['foto' => $filename]);

            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'message' => 'Foto de perfil actualizada exitosamente',
                    'foto' => $filename
                ]));
        } else {
            return $this->output->set_status_header(500)->set_content_type('application/json')->set_output(json_encode(['error' => 'No se pudo guardar la foto en el servidor']));
        }
    }

    /**
     * Buscar clientes para el autocompletado en el checkout web
     */
    public function buscar_cliente() {
        $search = $this->input->get('q');
        if (empty($search) || strlen(trim($search)) < 2) {
            return $this->output->set_content_type('application/json')->set_output(json_encode([]));
        }

        $search_escaped = $this->db->escape_like_str(trim($search));
        $this->db->select('id, nombre, nit, telefono, tipo_cliente, direccion');
        $this->db->group_start();
        $this->db->like('nombre', $search_escaped);
        $this->db->or_like('nit', $search_escaped);
        $this->db->or_like('telefono', $search_escaped);
        $this->db->group_end();
        $this->db->where('estado', 'activo');
        $this->db->limit(5);
        
        $clientes = $this->db->get('clientes')->result_array();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $clientes]));
    }

    /**
     * Procesa el checkout de la web
     * POST /tienda/checkout
     */
    public function checkout() {
        $input_data = json_decode(file_get_contents('php://input'), true);

        if (!$input_data || empty($input_data['detalle'])) {
            echo json_encode(['status' => 'error', 'error' => 'Datos de orden inválidos']);
            return;
        }

        $this->db->trans_start();

        $token = "WEB-" . strtoupper(substr(uniqid(), -6));
        $total = isset($input_data['total']) ? (float)$input_data['total'] : 0;
        $cliente = isset($input_data['cliente']) ? $input_data['cliente'] : 'Cliente Web';
        $celular = isset($input_data['celular']) ? $input_data['celular'] : '';
        $sucursal_id = isset($input_data['sucursal']) ? $input_data['sucursal'] : 1;

        // Registrar la orden como proforma pagada desde la Web para ser recuperada en SISVEN
        $proforma_data = [
            'idproforma' => $token,
            'fecha' => date('Y-m-d H:i:s'),
            'cliente' => $cliente,
            'telefono' => $celular,
            'nit' => '0',
            'complemento' => '',
            'total' => $total,
            'formapago' => 'qr-bisa',
            'idneg' => $sucursal_id,
            'idusr' => 1,
            'vendedor' => 1,
            'idcliente' => 0,
            'pago' => $total,
            'saldo' => 0,
            'comentario' => "Pagado desde la WEB (QR BISA) - Token: " . $token,
            'estado' => 'Pagado',
            'tipo_proforma' => 'normal',
            'con_factura' => 0,
            'porcentaje_aplicado' => 0
        ];

        $this->db->insert('proformas', $proforma_data);
        $insert_id = $this->db->insert_id();

        // Insertar detalle de la proforma
        foreach ($input_data['detalle'] as $item) {
            $det_prof = [
                'idproforma' => $token,
                'idprod' => $item['idprod'] ?? ($item['id'] ?? ''),
                'descripcion' => $item['descripcion'] ?? ($item['producto'] ?? ''),
                'cuantos' => floatval($item['cantidad'] ?? 1),
                'precioventa' => floatval($item['precioventa'] ?? ($item['precio'] ?? 0)),
                'preciolocal' => floatval($item['preciocompra'] ?? ($item['preciolocal'] ?? 0)),
                'preciofinal' => floatval($item['precioventa'] ?? ($item['precio'] ?? 0)),
                'vendedor' => 1,
                'comision' => 0,
                'observaciones' => 'Compra WEB QR BISA'
            ];
            $this->db->insert('detalleproformas', $det_prof);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            echo json_encode(['status' => 'error', 'error' => 'No se pudo generar la orden.']);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => 'Orden registrada correctamente',
                'token' => $token,
                'idventa' => $insert_id
            ]);
        }
    }

    /**
     * Procesa el checkout desde el modal de Vendedor (guarda como Proforma)
     * POST /tienda/checkout_proforma
     */
    public function checkout_proforma() {
        $input_data = json_decode(file_get_contents('php://input'), true);

        if (!$input_data || empty($input_data['detalle'])) {
            echo json_encode(['status' => 'error', 'error' => 'Datos de proforma inválidos']);
            return;
        }

        $phone = isset($input_data['celular']) ? trim($input_data['celular']) : '';
        // Validar que el celular sea de Bolivia (8 dígitos y comience con 5, 6 o 7)
        if (!preg_match('/^[567]\d{7}$/', $phone)) {
            echo json_encode(['status' => 'error', 'error' => 'El número de celular debe tener 8 dígitos y comenzar con 5, 6 o 7.']);
            return;
        }

        $this->db->trans_start();

        $idproforma = uniqid(); 
        $total = isset($input_data['total']) ? (float)$input_data['total'] : 0;
        
        // Construir el comentario de transporte
        $comentario = "Generado desde WEB Vendedor.\n\n";
        $comentario .= "Observaciones: " . ($input_data['observaciones'] ?? 'Ninguna') . "\n";
        $comentario .= "Estado de Pago: " . ($input_data['estado_pago'] ?? 'Pendiente') . "\n";
        
        if (isset($input_data['con_transporte']) && $input_data['con_transporte']) {
            $comentario .= "\n--- TRANSPORTE ---\n";
            $costo = floatval($input_data['costo_envio'] ?? 0);
            $comentario .= "Costo de Envío: " . ($costo > 0 ? 'Bs. ' . number_format($costo, 2) : 'El cliente paga') . "\n";
            $comentario .= "Dirección: " . ($input_data['direccion_envio'] ?? '') . "\n";
            $comentario .= "Ubicación/Mapa: " . ($input_data['mapa_envio'] ?? '') . "\n";
            $comentario .= "Fecha y hora de entrega: " . ($input_data['fecha_entrega'] ?? '') . "\n";
        }

        // Buscar o registrar al cliente en la tabla `clientes`
        $idcliente = isset($input_data['idcliente']) ? intval($input_data['idcliente']) : null;
        $nit = isset($input_data['nit']) ? trim($input_data['nit']) : '0';
        $cliente_nombre = isset($input_data['cliente']) ? trim($input_data['cliente']) : 'Cliente Web';
        $telefono = isset($input_data['celular']) ? trim($input_data['celular']) : '';
        $complemento = isset($input_data['complemento']) ? trim($input_data['complemento']) : '';

        // Si se recibió ID del cliente de frontend, validar si existe
        if ($idcliente) {
            $this->db->where('id', $idcliente);
            $client_check = $this->db->get('clientes')->row();
            if ($client_check) {
                // Actualizar datos del cliente por si variaron
                $update_data = [];
                if ($client_check->nombre !== $cliente_nombre) {
                    $update_data['nombre'] = $cliente_nombre;
                }
                if ($client_check->nit !== $nit) {
                    $update_data['nit'] = $nit;
                }
                if ($client_check->complemento !== $complemento) {
                    $update_data['complemento'] = $complemento;
                }
                if (!empty($telefono) && $client_check->telefono !== $telefono) {
                    $update_data['telefono'] = $telefono;
                }
                if (!empty($update_data)) {
                    $this->db->where('id', $idcliente);
                    $this->db->update('clientes', $update_data);
                }
            } else {
                $idcliente = null;
            }
        }

        // Si no se tiene o no existe el cliente por ID, buscar por NIT (si no es genérico)
        if (!$idcliente && !empty($nit)) {
            $nit_lower = strtolower($nit);
            $generic_placeholders = ['0', 's/n', 'sn', 'n/a', 'na', 'sin nit', 'sin numero', 'sin número', 'sin nro', 'sin nmero', 'sin documento', 'ninguno'];
            $clean_nit = preg_replace('/[^a-z0-9]/', '', $nit_lower);
            $generic_cleans = ['sn', 'na', 'sinnit', 'sinnumero', 'sinnro', 'sindocumento', 'ninguno'];
            $es_generico = in_array($clean_nit, $generic_cleans) || in_array($nit_lower, $generic_placeholders);

            if (!$es_generico) {
                $this->db->where('nit', $nit);
                $client_by_nit = $this->db->get('clientes')->row();
                if ($client_by_nit) {
                    $idcliente = $client_by_nit->id;
                    // Actualizar datos
                    $update_data = [];
                    if ($client_by_nit->nombre !== $cliente_nombre) {
                        $update_data['nombre'] = $cliente_nombre;
                    }
                    if ($client_by_nit->complemento !== $complemento) {
                        $update_data['complemento'] = $complemento;
                    }
                    if (!empty($telefono) && $client_by_nit->telefono !== $telefono) {
                        $update_data['telefono'] = $telefono;
                    }
                    if (!empty($update_data)) {
                        $this->db->where('id', $idcliente);
                        $this->db->update('clientes', $update_data);
                    }
                }
            }
        }

        // Si no se encontró por NIT, buscar por nombre exacto
        if (!$idcliente && !empty($cliente_nombre)) {
            $this->db->where('nombre', $cliente_nombre);
            $client_by_name = $this->db->get('clientes')->row();
            if ($client_by_name) {
                $idcliente = $client_by_name->id;
                // Actualizar datos
                $update_data = [];
                if ($client_by_name->nit !== $nit) {
                    $update_data['nit'] = $nit;
                }
                if ($client_by_name->complemento !== $complemento) {
                    $update_data['complemento'] = $complemento;
                }
                if (!empty($telefono) && $client_by_name->telefono !== $telefono) {
                    $update_data['telefono'] = $telefono;
                }
                if (!empty($update_data)) {
                    $this->db->where('id', $idcliente);
                    $this->db->update('clientes', $update_data);
                }
            }
        }

        // Si es cliente nuevo y no se encontró coincidencia alguna, se crea
        if (!$idcliente) {
            $new_client_data = [
                'nombre' => $cliente_nombre,
                'nit' => $nit,
                'complemento' => $complemento,
                'telefono' => $telefono
            ];
            $this->db->insert('clientes', $new_client_data);
            $idcliente = $this->db->insert_id();
        }

        $estado_pago = $input_data['estado_pago'] ?? 'Pendiente';

        // Verificar si hay webhook que llego antes y actualizo la transaccion
        if (!empty($input_data['qr_alias'])) {
            $tx = $this->db->where('alias', $input_data['qr_alias'])->get('bisa_qr_transacciones')->row();
            if ($tx && $tx->estado === 'PAGADO') {
                $estado_pago = 'Pagado';
            }
        }

        $isPaid = $estado_pago === 'Pagado';
        $paidAmount = $isPaid ? $total : 0;
        $remainingBalance = $isPaid ? 0 : $total;

        $proforma_data = [
            'idneg' => $input_data['sucursal_entrega'] ?? 1,
            'idproforma' => $idproforma,
            'fecha' => date('Y-m-d H:i:s'),
            'cliente' => $cliente_nombre,
            'nit' => $nit,
            'complemento' => $complemento,
            'telefono' => $telefono,
            'total' => $total,
            'formapago' => $input_data['metodo_pago'] ?? 'efectivo',
            'estado' => $estado_pago, 
            'vendedor' => $input_data['vendedor_id'] ?? 1,
            'idusr' => $input_data['vendedor_id'] ?? 1,
            'idcliente' => $idcliente,
            'pago' => $paidAmount,
            'saldo' => $remainingBalance,
            'comentario' => $comentario
        ];

        $this->db->insert('proformas', $proforma_data);

        // Vincular la transaccion de QR BISA si existe
        if (!empty($input_data['qr_alias'])) {
            $this->db->where('alias', $input_data['qr_alias'])->update('bisa_qr_transacciones', [
                'id_proforma' => $idproforma
            ]);
        }

        foreach ($input_data['detalle'] as $item) {
            $itemComision = floatval($item['comision'] ?? 0);
            if ($itemComision <= 0) {
                $prodSearchId = $item['idprod'] ?? null;
                if ($prodSearchId) {
                    $pRow = $this->db->select('comision')->get_where('productos', ['idprod' => $prodSearchId])->row();
                    if ($pRow) {
                        $itemComision = floatval($pRow->comision ?? 0);
                    }
                }
                if ($itemComision <= 0 && !empty($item['id'])) {
                    $invRow = $this->db->select('idprod')->get_where('inventarios', ['id' => $item['id']])->row();
                    if ($invRow) {
                        $pRow = $this->db->select('comision')->get_where('productos', ['idprod' => $invRow->idprod])->row();
                        if ($pRow) {
                            $itemComision = floatval($pRow->comision ?? 0);
                        }
                    }
                }
            }

            $det_data = [
                'idproforma' => $idproforma,
                'idprod' => $item['id'], // El sistema POS requiere el ID numérico autoincremental
                'descripcion' => $item['descripcion'],
                'cuantos' => $item['cantidad'],
                'preciolocal' => $item['precio_base'] ?? $item['precioventa'],
                'precioventa' => $item['precioventa'],
                'preciofinal' => (float)$item['cantidad'] * (float)$item['precioventa'],
                'vendedor' => $input_data['vendedor_id'] ?? 1,
                'comision' => $itemComision
            ];
            $this->db->insert('detalleproformas', $det_data);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            echo json_encode(['status' => 'error', 'error' => 'No se pudo generar la proforma.']);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => 'Proforma registrada correctamente',
                'idproforma' => $idproforma
            ]);
        }
    }

    /**
     * Alterna (agrega o quita) un producto de la lista de favoritos de un vendedor.
     * POST /tienda/toggle_favorito
     */
    public function toggle_favorito() {
        $input_data = json_decode(file_get_contents('php://input'), true);

        $vendedor_id = $input_data['vendedor_id'] ?? null;
        $producto_id = $input_data['producto_id'] ?? null;

        if (!$vendedor_id || !$producto_id) {
            echo json_encode(['status' => 'error', 'error' => 'Parámetros insuficientes']);
            return;
        }

        $this->db->where('id_vendedor', $vendedor_id);
        $this->db->where('id_producto', $producto_id);
        $exists = $this->db->get('vendedor_favoritos')->row();

        if ($exists) {
            $this->db->where('id', $exists->id);
            $this->db->delete('vendedor_favoritos');
            echo json_encode(['status' => 'success', 'is_favorito' => false]);
        } else {
            $this->db->insert('vendedor_favoritos', [
                'id_vendedor' => $vendedor_id,
                'id_producto' => $producto_id,
                'fecha_agregado' => date('Y-m-d H:i:s')
            ]);
            echo json_encode(['status' => 'success', 'is_favorito' => true]);
        }
    }

    /**
     * Obtiene información pública básica de un vendedor (nombre y celular)
     * GET /tienda/vendedor_info?id=1
     */
    public function vendedor_info() {
        $id = $this->input->get('id');
        if (empty($id)) {
            echo json_encode(['status' => 'error', 'error' => 'Falta el ID del vendedor']);
            return;
        }

        $this->db->select('id, nombre, telefono');
        $this->db->where('id', $id);
        $this->db->where('estado', 'activo');
        $vendedor = $this->db->get('vendedores')->row();

        if ($vendedor) {
            echo json_encode(['status' => 'success', 'data' => $vendedor]);
        } else {
            echo json_encode(['status' => 'error', 'error' => 'Vendedor no encontrado']);
        }
    }

    /**
     * Obtiene el listado de proformas generadas por un vendedor específico
     * GET /tienda/listar_proformas_vendedor?vendedor_id=X
     */
    public function listar_proformas_vendedor() {
        // Expirar o restaurar proformas según dias_proforma de la configuración
        $config_app = $this->db->get('configapp')->row();
        $dias_proforma = (isset($config_app->dias_proforma) && intval($config_app->dias_proforma) > 0) ? intval($config_app->dias_proforma) : 1;
        $this->db->query("UPDATE proformas SET estado = 'Vencido' WHERE estado = 'Pendiente' AND DATE(fecha) < DATE_SUB(CURDATE(), INTERVAL $dias_proforma DAY)");
        $this->db->query("UPDATE proformas SET estado = 'Pendiente' WHERE estado = 'Vencido' AND DATE(fecha) >= DATE_SUB(CURDATE(), INTERVAL $dias_proforma DAY)");

        $sellerId = $this->input->get('vendedor_id');

        if (empty($sellerId)) {
            echo json_encode(['status' => 'error', 'error' => 'Falta el ID del vendedor']);
            return;
        }

        // Validar vendedor activo
        $this->db->where('id', $sellerId);
        $this->db->where('estado', 'activo');
        $seller = $this->db->get('vendedores')->row();
        if (!$seller) {
            echo json_encode(['status' => 'error', 'error' => 'Vendedor no válido o inactivo']);
            return;
        }

        $this->db->select('p.id, p.idproforma, p.fecha, p.fecha_venta, p.cliente, p.nit, p.telefono, p.total, p.estado, p.formapago, MAX(d.nombre) AS sucursal_nombre, MAX(qr.alias) AS qr_alias, MAX(qr.qr_base64) AS qr_base64');
        $this->db->from('proformas p');
        $this->db->join('depositos d', 'p.idneg = d.id', 'left');
        $this->db->join('bisa_qr_transacciones qr', 'p.idproforma = qr.id_proforma', 'left');
        $this->db->where('p.vendedor', $sellerId);
        $this->db->group_by('p.id');
        $this->db->order_by('p.fecha', 'DESC');
        $proformas = $this->db->get()->result();

        echo json_encode([
            'status' => 'success',
            'data' => $proformas
        ]);
    }

    /**
     * Obtiene el detalle de una proforma generada por un vendedor
     * GET /tienda/detalle_proforma?vendedor_id=X&idproforma=Y
     */
    public function detalle_proforma() {
        $sellerId = $this->input->get('vendedor_id');
        $proformaId = $this->input->get('idproforma');

        if (empty($sellerId) || empty($proformaId)) {
            echo json_encode(['status' => 'error', 'error' => 'Parámetros insuficientes']);
            return;
        }

        // Validar proforma y que pertenezca al vendedor
        $this->db->select('p.*, d.nombre as sucursal_nombre, v.nombre as vendedor_nombre, v.telefono as vendedor_telefono, v.telefono as vendedor_celular');
        $this->db->from('proformas p');
        $this->db->join('depositos d', 'p.idneg = d.id', 'left');
        $this->db->join('vendedores v', 'p.vendedor = v.id', 'left');
        $this->db->where('p.idproforma', $proformaId);
        $this->db->where('p.vendedor', $sellerId);
        $proforma = $this->db->get()->row();

        if (!$proforma) {
            echo json_encode(['status' => 'error', 'error' => 'Proforma no encontrada o no pertenece al vendedor']);
            return;
        }

        // Obtener detalles de la proforma relacionando detalleproformas.idprod -> inventarios.id -> productos.idprod
        $this->db->select('
            dp.*,
            COALESCE(inv.idprod, p_directo.idprod, dp.idprod) as codigo_producto,
            COALESCE(NULLIF(p.imagen, ""), NULLIF(p_directo.imagen, "")) as imagen
        ', FALSE);
        $this->db->from('detalleproformas dp');
        $this->db->join('inventarios inv', 'dp.idprod = inv.id', 'left');
        $this->db->join('productos p', 'inv.idprod = p.idprod', 'left');
        $this->db->join('productos p_directo', 'dp.idprod = p_directo.idprod', 'left');
        $this->db->where('dp.idproforma', $proformaId);
        $details = $this->db->get()->result();

        // Convertir imagen a data:image en el servidor para evitar bloqueos de CORS en html2canvas/html2pdf
        $uploadsDir = FCPATH . 'uploads/productos/';
        $defaultImgPath = $uploadsDir . 'producto.png';
        $defaultBase64 = null;
        if (file_exists($defaultImgPath)) {
            $defaultBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($defaultImgPath));
        }

        foreach ($details as &$d) {
            $d->imagen_base64 = null;
            if (!empty($d->imagen)) {
                $imgPath = $uploadsDir . $d->imagen;
                if (file_exists($imgPath) && is_file($imgPath)) {
                    $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
                    $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : (($ext === 'webp') ? 'image/webp' : 'image/png');
                    $d->imagen_base64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($imgPath));
                }
            }
            if (empty($d->imagen_base64)) {
                $d->imagen_base64 = $defaultBase64;
            }
        }

        echo json_encode([
            'status' => 'success',
            'proforma' => $proforma,
            'data' => $details
        ]);
    }

    /**
     * Obtiene el stock por sucursal de un producto específico
     * GET /tienda/stock_por_sucursal?idprod=X
     */
    public function stock_por_sucursal() {
        $idprod = $this->input->get('idprod');
        if (empty($idprod)) {
            echo json_encode(['status' => 'error', 'error' => 'Código de producto requerido']);
            return;
        }

        $this->db->select('i.deposito as sucursal_id, d.nombre as sucursal_nombre, COALESCE(SUM(i.cantidad), 0) as cantidad');
        $this->db->from('inventarios i');
        $this->db->join('depositos d', 'i.deposito = d.id', 'left');
        $this->db->where('i.idprod', $idprod);
        $this->db->group_by('i.deposito');
        $stocks = $this->db->get()->result();

        echo json_encode([
            'status' => 'success',
            'data' => $stocks
        ]);
    }

    /**
     * Endpoint para acortar URL con redirección directa sin páginas intermedias
     * POST /tienda/acortar_url
     */
    public function acortar_url() {
        $url = $this->input->post('url');
        if (empty($url)) {
            $rawInput = json_decode(file_get_contents('php://input'), true);
            $url = isset($rawInput['url']) ? $rawInput['url'] : '';
        }

        if (empty($url)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'message' => 'URL requerida']));
        }

        // Intento 1: is.gd (redirección 100% directa al producto sin intermediarios)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://is.gd/create.php?format=json&url=" . urlencode($url));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $json = json_decode($response, true);
            if (!empty($json['shorturl'])) {
                return $this->output
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['status' => 'success', 'shorturl' => $json['shorturl']]));
            }
        }

        // Intento 2: cleanuri.com (redirección directa limpia)
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, "https://cleanuri.com/api/v1/shorten");
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query(['url' => $url]));
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        $response2 = curl_exec($ch2);
        curl_close($ch2);

        if ($response2) {
            $json2 = json_decode($response2, true);
            if (!empty($json2['result_url'])) {
                return $this->output
                    ->set_content_type('application/json')
                    ->set_output(json_encode(['status' => 'success', 'shorturl' => $json2['result_url']]));
            }
        }

        // Intento 3: TinyURL directo
        $ch3 = curl_init();
        curl_setopt($ch3, CURLOPT_URL, "https://tinyurl.com/api-create.php?url=" . urlencode($url));
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
        $response3 = curl_exec($ch3);
        curl_close($ch3);

        if ($response3 && strpos($response3, 'http') === 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'success', 'shorturl' => trim($response3)]));
        }

        // Fallback: URL original funcional
        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'shorturl' => $url]));
    }

    /**
     * Obtiene el listado de productos con comisión ordenados de mayor a menor
     * GET /tienda/top_comisiones?sucursal=1&q=...
     */
    public function top_comisiones() {
        try {
            $sucursal = $this->input->get('sucursal');
            $q = $this->input->get('q');

            $this->db->select('
                COALESCE(MAX(i.id), MAX(p.id)) as id,
                p.idprod,
                MAX(p.descripcion) AS descripcion,
                MAX(m.nombre) AS marca,
                MAX(p.precioventa) AS precioventa,
                COALESCE(MAX(p.comision), 0) AS comision,
                NULLIF(MAX(p.imagen), "") AS imagen,
                NULLIF(MAX(p.foto), "") AS foto,
                COALESCE(SUM(i.cantidad), 0) AS cantidad,
                MAX(d.nombre) AS sucursal_nombre,
                COALESCE(MAX(i.deposito), 1) AS sucursal
            ', FALSE);
            $this->db->from('productos p');
            $this->db->join('marcas m', 'p.idmarca = m.id', 'left');
            $this->db->join('inventarios i', 'p.idprod = i.idprod', 'left');
            $this->db->join('depositos d', 'i.deposito = d.id', 'left');
            $this->db->where('p.estado', 'Activo');
            $this->db->where('COALESCE(p.comision, 0) >', 0);

            if (!empty($sucursal) && $sucursal !== '0') {
                $this->db->where('i.deposito', (int)$sucursal);
            }

            if (!empty($q)) {
                $this->db->group_start();
                $this->db->like('p.descripcion', $q);
                $this->db->or_like('p.idprod', $q);
                $this->db->or_like('m.nombre', $q);
                $this->db->group_end();
            }

            $this->db->group_by('p.idprod');
            $this->db->having('COALESCE(SUM(i.cantidad), 0) >', 0);
            $this->db->order_by('COALESCE(MAX(p.comision), 0)', 'DESC');
            $this->db->order_by('MAX(p.descripcion)', 'ASC');

            $productos = $this->db->get()->result();

            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status' => 'success',
                    'data' => $productos
                ]));
        } catch (Exception $e) {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
        }
    }
}

