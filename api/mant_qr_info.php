<?php
// Endpoint retirado. Borra este archivo manualmente.
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode(['ok' => false, 'error' => 'Funcionalidad QR retirada']);
