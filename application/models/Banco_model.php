<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Banco_model extends CI_Model {

    public function __construct() {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Obtener todos los bancos ordenados por nombrebanco
     */
    public function get_bancos() {
        $this->db->select('id, nombrebanco, codigo');
        $this->db->order_by('nombrebanco', 'ASC');
        return $this->db->get('bancos')->result();
    }
}
