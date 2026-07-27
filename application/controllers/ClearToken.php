<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class ClearToken extends CI_Controller {
    public function index() {
        $this->load->database();
        $this->db->query("UPDATE bisa_qr_config SET token = NULL, token_expires_at = NULL");
        echo "<h1>¡Token de SIP BISA borrado exitosamente!</h1>";
        echo "<p>La próxima vez que intentes generar un QR, el sistema solicitará un token nuevo usando tus credenciales actuales.</p>";
    }
}
