<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Transferencias extends CI_Controller {

    public function __construct() {
        parent::__construct();
        // Habilitar CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-User-Id, X-Rol-Id');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }
        
        $this->load->database();
    }

    /**
     * Obtiene el listado de transferencias
     */
    public function index() {
        $this->db->select('t.*, o.nombre as origen_nombre, d.nombre as destino_nombre, u.nombre as usuario_nombre');
        $this->db->from('transferencias t');
        $this->db->join('depositos o', 't.almacen_origen_id = o.id', 'left');
        $this->db->join('depositos d', 't.almacen_destino_id = d.id', 'left');
        $this->db->join('vendedores u', 't.usuario_id = u.id', 'left'); // users are in vendedores table mostly? Let's check users vs vendedores.
        // Wait, the system uses `users` or `vendedores`. Other controllers join with `vendedores` as `u`.
        // Let's use `vendedores` for now, or just `users` if it's the auth user. In MY_Controller it checks users.
        // Actually I will join with `users` because authUser.id comes from users.
        // But let's select from users instead.
        
        // Correction: the user table is `users`.
        $this->db->order_by('t.fecha', 'DESC');
        $query = $this->db->get();
        $result = $query->result_array();
        
        // We will try to map users from `users` table since `t.usuario_id` comes from authUser.id which is `users.id`
        // But to be safe if it doesn't join we'll do it manually or via another join.
        // Let's fix the join for users
        $this->db->select('t.*, o.nombre as origen_nombre, d.nombre as destino_nombre, u.name as usuario_nombre');
        $this->db->from('transferencias t');
        $this->db->join('depositos o', 't.almacen_origen_id = o.id', 'left');
        $this->db->join('depositos d', 't.almacen_destino_id = d.id', 'left');
        $this->db->join('users u', 't.usuario_id = u.id', 'left');
        $this->db->order_by('t.fecha', 'DESC');
        $query2 = $this->db->get();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($query2->result_array()));
    }

    /**
     * Obtiene el detalle de una transferencia
     */
    public function detalle($id) {
        $this->db->select('td.*, p.descripcion, p.marca, p.categoria, p.unidad');
        $this->db->from('transferencia_detalles td');
        $this->db->join('productos p', 'td.producto_id = p.id', 'left');
        $this->db->where('td.transferencia_id', $id);
        $query = $this->db->get();

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($query->result_array()));
    }

    /**
     * Guarda una nueva transferencia y procesa el inventario
     */
    public function guardar() {
        $data = json_decode(file_get_contents('php://input'), true);

        $origen_id = isset($data['almacen_origen_id']) ? intval($data['almacen_origen_id']) : null;
        $destino_id = isset($data['almacen_destino_id']) ? intval($data['almacen_destino_id']) : null;
        $usuario_id = isset($data['usuario_id']) ? intval($data['usuario_id']) : null;
        $observaciones = isset($data['observaciones']) ? $data['observaciones'] : '';
        $detalles = isset($data['detalles']) ? $data['detalles'] : [];

        if (!$origen_id || !$destino_id || !$usuario_id || empty($detalles)) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'Datos incompletos para la transferencia.']));
        }

        if ($origen_id === $destino_id) {
            return $this->output->set_status_header(400)->set_output(json_encode(['error' => 'El almacén de origen y destino no pueden ser el mismo.']));
        }

        $this->db->trans_start();

        // 1. Crear registro de transferencia
        $transferencia_data = [
            'almacen_origen_id' => $origen_id,
            'almacen_destino_id' => $destino_id,
            'usuario_id' => $usuario_id,
            'fecha' => date('Y-m-d H:i:s'),
            'estado' => 'Completado',
            'observaciones' => $observaciones
        ];
        $this->db->insert('transferencias', $transferencia_data);
        $transferencia_id = $this->db->insert_id();

        // 2. Procesar detalles
        foreach ($detalles as $item) {
            $codigo_producto = $item['codigo_producto'];
            $cantidad = floatval($item['cantidad']);

            if ($cantidad <= 0) continue;

            // Obtener producto master por idprod
            $prodMaster = $this->db->where('idprod', $codigo_producto)->get('productos')->row();
            if (!$prodMaster) continue;
            
            $prod_id = $prodMaster->id;
            $idprod = $prodMaster->idprod;

            // Guardar detalle
            $this->db->insert('transferencia_detalles', [
                'transferencia_id' => $transferencia_id,
                'producto_id' => $prod_id,
                'codigo_producto' => $idprod,
                'cantidad' => $cantidad
            ]);

            // === A. ACTUALIZAR INVENTARIO_STOCK (NUEVO SISTEMA) ===
            // Restar origen
            $this->db->where('producto_id', $prod_id)->where('almacen_id', $origen_id);
            $stockOrigen = $this->db->get('inventario_stock')->row();
            if ($stockOrigen) {
                $this->db->set('stock', 'stock - ' . $cantidad, FALSE)->where('id', $stockOrigen->id)->update('inventario_stock');
            } else {
                // Genera negativo si no existia
                $this->db->insert('inventario_stock', ['producto_id' => $prod_id, 'almacen_id' => $origen_id, 'stock' => -$cantidad]);
            }

            // Sumar destino
            $this->db->where('producto_id', $prod_id)->where('almacen_id', $destino_id);
            $stockDestino = $this->db->get('inventario_stock')->row();
            if ($stockDestino) {
                $this->db->set('stock', 'stock + ' . $cantidad, FALSE)->where('id', $stockDestino->id)->update('inventario_stock');
            } else {
                $this->db->insert('inventario_stock', ['producto_id' => $prod_id, 'almacen_id' => $destino_id, 'stock' => $cantidad]);
            }

            // === B. ACTUALIZAR INVENTARIOS (SISTEMA ANTIGUO / LOTES) ===
            // Restar origen (FIFO o buscar primer lote disponible)
            $this->db->where('idprod', $idprod)->where('deposito', $origen_id)->where('cantidad >', 0)->order_by('fecha_ingreso', 'ASC');
            $lotesOrigen = $this->db->get('inventarios')->result();
            $cantPendiente = $cantidad;
            $ultimoLote = null;

            foreach ($lotesOrigen as $lote) {
                if ($cantPendiente <= 0) break;
                $descuento = min($lote->cantidad, $cantPendiente);
                $this->db->set('cantidad', 'cantidad - ' . $descuento, FALSE)->where('id', $lote->id)->update('inventarios');
                $cantPendiente -= $descuento;
                $ultimoLote = $lote;
            }

            // Si falta, restar al ultimo lote o al primero que haya
            if ($cantPendiente > 0) {
                if (!$ultimoLote) {
                    $ultimoLote = $this->db->where('idprod', $idprod)->where('deposito', $origen_id)->get('inventarios')->row();
                }
                if ($ultimoLote) {
                    $this->db->set('cantidad', 'cantidad - ' . $cantPendiente, FALSE)->where('id', $ultimoLote->id)->update('inventarios');
                }
            }

            // Sumar destino (Buscar lote existente o crear uno nuevo)
            $this->db->where('idprod', $idprod)->where('deposito', $destino_id);
            $loteDestino = $this->db->get('inventarios')->row();
            
            if ($loteDestino) {
                $this->db->set('cantidad', 'cantidad + ' . $cantidad, FALSE)->where('id', $loteDestino->id)->update('inventarios');
            } else {
                // Crear lote si no existe basandose en el master o un lote viejo
                $base = $ultimoLote ? $ultimoLote : $prodMaster;
                $nuevoLote = [
                    'idprod' => $idprod,
                    'descripcion' => $prodMaster->descripcion,
                    'marca' => $prodMaster->marca,
                    'idmarca' => $prodMaster->idmarca ?? 0,
                    'categoria' => $prodMaster->categoria,
                    'idcategoria' => $prodMaster->idcategoria ?? 0,
                    'unidad' => $prodMaster->unidad,
                    'cantidad' => $cantidad,
                    'cantidad_inicial' => $cantidad,
                    'preciolocal' => isset($base->preciolocal) ? $base->preciolocal : $prodMaster->preciolocal,
                    'precioventa' => isset($base->precioventa) ? $base->precioventa : $prodMaster->precioventa,
                    'preciomayor' => $prodMaster->nuevoprecio ?? $prodMaster->precioventa,
                    'comision' => isset($base->comision) ? $base->comision : ($prodMaster->comision ?? 0),
                    'deposito' => $destino_id,
                    'proveedor' => isset($base->proveedor) ? $base->proveedor : '',
                    'imagenes' => $prodMaster->imagen ?? null,
                    'fecha_ingreso' => date('Y-m-d H:i:s')
                ];
                $this->db->insert('inventarios', $nuevoLote);
            }

            // === C. REGISTRO EN KARDEX ===
            // Egreso origen
            $this->db->insert('kardex', [
                'producto_id' => $prod_id,
                'almacen_id' => $origen_id,
                'lote_id' => $ultimoLote ? $ultimoLote->id : 0,
                'cantidad' => $cantidad,
                'concepto' => 'TRANSFERENCIA SALIDA',
                'tipo_movimiento' => 'EGRESO',
                'referencia_id' => $transferencia_id,
                'fecha_registro' => date('Y-m-d H:i:s')
            ]);
            // Ingreso destino
            $this->db->insert('kardex', [
                'producto_id' => $prod_id,
                'almacen_id' => $destino_id,
                'lote_id' => $loteDestino ? $loteDestino->id : ($this->db->insert_id() ?? 0),
                'cantidad' => $cantidad,
                'concepto' => 'TRANSFERENCIA ENTRADA',
                'tipo_movimiento' => 'INGRESO',
                'referencia_id' => $transferencia_id,
                'fecha_registro' => date('Y-m-d H:i:s')
            ]);
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return $this->output->set_status_header(500)->set_output(json_encode(['error' => 'Error procesando la transferencia en la base de datos.']));
        }

        return $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(['message' => 'Transferencia realizada y stock actualizado con éxito.', 'transferencia_id' => $transferencia_id]));
    }
}
