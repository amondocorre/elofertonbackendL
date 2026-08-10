<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Controlador de Ventas por Mayor.
 * - Precio por mayor (nuevoprecio), sin comision
 * - Solo clientes tipo Mayorista o Ambos
 * - Soporte credito/contado
 */
class VentasMayorista extends MY_Controller {

    public function __construct() {
        parent::__construct();
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-Active-Branch, X-User-Id, X-Rol-Id');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(); }
        $this->load->database();
    }

    /** Busca productos devolviendo precio por mayor */
    public function search_products() {
        $query = $this->input->get('q');
        $dep   = $this->input->get('dep') ?: '1';
        $precioSelect = 'COALESCE(NULLIF(MAX(p.nuevoprecio), 0), MAX(p.precioventa))';
        $this->db->select('
            MAX(p.id) AS id, p.idprod,
            MAX(p.descripcion) AS descripcion, MAX(p.marca) AS marca,
            MAX(p.categoria) AS categoria, MAX(p.unidad) AS unidad,
            ' . $precioSelect . ' AS precioventa,
            MAX(p.precioventa) AS precioventa_normal,
            COALESCE(SUM(i.cantidad), 0) AS cantidad,
            0 AS comision,
            \''. intval($dep).'\'  AS deposito,
            MAX(NULLIF(p.imagen, \'\')) AS imagen
        ', FALSE);
        $this->db->from('productos p');
        $this->db->join('inventarios i', 'p.idprod = i.idprod AND i.deposito = ' . intval($dep), 'left', FALSE);
        $this->db->where('p.estado', 'Activo');
        if (!empty($query)) {
            $this->db->group_start();
            $this->db->like('p.descripcion', $query, 'both', FALSE);
            $this->db->or_like('p.idprod', $query, 'both', FALSE);
            $this->db->group_end();
        }
        $this->db->group_by('p.idprod');
        $this->db->order_by('COALESCE(SUM(i.cantidad), 0) DESC, MAX(p.descripcion) ASC');
        $this->db->limit(200);
        return $this->output->set_content_type('application/json')->set_output(json_encode($this->db->get()->result()));
    }

    /** Busca vendedores activos */
    public function buscar_vendedores() {
        $q = trim($this->input->get('q') ?? '');
        $this->db->select('id, nombre, email, rol')->from('vendedores')->where('estado', 'activo');
        if ($q !== '') { $this->db->group_start(); $this->db->like('nombre', $q); $this->db->or_like('email', $q); $this->db->group_end(); }
        $this->db->order_by('nombre', 'ASC')->limit(20);
        return $this->output->set_content_type('application/json')->set_output(json_encode($this->db->get()->result()));
    }

    /** Busca clientes tipo Mayorista o Ambos */
    public function buscar_clientes() {
        $q     = trim($this->input->get('q') ?? '');
        $limit = intval($this->input->get('limit') ?? 10);
        $this->db->select('id, nombre, nit, complemento, telefono, direccion, tipo_cliente');
        $this->db->from('clientes')->where('estado', 'activo');
        $this->db->group_start();
        $this->db->where('tipo_cliente', 'Mayorista');
        $this->db->or_where('tipo_cliente', 'Ambos');
        $this->db->group_end();
        if ($q !== '') {
            $this->db->group_start();
            $this->db->like('nombre', $q);
            $this->db->or_like('nit', $q);
            $this->db->or_like('telefono', $q);
            $this->db->group_end();
        }
        $this->db->order_by('nombre', 'ASC')->limit($limit);
        return $this->output->set_content_type('application/json')->set_output(json_encode($this->db->get()->result()));
    }

    /** Registra cliente Mayorista o Ambos */
    public function guardar_cliente() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['nombre']) || trim($data['nombre']) === '') {
            return $this->output->set_content_type('application/json')->set_status_header(400)->set_output(json_encode(['error' => 'El nombre es obligatorio']));
        }
        $tipo = isset($data['tipo_cliente']) ? trim($data['tipo_cliente']) : 'Mayorista';
        if (!in_array($tipo, ['Mayorista', 'Ambos'])) { $tipo = 'Mayorista'; }
        $this->db->insert('clientes', [
            'nombre'       => mb_strtoupper(trim($data['nombre']), 'UTF-8'),
            'nit'          => isset($data['nit']) ? trim($data['nit']) : '',
            'complemento'  => isset($data['complemento']) ? trim($data['complemento']) : '',
            'telefono'     => isset($data['telefono']) ? trim($data['telefono']) : '',
            'direccion'    => isset($data['direccion']) ? trim($data['direccion']) : '',
            'tipo_cliente' => $tipo,
            'estado'       => 'activo'
        ]);
        return $this->output->set_content_type('application/json')->set_status_header(200)->set_output(json_encode(['message' => 'Cliente mayorista registrado', 'id' => $this->db->insert_id()]));
    }

    /** Procesa venta por mayor: FIFO, sin comision, credito/contado */
    public function procesar() {
        $data = json_decode(file_get_contents('php://input'), true);
        log_message('error', 'PROCESAR DATA: ' . json_encode($data));
        if (empty($data['cart']) || empty($data['total'])) {
            log_message('error', '400 ERROR IN PROCESAR'); return $this->output->set_status_header(400)->set_content_type('application/json')->set_output(json_encode(['error' => 'Datos de venta incompletos']));
        }
        $idVendedor = intval($data['vendedor'] ?? 0);
        if ($idVendedor <= 0 || !$this->db->where('id', $idVendedor)->where('estado', 'activo')->get('vendedores')->row()) {
            log_message('error', '400 ERROR IN PROCESAR'); return $this->output->set_status_header(400)->set_content_type('application/json')->set_output(json_encode(['error' => 'Vendedor invalido o inactivo']));
        }
        $depositoId = intval($data['idneg'] ?? 1);
        foreach ($data['cart'] as &$item) {
            $inv = $this->db->where('id', intval($item['id']))->where('deposito', $depositoId)->get('inventarios')->row();
            if (!$inv && !empty($item['idprod'])) {
                $inv = $this->db->where('idprod', $item['idprod'])->where('deposito', $depositoId)->order_by('precioventa','DESC')->get('inventarios')->row();
                if ($inv) { $item['id'] = $inv->id; }
            }
            $prodMaster = null;
            $idprodSearch = $item['idprod'] ?? ($inv ? $inv->idprod : null);
            if ($idprodSearch) { $prodMaster = $this->db->where('idprod', $idprodSearch)->get('productos')->row(); }
            if (!$inv && $prodMaster) {
                $this->db->insert('inventarios', ['idprod'=>$prodMaster->idprod,'descripcion'=>$prodMaster->descripcion,'marca'=>$prodMaster->marca,'idmarca'=>$prodMaster->idmarca,'categoria'=>$prodMaster->categoria,'idcategoria'=>$prodMaster->idcategoria,'unidad'=>$prodMaster->unidad,'cantidad_inicial'=>0,'cantidad'=>0,'preciolocal'=>$prodMaster->preciolocal,'precioventa'=>$prodMaster->precioventa,'preciomayor'=>$prodMaster->nuevoprecio??0,'comision'=>0,'deposito'=>$depositoId,'imagenes'=>$prodMaster->imagen]);
                $inv = $this->db->where('id', $this->db->insert_id())->get('inventarios')->row();
                if ($inv) { $item['id'] = $inv->id; }
            }
            if (!$inv) { log_message('error', '400 ERROR IN PROCESAR'); return $this->output->set_status_header(400)->set_content_type('application/json')->set_output(json_encode(['error'=>'Producto no encontrado en inventario.'])); }
            if (floatval($item['precioventa']) <= 0) { log_message('error', '400 ERROR IN PROCESAR'); return $this->output->set_status_header(400)->set_content_type('application/json')->set_output(json_encode(['error'=>'Precio debe ser > 0 para '.$item['descripcion']])); }
        }
        unset($item);
        $this->db->trans_start();
        
        // Actualizar estado de proforma si la venta viene de una
        if (!empty($data['origen_proforma_id'])) {
            $this->db->where('idproforma', $data['origen_proforma_id']);
            $this->db->update('proformas', [
                'estado' => 'Vendido',
                'fecha_venta' => date('Y-m-d H:i:s')
            ]);
        }
        $idventa = uniqid();
        $comment = trim(($data['comentario'] ?? '') . ' [MAYOR]');
        $this->db->insert('ventas', ['idneg'=>$data['idneg']??'1','idventa'=>$idventa,'total'=>$data['total'],'cliente'=>$data['cliente']??'CLIENTE MAYORISTA','telefono'=>$data['telefono']??'','nit'=>$data['nit']??'','formapago'=>$data['formapago']??'Efectivo','fecha'=>date('Y-m-d H:i:s'),'vendedor'=>intval($data['vendedor']??1),'idusr'=>intval($data['idusr']??1),'con_factura'=>0,'porcentaje_aplicado'=>0,'idcliente'=>$data['idcliente']??0,'pago'=>$data['pago']??$data['total'],'saldo'=>abs(($data['pago']??$data['total'])-$data['total']),'pagomixto'=>$data['pagomixto']??null,'comentario'=>$comment]);
        $nro_venta = $this->db->insert_id();

        if (!empty($data['qr_alias'])) {
            $this->db->where('alias', $data['qr_alias'])->update('bisa_qr_transacciones', [
                'id_venta' => $idventa
            ]);
        }

        foreach ($data['cart'] as $item) {
            $inv = $this->db->where('id', intval($item['id']))->where('deposito', $depositoId)->get('inventarios')->row();
            if (!$inv) { continue; }
            $idprod = $inv->idprod; $dep = intval($inv->deposito); $cantTotal = floatval($item['cantidad']);
            $pm = $this->db->where('idprod',$idprod)->get('productos')->row(); $pmId = $pm ? $pm->id : 0;
            if ($pmId > 0) { $this->db->where('producto_id',$pmId)->where('almacen_id',$dep)->set('stock','stock-'.$cantTotal,false)->update('inventario_stock'); }
            $lotes = $this->db->from('inventarios')->where('idprod',$idprod)->where('deposito',$dep)->where('cantidad >',0)->order_by('fecha_ingreso','ASC')->get()->result();
            $pendiente = $cantTotal;
            foreach ($lotes as $lote) {
                if ($pendiente <= 0) break;
                $desc = min($lote->cantidad, $pendiente);
                $this->db->where('id',$lote->id)->set('cantidad','cantidad-'.$desc,false)->update('inventarios');
                $this->db->insert('detalleventas',['idventa'=>$idventa,'idprod'=>$lote->id,'preciolocal'=>$lote->preciolocal,'precioventa'=>$item['precioventa'],'preciofinal'=>$item['precioventa'],'cuantos'=>$desc,'comision'=>0,'descripcion'=>$item['descripcion']??$lote->descripcion,'vendedor'=>$data['vendedor']??1,'pagocomision'=>null,'observaciones'=>'','cierre'=>null]);
                if ($pmId > 0) { $this->db->insert('kardex',['producto_id'=>$pmId,'almacen_id'=>$dep,'lote_id'=>$lote->id,'cantidad'=>$desc,'concepto'=>'VENTA MAYOR','tipo_movimiento'=>'EGRESO','referencia_id'=>$nro_venta]); }
                $pendiente -= $desc;
            }
            if ($pendiente > 0) {
                $this->db->where('id',$inv->id)->set('cantidad','cantidad-'.$pendiente,false)->update('inventarios');
                $this->db->insert('detalleventas',['idventa'=>$idventa,'idprod'=>$inv->id,'preciolocal'=>$inv->preciolocal,'precioventa'=>$item['precioventa'],'preciofinal'=>$item['precioventa'],'cuantos'=>$pendiente,'comision'=>0,'descripcion'=>$item['descripcion']??$inv->descripcion,'vendedor'=>$data['vendedor']??1,'pagocomision'=>null,'observaciones'=>'Sobregiro mayorista','cierre'=>null]);
                if ($pmId > 0) { $this->db->insert('kardex',['producto_id'=>$pmId,'almacen_id'=>$dep,'lote_id'=>$inv->id,'cantidad'=>$pendiente,'concepto'=>'VENTA MAYOR','tipo_movimiento'=>'EGRESO','referencia_id'=>$nro_venta]); }
            }
        }
        $this->db->trans_complete();
        if ($this->db->trans_status() === FALSE) {
            return $this->output->set_status_header(500)->set_content_type('application/json')->set_output(json_encode(['error'=>'Error al procesar la venta mayorista']));
        }
        return $this->output->set_content_type('application/json')->set_output(json_encode(['message'=>'Venta mayorista procesada exitosamente','idventa'=>$idventa,'nro_venta'=>$nro_venta]));
    }
}
