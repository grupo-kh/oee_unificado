<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/OfsStore.php';
require_once __DIR__ . '/../lib/SageEmpleadosStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php'; // para el catálogo de operarios

/**
 * API del módulo "Lanzamiento de OFs" (tablet de planta).
 *
 * Endpoints (sin sesión PHP; autenticación mínima por PIN numérico):
 *   GET  ?action=estaciones                  → desplegable inicial
 *   POST ?action=verifica_operario           → comprueba que el PIN existe
 *                                              body: { operario: "1004" }
 *   GET  ?action=planificadas&cod=...&f=YYYY-MM-DD
 *   GET  ?action=detalle&cod=...&f=...&of=...
 *   POST ?action=lanzar                      → marca lanzada en BD
 *                                              body: { of, cod_maquina, operario, notas_operario }
 *
 * Esta API NO requiere Auth (la planta no tiene sesión web). El PIN del
 * operario se valida en cada llamada de escritura.
 */

function readJsonBody(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
    return $_POST ?: [];
}

/**
 * Verifica el número de operario:
 *   1. Primero contra Sage (mantenimiento de empleados). Cualquier empleado
 *      dado de alta entra.
 *   2. Si Sage no lo encuentra, comprueba mant_operarios — así no se rompen
 *      los operarios del módulo de mantenimiento que ya usaban appmovil.
 */
function existeOperario(string $num): bool
{
    if ($num === '') return false;
    if (SageEmpleadosStore::existe($num)) return true;
    if (!defined('MANT_USE_PG') || MANT_USE_PG !== true) return false;
    try {
        $row = Db::pgFetchOne(
            "SELECT 1 FROM mant_operarios WHERE numero = :n AND COALESCE(activo, TRUE) = TRUE",
            [':n' => $num]
        );
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

/** Nombre legible del empleado para la cabecera (Sage > mant_operarios > código). */
function nombreOperario(string $num): string
{
    $nom = SageEmpleadosStore::nombre($num);
    if ($nom !== '' && $nom !== $num) return $nom;
    if (defined('MANT_USE_PG') && MANT_USE_PG === true) {
        try {
            $r = Db::pgFetchOne(
                "SELECT COALESCE(NULLIF(TRIM(apellidos)||' '||TRIM(COALESCE(nombre,'')), ''), nombre, numero) AS label
                   FROM mant_operarios WHERE numero = :n",
                [':n' => $num]
            );
            $lab = trim((string)($r['label'] ?? ''));
            if ($lab !== '') return $lab;
        } catch (Throwable $e) { /* ignore */ }
    }
    return $num;
}

try {
    $action = (string)getParam('action', '');

    switch ($action) {
        case 'estaciones': {
            $fecha = trim((string)getParam('f', date('Y-m-d')));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');
            $estaciones = OfsStore::listarEstaciones($fecha);
            jsonOk([
                'fecha'      => $fecha,
                'estaciones' => $estaciones,
                'total'      => count($estaciones),
            ]);
            break;
        }

        case 'verifica_operario': {
            $body = readJsonBody();
            $num = trim((string)($body['operario'] ?? ''));
            if (!preg_match('/^\d{1,10}$/', $num)) jsonError('PIN inválido (numérico)');
            if (!existeOperario($num)) {
                jsonError('Operario no encontrado o no dado de alta', 404);
            }
            jsonOk([
                'operario' => $num,
                'nombre'   => nombreOperario($num),
                'origen'   => SageEmpleadosStore::candidatoUsado() ?: 'mant_operarios',
            ]);
            break;
        }

        case 'planificadas': {
            $cod   = trim((string)getParam('cod', ''));
            $fecha = trim((string)getParam('f', date('Y-m-d')));
            if ($cod === '') jsonError('Falta cod (máquina)');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
            $ofs = OfsStore::listarPlanificadas($cod, $fecha);
            // Buscamos la descripción de la máquina para mostrarla en la cabecera
            $desc = '';
            foreach (OfsStore::listarEstaciones($fecha) as $e) {
                if ($e['cod'] === $cod) { $desc = $e['desc']; break; }
            }
            jsonOk([
                'cod_maquina'  => $cod,
                'desc_maquina' => $desc,
                'fecha'        => $fecha,
                'total'        => count($ofs),
                'ofs'          => $ofs,
            ]);
            break;
        }

        case 'detalle': {
            $cod   = trim((string)getParam('cod', ''));
            $fecha = trim((string)getParam('f', date('Y-m-d')));
            $of    = trim((string)getParam('of', ''));
            if ($cod === '' || $of === '') jsonError('Faltan parámetros');
            $d = OfsStore::detalleOf($cod, $fecha, $of);
            if (!$d) jsonError('OF no encontrada', 404);
            jsonOk(['of' => $d]);
            break;
        }

        case 'lanzar': {
            $body = readJsonBody();
            $op = trim((string)($body['operario'] ?? ''));
            if (!preg_match('/^\d{1,10}$/', $op) || !existeOperario($op)) {
                jsonError('PIN de operario no válido', 403);
            }
            $ofCodigo  = trim((string)($body['of_codigo']  ?? ''));
            $codMaq    = trim((string)($body['cod_maquina']?? ''));
            if ($ofCodigo === '' || $codMaq === '') jsonError('Faltan datos de la OF');

            $id = OfsStore::registrarLanzamiento([
                'of_codigo'       => $ofCodigo,
                'ref'             => $body['ref']             ?? null,
                'cod_maquina'     => $codMaq,
                'desc_maquina'    => $body['desc_maquina']    ?? null,
                'cantidad'        => $body['cantidad']        ?? null,
                'duracion_horas'  => $body['duracion_horas']  ?? null,
                'ubicacion_galga' => $body['ubicacion_galga'] ?? null,
                'notas'           => $body['notas']           ?? null,
                'notas_operario'  => $body['notas_operario']  ?? null,
                'operario'        => $op,
            ]);
            jsonOk(['id' => $id, 'of_codigo' => $ofCodigo, 'estado' => 'lanzada']);
            break;
        }

        default:
            jsonError('action desconocida', 400);
    }
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
