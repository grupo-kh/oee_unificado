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

/**
 * Resuelve la lista de secciones a aplicar en un endpoint (selección múltiple).
 * Acepta:
 *   - seccion=VARILLAS,TROQUELADOS  (CSV, preferido)
 *   - seccion[]=VARILLAS&seccion[]=TROQUELADOS  (array)
 *   - seccion=VARILLAS  (un único valor, compat hacia atrás)
 *   - seccion=TODAS | seccion=''  → array vacío = SIN filtro (todas las secciones)
 * Sanitiza contra $allowed (por defecto VARILLAS/TROQUELADOS/OTROS), normaliza a mayúsculas.
 * IMPORTANTE: un array vacío significa "todas" (comportamiento histórico de cada endpoint),
 * NO "ninguna". Por eso los endpoints deben filtrar solo cuando el array NO está vacío.
 */
function parseSecciones(array $allowed = ['VARILLAS','TROQUELADOS','OTROS']): array {
    $list = getListParam('seccion');
    // 'TODAS' (en cualquier posición) equivale a no filtrar.
    if (in_array('TODAS', array_map(fn($s) => strtoupper((string)$s), $list), true)) {
        return [];
    }
    return array_values(array_unique(array_filter(
        array_map(fn($s) => strtoupper((string)$s), $list),
        fn($s) => in_array($s, $allowed, true)
    )));
}

if (!function_exists('filtroFechaHora')) {
    /**
     * Fragmento WHERE + params para filtrar una columna datetime por rango de
     * fechas y (opcional) franja horaria. Soporta franja que cruza medianoche.
     * Sin horas válidas → solo filtro de fecha (equivalente al filtro actual).
     *
     * @param string $col    columna datetime cualificada (p.ej. "hpp.Fecha_ini")
     * @param string $fdesde YYYY-MM-DD
     * @param string $fhasta YYYY-MM-DD
     * @param string $hDesde HH:MM o '' (sin filtro horario)
     * @param string $hHasta HH:MM o ''
     * @return array{0:string,1:array}  [sqlFragment, params]
     */
    function filtroFechaHora(string $col, string $fdesde, string $fhasta, string $hDesde = '', string $hHasta = ''): array
    {
        $horaOk = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hDesde)
               && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hHasta)
               && $hDesde !== $hHasta;
        if (!$horaOk) {
            return ["CAST($col AS DATE) BETWEEN ? AND ?", [$fdesde, $fhasta]];
        }
        $hh = "CONVERT(varchar(5), $col, 108)";
        if ($hDesde < $hHasta) {
            return [
                "(CAST($col AS DATE) BETWEEN ? AND ? AND $hh >= ? AND $hh < ?)",
                [$fdesde, $fhasta, $hDesde, $hHasta],
            ];
        }
        // Cruza medianoche (p.ej. 22:00 → 06:00)
        $fdesdeP1 = date('Y-m-d', strtotime($fdesde . ' +1 day'));
        $fhastaP1 = date('Y-m-d', strtotime($fhasta . ' +1 day'));
        return [
            "((CAST($col AS DATE) BETWEEN ? AND ? AND $hh >= ?)"
            . " OR (CAST($col AS DATE) BETWEEN ? AND ? AND $hh < ?))",
            [$fdesde, $fhasta, $hDesde, $fdesdeP1, $fhastaP1, $hHasta],
        ];
    }
}
