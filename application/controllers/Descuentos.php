<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/MY_Controller.php';

class Descuentos extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, X-User-Id, X-Rol-Id, X-Active-Branch, Authorization');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            die();
        }
    }

    /**
     * Listar promociones de descuento
     */
    public function listar() {
        $this->check_permission('Descuentos', 'ver');

        $this->db->select("p.*, m.nombre as marca_nombre, c.descripcion as categoria_nombre,
            DATE_FORMAT(p.fecha_inicio, '%d/%m/%Y') as fecha_inicio_formatted,
            DATE_FORMAT(p.fecha_fin, '%d/%m/%Y') as fecha_fin_formatted,
            (CASE 
                WHEN p.activo = 0 THEN 'Inactiva'
                WHEN CURDATE() < p.fecha_inicio THEN 'Programada'
                WHEN CURDATE() > p.fecha_fin THEN 'Expirada'
                ELSE 'Vigente'
            END) as estado_vigencia", FALSE);
        $this->db->from('promociones_descuentos p');
        $this->db->join('marcas m', 'p.marca_id = m.id', 'left');
        $this->db->join('categoria_producto c', 'p.categoria_id = c.idcategoria', 'left');
        $this->db->order_by('p.id', 'DESC');

        $query = $this->db->get();
        $res = $query->result_array();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $res]));
    }

    /**
     * Listado de Categorías para los selectores de promociones
     */
    public function listar_categorias() {
        $query = $this->db->select("idcategoria as id, descripcion as nombre")
            ->order_by('descripcion', 'ASC')
            ->get('categoria_producto');
        $res = $query->result_array();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $res]));
    }

    /**
     * Simulación de Alerta de Rentabilidad (Pérdida Financiera)
     * Evalúa los productos afectados según filtro (Marca / Categoría / Todos)
     */
    public function simular_alerta() {
        $this->check_permission('Descuentos', 'ver');
        $data = json_decode(file_get_contents('php://input'), true);

        $tipo_filtro = $data['tipo_filtro'] ?? 'todos';
        $marca_id = !empty($data['marca_id']) ? intval($data['marca_id']) : null;
        $categoria_id = !empty($data['categoria_id']) ? intval($data['categoria_id']) : null;
        $porcentaje = intval($data['porcentaje_descuento'] ?? 0);

        if ($porcentaje <= 0) {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'success', 'riesgos' => []]));
        }

        $filter_marca_name = null;
        $filter_cat_name = null;

        if ($tipo_filtro === 'marca' && $marca_id) {
            $marca_row = $this->db->get_where('marcas', ['id' => $marca_id])->row();
            if ($marca_row) {
                $filter_marca_name = strtolower(trim($marca_row->nombre));
            }
        } else if ($tipo_filtro === 'categoria' && $categoria_id) {
            $cat_row = $this->db->get_where('categoria_producto', ['idcategoria' => $categoria_id])->row();
            if ($cat_row) {
                $filter_cat_name = strtolower(trim($cat_row->descripcion));
            }
        }

        $this->db->select("p.id, p.idprod, p.descripcion, p.marca, p.categoria, p.preciolocal as costo_compra, p.precioventa as precio_venta, p.comision", FALSE);
        $this->db->from('inventarios p');

        if ($filter_marca_name) {
            $this->db->where('LOWER(TRIM(p.marca))', $filter_marca_name);
        } else if ($filter_cat_name) {
            $this->db->where('LOWER(TRIM(p.categoria))', $filter_cat_name);
        } else if ($tipo_filtro === 'comision') {
            $this->db->where('p.comision >', 0);
        }

        $query = $this->db->get();
        $productos = $query->result_array();

        $riesgos = [];
        foreach ($productos as $prod) {
            $costo = floatval($prod['costo_compra'] ?? 0);
            $pv = floatval($prod['precio_venta'] ?? 0);
            $comision = floatval($prod['comision'] ?? 0);

            $monto_descuento = $pv * ($porcentaje / 100.0);
            $pv_descuento = $pv - $monto_descuento;
            $neto_empresa = $pv_descuento - $comision;

            // Si el precio neto tras descuento y comisión es menor o igual al costo de compra -> RIESGO DE PÉRDIDA
            if ($costo > 0 && $neto_empresa <= $costo) {
                $perdida = $costo - $neto_empresa;
                $riesgos[] = [
                    'id' => $prod['id'],
                    'idprod' => $prod['idprod'],
                    'descripcion' => $prod['descripcion'],
                    'marca' => $prod['marca'],
                    'categoria' => $prod['categoria'],
                    'costo_compra' => $costo,
                    'precio_venta' => $pv,
                    'comision' => $comision,
                    'porcentaje_descuento' => $porcentaje,
                    'monto_descuento' => round($monto_descuento, 2),
                    'precio_final' => round($pv_descuento, 2),
                    'neto_empresa' => round($neto_empresa, 2),
                    'perdida_estimada' => round($perdida, 2)
                ];
            }
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'riesgos' => $riesgos, 'total_evaluados' => count($productos)]));
    }

    /**
     * Reporte completo de TODOS los productos actualmente en riesgo de pérdida por promociones activas
     */
    public function listar_productos_en_riesgo() {
        $this->check_permission('Descuentos', 'ver');
        $hoy = date('Y-m-d');

        $promociones = $this->db->query("
            SELECT p.*, m.nombre as marca_nombre, c.descripcion as categoria_nombre 
            FROM promociones_descuentos p 
            LEFT JOIN marcas m ON p.marca_id = m.id 
            LEFT JOIN categoria_producto c ON p.categoria_id = c.idcategoria 
            WHERE p.activo = 1 AND p.fecha_inicio <= '$hoy' AND p.fecha_fin >= '$hoy' 
            ORDER BY p.porcentaje_descuento DESC
        ")->result_array();

        if (empty($promociones)) {
            return $this->output
                ->set_content_type('application/json')
                ->set_output(json_encode(['status' => 'success', 'data' => []]));
        }

        $productos = $this->db->select("p.id, p.idprod, p.descripcion, p.marca, p.categoria, p.preciolocal as costo_compra, p.precioventa as precio_venta, p.comision", FALSE)
            ->from('inventarios p')
            ->get()->result_array();

        $riesgos = [];
        foreach ($productos as $prod) {
            $costo = floatval($prod['costo_compra'] ?? 0);
            $pv = floatval($prod['precio_venta'] ?? 0);
            $comision = floatval($prod['comision'] ?? 0);

            // Encontrar el mayor porcentaje de descuento activo aplicable
            $max_pct = 0;
            $promo_nombre = '';
            foreach ($promociones as $promo) {
                $match = false;
                if ($promo['tipo_filtro'] === 'todos') {
                    $match = true;
                } else if ($promo['tipo_filtro'] === 'comision') {
                    if ($comision > 0) {
                        $match = true;
                    }
                } else if ($promo['tipo_filtro'] === 'marca' && !empty($promo['marca_nombre'])) {
                    if (strtolower(trim($prod['marca'] ?? '')) === strtolower(trim($promo['marca_nombre']))) {
                        $match = true;
                    }
                } else if ($promo['tipo_filtro'] === 'categoria' && !empty($promo['categoria_nombre'])) {
                    if (strtolower(trim($prod['categoria'] ?? '')) === strtolower(trim($promo['categoria_nombre']))) {
                        $match = true;
                    }
                }

                if ($match) {
                    $pct = intval($promo['porcentaje_descuento']);
                    if ($pct > $max_pct) {
                        $max_pct = $pct;
                        $promo_nombre = $promo['nombre'];
                    }
                }
            }

            if ($max_pct > 0 && $costo > 0) {
                $monto_descuento = $pv * ($max_pct / 100.0);
                $pv_descuento = $pv - $monto_descuento;
                $neto_empresa = $pv_descuento - $comision;

                // Criterio del usuario: costo_compra >= (precio_venta - comision - descuento)
                if ($neto_empresa <= $costo) {
                    $perdida = $costo - $neto_empresa;
                    $riesgos[] = [
                        'id' => $prod['id'],
                        'idprod' => $prod['idprod'],
                        'descripcion' => $prod['descripcion'],
                        'marca' => $prod['marca'],
                        'categoria' => $prod['categoria'],
                        'costo_compra' => $costo,
                        'precio_venta' => $pv,
                        'comision' => $comision,
                        'porcentaje_descuento' => $max_pct,
                        'nombre_promocion' => $promo_nombre,
                        'monto_descuento' => round($monto_descuento, 2),
                        'precio_final' => round($pv_descuento, 2),
                        'neto_empresa' => round($neto_empresa, 2),
                        'perdida_estimada' => round($perdida, 2)
                    ];
                }
            }
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $riesgos]));
    }

    /**
     * Guardar o Actualizar una regla de descuento
     */
    public function guardar() {
        $this->check_permission('Descuentos', 'crear');
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['nombre']) || empty($data['fecha_inicio']) || empty($data['fecha_fin'])) {
            return $this->output
                ->set_status_header(400)
                ->set_content_type('application/json')
                ->set_output(json_encode(['error' => 'Faltan campos obligatorios (nombre, fecha de inicio y fin).']));
        }

        $id = !empty($data['id']) ? intval($data['id']) : null;
        $porcentaje = max(1, intval($data['porcentaje_descuento'] ?? 0));

        $save_data = [
            'nombre' => trim($data['nombre']),
            'tipo_filtro' => $data['tipo_filtro'] ?? 'todos',
            'marca_id' => !empty($data['marca_id']) ? intval($data['marca_id']) : null,
            'categoria_id' => !empty($data['categoria_id']) ? intval($data['categoria_id']) : null,
            'porcentaje_descuento' => $porcentaje,
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'activo' => isset($data['activo']) ? intval($data['activo']) : 1
        ];

        if ($id) {
            $this->db->where('id', $id);
            $this->db->update('promociones_descuentos', $save_data);
            $message = 'Regla de descuento actualizada con éxito.';
        } else {
            $this->db->insert('promociones_descuentos', $save_data);
            $message = 'Regla de descuento creada con éxito.';
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'message' => $message]));
    }

    /**
     * Toggle activo / inactivo
     */
    public function toggle_estado() {
        $this->check_permission('Descuentos', 'editar');
        $data = json_decode(file_get_contents('php://input'), true);
        $id = intval($data['id'] ?? 0);

        if (!$id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'ID inválido.']));
        }

        $row = $this->db->get_where('promociones_descuentos', ['id' => $id])->row();
        if (!$row) {
            return $this->output->set_status_header(444)->set_output(json_encode(['error' => 'Promoción no encontrada.']));
        }

        $nuevo_activo = $row->activo == 1 ? 0 : 1;
        $this->db->where('id', $id);
        $this->db->update('promociones_descuentos', ['activo' => $nuevo_activo]);

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'activo' => $nuevo_activo]));
    }

    /**
     * Eliminar una regla de descuento
     */
    public function eliminar() {
        $this->check_permission('Descuentos', 'eliminar');
        $data = json_decode(file_get_contents('php://input'), true);
        $id = intval($data['id'] ?? 0);

        if (!$id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'ID inválido.']));
        }

        $this->db->where('id', $id);
        $this->db->delete('promociones_descuentos');

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'message' => 'Promoción eliminada correctamente.']));
    }

    /**
     * Endpoint público/compartido para obtener las promociones activas actuales
     */
    public function obtener_promociones_activas() {
        $hoy = date('Y-m-d');
        $this->db->select("p.*, m.nombre as marca_nombre, c.descripcion as categoria_nombre", FALSE);
        $this->db->from('promociones_descuentos p');
        $this->db->join('marcas m', 'p.marca_id = m.id', 'left');
        $this->db->join('categoria_producto c', 'p.categoria_id = c.idcategoria', 'left');
        $this->db->where('p.activo', 1);
        $this->db->where('p.fecha_inicio <=', $hoy);
        $this->db->where('p.fecha_fin >=', $hoy);
        $this->db->order_by('p.porcentaje_descuento', 'DESC');

        $query = $this->db->get();
        $promociones = $query->result_array();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['status' => 'success', 'data' => $promociones]));
    }
}
