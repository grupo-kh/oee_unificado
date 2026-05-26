<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function jsonOk($data) {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit;
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function getParam($name, $default = null, $filter = FILTER_DEFAULT) {
    if (!isset($_GET[$name]) || $_GET[$name] === '') return $default;
    $raw = $_GET[$name];
    if (is_array($raw)) return $default; // protege contra ?x[]=foo cuando se espera escalar
    // Elimina caracteres de control (incluidos NUL, CR, LF, DEL); evita
    // ataques tipo header injection si el valor se reutiliza en cabeceras.
    $raw = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$raw) ?? '';
    $value = filter_var($raw, $filter);
    return ($value === false) ? $default : $value;
}

/**
 * Parse a multi-value parameter from $_GET. Accepts either a CSV string or an array.
 * Returns a deduplicated array of trimmed non-empty string values.
 */
function getListParam(string $name): array {
    $raw = $_GET[$name] ?? null;
    if ($raw === null || $raw === '') return [];
    $list = is_array($raw)
        ? $raw
        : array_filter(array_map('trim', explode(',', (string)$raw)));
    return array_values(array_unique(array_filter($list, fn($v) => $v !== '')));
}

/**
 * Resuelve la lista de turnos a aplicar en un endpoint.
 * Acepta:
 *   - turnos=M,T,N  (CSV, preferido)
 *   - turnos[]=M&turnos[]=T  (array)
 *   - turno=M  (compat hacia atrás, único valor)
 * Si no se pasa nada, devuelve $defaultIfEmpty.
 * Sanitiza contra $allowed (por defecto M/T/N/C).
 */
function parseTurnos(array $allowed = ['M','T','N','C'], array $defaultIfEmpty = ['M','T','N']): array {
    $list = getListParam('turnos');
    if (!$list) {
        $single = getParam('turno');
        if ($single) $list = [$single];
    }
    $list = array_values(array_unique(array_filter(
        array_map(fn($t) => strtoupper((string)$t), $list),
        fn($t) => in_array($t, $allowed, true)
    )));
    return $list ?: $defaultIfEmpty;
}
