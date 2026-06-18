<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();

$pageTitle    = 'Mantenimiento · Próximas Revisiones';
$backLink     = 'mantenimiento.php';
$hideFiltros  = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>

<style>
    /* ════════════════════════════════════════════════════════════════
       Modo "limpio" del grid de Próximas Revisiones.

       Este bloque neutraliza el exceso de colores y badges del CSS
       original para que la tabla quede más legible. Estrategia:
         · Borrar fondos coloreados de las filas → sólo un borde lateral
           de 3 px que indica el estado (rojo / ámbar / verde / gris).
         · Convertir las "pills" de periodicidad en texto neutro.
         · Quitar el fondo de la columna "días restantes" — basta el
           color del texto.
         · Hacer el badge de consolidación discreto (sin cadena).
         · Reducir la opacidad de las filas ya marcadas.
       Si más adelante se quiere volver al estilo anterior, basta con
       quitar este <style>.
    ═════════════════════════════════════════════════════════════════ */
    .mant-row,
    .mant-row.mant-row-vencida,
    .mant-row.mant-row-urgente,
    .mant-row.mant-row-en_plazo,
    .mant-row.mant-row-consolidada {
        background: #ffffff !important;
        border-left: 3px solid transparent;
    }
    .mant-row.mant-row-vencida  { border-left-color: #c8102e; }   /* rojo  */
    .mant-row.mant-row-urgente  { border-left-color: #f59e0b; }   /* ámbar */
    .mant-row.mant-row-en_plazo { border-left-color: #8c181a; }   /* verde */

    /* Filas ya hechas: muy sutiles. Mantienen el borde para no perder
       el estado, pero el contenido se difumina. */
    .mant-row.mant-row-hecha td {
        opacity: 0.55;
        text-decoration: line-through;
        text-decoration-color: rgba(0,0,0,0.25);
    }
    .mant-row.mant-row-hecha .mant-action-btn {
        text-decoration: none;
        opacity: 1;
    }

    /* Pills de periodicidad: todas iguales, color tenue. La etiqueta de
       texto basta para distinguir SEMANAL/MENSUAL/etc.; el color
       diferenciado por periodicidad no aportaba info útil. */
    .mant-pill,
    .mant-pill-semanal, .mant-pill-quincenal, .mant-pill-mensual,
    .mant-pill-bimensual, .mant-pill-bimestral, .mant-pill-trimestral,
    .mant-pill-cuatrimestral, .mant-pill-semestral, .mant-pill-anual,
    .mant-pill-trianual, .mant-pill-diaria, .mant-pill-diario {
        background: #eef3f8 !important;
        color: #2d4d7a !important;
        border: 1px solid #d5dfe8 !important;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.2px;
    }

    /* Días restantes: sin fondo, solo color de texto que ya transmite
       la urgencia. */
    .mant-dias,
    .mant-dias.mant-dias-vencida,
    .mant-dias.mant-dias-urgente,
    .mant-dias.mant-dias-en_plazo {
        background: transparent !important;
        padding: 0;
        font-weight: 600;
    }
    .mant-dias.mant-dias-vencida  { color: #c8102e; }
    .mant-dias.mant-dias-urgente  { color: #b45309; }
    .mant-dias.mant-dias-en_plazo { color: #1f8a3c; }

    /* Badge "consolidada" más discreto. */
    .mant-consol-badge {
        background: #eef3f8 !important;
        color: #2d4d7a !important;
        border: 1px solid #d5dfe8 !important;
        font-weight: 600;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 10px;
    }

    /* Botón de "Ver tiempos" (reloj) más pequeño y neutro. */
    .mant-tiempos-btn {
        background: transparent !important;
        border: 1px solid #d5dfe8 !important;
        color: #2d4d7a !important;
        padding: 1px 6px;
        font-size: 13px;
        border-radius: 4px;
    }
    .mant-tiempos-btn:hover {
        background: #eef3f8 !important;
    }

    /* Líneas de la tabla más finas y consistentes. */
    .mant-table tbody tr { border-bottom: 1px solid #f0f3f7; }
    .mant-table tbody tr:hover { background: #f9fbfd !important; }

    /* Sub-tareas: ya colapsadas por <details>, pero al expandir más sobrias. */
    .mant-subtareas summary {
        color: #5b6f86;
        font-size: 11px;
        cursor: pointer;
    }
    .mant-subtareas ul {
        margin: 4px 0 0 14px;
        padding: 0;
        font-size: 11.5px;
        color: #5b6f86;
    }
    .mant-subtareas ul li { margin: 1px 0; }
</style>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Próximas Revisiones <span id="header-scope" class="header-scope"></span></h2>
            <span class="view-card-info" id="info-line">—</span>
        </div>
        <div class="view-card-body">

            <div class="dual-selector-row">
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="fecha-desde" class="machine-selector-label">Desde:</label>
                    <input type="date" id="fecha-desde" class="machine-selector" style="min-width:140px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="fecha-hasta" class="machine-selector-label">Hasta:</label>
                    <input type="date" id="fecha-hasta" class="machine-selector" style="min-width:140px">
                </div>
                <button id="filter-current-week" class="machine-selector-clear" type="button"
                        title="Filtrar por la semana natural en curso"
                        style="background:#5b8cc7;color:#fff">Semana actual</button>
                <button id="filter-next-week" class="machine-selector-clear" type="button"
                        title="Filtrar por la semana natural siguiente a la actual"
                        style="background:#3a6aa3;color:#fff">Prox. semana</button>
                <div class="machine-selector-row" style="flex:1">
                    <label for="machine-selector" class="machine-selector-label">Máquina:</label>
                    <select id="machine-selector" class="machine-selector">
                        <option value="">— Todas —</option>
                    </select>
                </div>
                <div class="machine-selector-row" style="flex:1">
                    <label for="periodicidad-selector" class="machine-selector-label">Periodicidad:</label>
                    <select id="periodicidad-selector" class="machine-selector">
                        <option value="">— Todas —</option>
                    </select>
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label class="machine-selector-label">
                        <input type="checkbox" id="solo-vencidas"> Solo vencidas
                    </label>
                </div>
                <button id="filter-clear" class="machine-selector-clear" type="button" style="display:none">× Quitar filtros</button>
                <button id="prox-tiempos-xlsx" class="machine-selector-clear" type="button"
                        title="Descargar Excel con el tiempo estimado por máquina filtrado al intervalo seleccionado"
                        style="background:#1a4a7a;color:#fff">&#x23F1; Resumen</button>
            </div>

            <div class="oee-fab-global-grid">
                <div class="oee-fab-gauge">
                    <div class="oee-detalle-subtitle">% hechas / en plazo</div>
                    <div id="gauge-mant"></div>
                </div>
                <div class="oee-fab-secciones">
                    <div class="oee-detalle-subtitle">Resumen</div>
                    <div class="mant-stats" id="mant-stats">
                        <div class="mant-stat mant-stat-vencidas"><span class="mant-stat-value" id="stat-vencidas">—</span><span class="mant-stat-label">Vencidas</span></div>
                        <div class="mant-stat mant-stat-urgentes"><span class="mant-stat-value" id="stat-urgentes">—</span><span class="mant-stat-label">Próximas (≤10 días)</span></div>
                        <div class="mant-stat mant-stat-en-plazo"><span class="mant-stat-value" id="stat-en-plazo">—</span><span class="mant-stat-label">Hechas / en plazo</span></div>
                        <div class="mant-stat mant-stat-total"><span class="mant-stat-value" id="stat-total">—</span><span class="mant-stat-label">Total</span></div>
                    </div>
                </div>
            </div>

            <div class="mant-chart-box mant-chart-fullwidth">
                <div class="oee-detalle-subtitle">Top Máquinas con tareas vencidas/próximas <small class="mant-hint">(clic para filtrar)</small></div>
                <div id="chart-top-maquinas-prox"></div>
                <div id="chart-top-maquinas-prox-empty" class="drill-down-empty" style="display:none">Sin máquinas con tareas en este rango</div>
            </div>

            <div class="mant-table-wrap">
                <table class="mant-table mant-table-zebra">
                    <thead>
                        <tr>
                            <th style="width:96px">Próxima</th>
                            <th style="width:120px">Estado</th>
                            <th>Máquina</th>
                            <th style="width:150px">Periodicidades</th>
                            <th>Resumen</th>
                            <th>Detalle</th>
                            <th style="width:96px">Última</th>
                            <th style="width:140px">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="mant-tbody">
                        <tr><td colspan="8" class="mant-empty">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Próximas Revisiones</strong> · una tarea pendiente se considera <strong>vencida</strong> si su fecha de "Próxima revisión" superó el margen de tolerancia (gap por periodicidad), <strong>próxima</strong> si vence en los siguientes 10 días, y <strong>en plazo</strong> en caso contrario. Las tareas ya marcadas como realizadas cuentan como hechas en el resumen aunque su fecha programada haya pasado; las marcadas como no realizadas siguen penalizando.</p>
                <p class="metric-legend-note" id="footer-actualizado">Fichero actualizado: —</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<style>
/* Botón ⏱ "Ver tiempos" junto al nombre de la máquina en la tabla */
.mant-tiempos-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px; height: 26px;
    margin-left: 6px;
    background: #1a4a7a;
    color: #fff;
    border: 0;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    vertical-align: middle;
    box-shadow: 0 1px 2px rgba(26,74,122,0.25);
    transition: background .12s, transform .08s;
}
.mant-tiempos-btn:hover  { background: #2563a3; }
.mant-tiempos-btn:active { transform: scale(0.94); }

/* Modal "Ver tiempos": más ancho que el modal estándar para que las tablas
   con varias columnas (Tarea, Periodicidad, Descripción, Tiempo, Próxima,
   Estado) quepan sin necesidad de scroll horizontal. */
#tiempos-modal .mant-modal-dialog {
    width: min(1100px, 96vw);
    max-width: none;
    max-height: 92vh;
}
#tiempos-modal .mant-modal-body {
    overflow-x: hidden;       /* solo scroll vertical, nunca horizontal */
    overflow-y: auto;
}
/* Tablas internas: layout que reparte mejor el ancho */
#tiempos-modal .mant-table {
    width: 100%;
    table-layout: auto;
    border-collapse: collapse;
}
#tiempos-modal .mant-table th,
#tiempos-modal .mant-table td {
    padding: 6px 8px;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    font-size: 12.5px;
}
/* Asignación de anchos preferentes por tabla.
   1) Desglose por periodicidad (5 columnas) */
#tiempos-modal .tiempos-tbl-per th:nth-child(1),
#tiempos-modal .tiempos-tbl-per td:nth-child(1) { width: 18%; }
#tiempos-modal .tiempos-tbl-per th:nth-child(n+2),
#tiempos-modal .tiempos-tbl-per td:nth-child(n+2) { width: 20.5%; text-align: right; }
/* 2) Tareas activas (6 columnas): la descripción se queda con el espacio
      sobrante; las demás van ajustadas al contenido. */
#tiempos-modal .tiempos-tbl-task th:nth-child(1),  /* Tarea */
#tiempos-modal .tiempos-tbl-task td:nth-child(1) { width: 72px; }
#tiempos-modal .tiempos-tbl-task th:nth-child(2),  /* Periodicidad */
#tiempos-modal .tiempos-tbl-task td:nth-child(2) { width: 110px; }
#tiempos-modal .tiempos-tbl-task th:nth-child(3),  /* Descripción (resto) */
#tiempos-modal .tiempos-tbl-task td:nth-child(3) { width: auto; }
#tiempos-modal .tiempos-tbl-task th:nth-child(4),  /* Estimado */
#tiempos-modal .tiempos-tbl-task td:nth-child(4) { width: 78px; text-align: right; }
#tiempos-modal .tiempos-tbl-task th:nth-child(5),  /* Próxima */
#tiempos-modal .tiempos-tbl-task td:nth-child(5) { width: 92px; text-align: center; }
#tiempos-modal .tiempos-tbl-task th:nth-child(6),  /* Estado */
#tiempos-modal .tiempos-tbl-task td:nth-child(6) { width: 96px; text-align: center; }
</style>

<!-- Modal · tiempos por máquina (plan completo y pendiente ahora) -->
<div id="tiempos-modal" class="mant-modal" style="display:none" aria-hidden="true">
    <div class="mant-modal-backdrop" id="tiempos-modal-backdrop"></div>
    <div class="mant-modal-dialog" role="dialog" aria-modal="true">
        <div class="mant-modal-header">
            <span>⏱ Tiempo estimado · <span id="tiempos-modal-title">—</span></span>
            <button type="button" class="mant-modal-close" id="tiempos-modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="mant-modal-body" id="tiempos-modal-body">
            <div class="mant-empty">Cargando…</div>
        </div>
    </div>
</div>

<div id="mark-modal" class="mant-modal" style="display:none" aria-hidden="true">
    <div class="mant-modal-backdrop" id="mark-modal-backdrop"></div>
    <div class="mant-modal-dialog" role="dialog" aria-modal="true">
        <div class="mant-modal-header">
            <span>Marcar revisión como hecha</span>
            <button type="button" class="mant-modal-close" id="mark-modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="mant-modal-body">
            <div class="mant-modal-summary" id="mark-modal-summary">—</div>

            <!-- Lista de subtareas (solo visible si la fila es consolidada).
                 La accion consolidada registra todas las subtareas a la vez. -->
            <div class="mant-modal-field" id="mark-subtareas-wrap" style="display:none">
                <label>Sub-tareas incluidas</label>
                <div id="mark-subtareas-list" class="mant-subtareas-checklist"></div>
                <small style="color:var(--blue-mid);font-style:italic">
                    Al confirmar, todas estas tareas se registran juntas.
                </small>
            </div>

            <!-- Selector de tipo: realizada / no realizada -->
            <div class="mant-modal-field mant-modal-tipo">
                <label>Estado de la intervención</label>
                <div class="mant-tipo-row">
                    <label class="mant-tipo-opt">
                        <input type="radio" name="mark-tipo" value="completada" checked>
                        <span>✓ Realizada</span>
                    </label>
                    <label class="mant-tipo-opt">
                        <input type="radio" name="mark-tipo" value="no_realizada">
                        <span>✕ No realizada</span>
                    </label>
                </div>
            </div>

            <!-- Motivo (solo si no_realizada): 3 valores fijos -->
            <div class="mant-modal-field" id="mark-motivo-wrap" style="display:none">
                <label for="mark-motivo">Motivo de no realización</label>
                <select id="mark-motivo" class="machine-selector">
                    <option value="">— Selecciona el motivo —</option>
                    <option value="disponibilidad_maquina">Falta de disponibilidad de máquina</option>
                    <option value="disponibilidad_operario">Falta de disponibilidad de operario</option>
                    <option value="falta_material">Falta de material</option>
                </select>
            </div>

            <div class="mant-modal-field">
                <label for="mark-fecha">Fecha de la intervención</label>
                <input type="date" id="mark-fecha" class="machine-selector">
            </div>
            <!-- Hora a la que el operario inicia la intervención -->
            <div class="mant-modal-field" id="mark-hora-wrap">
                <label for="mark-hora-inicio">Hora de inicio</label>
                <input type="time" id="mark-hora-inicio" class="machine-selector" step="60">
            </div>
            <div class="mant-modal-field">
                <label for="mark-operario">Operario</label>
                <select id="mark-operario" class="machine-selector">
                    <option value="">— Sin operario —</option>
                </select>
            </div>
            <div class="mant-modal-field" id="mark-operario-otro-wrap" style="display:none">
                <label for="mark-operario-otro">Número de operario</label>
                <input type="text" inputmode="numeric" pattern="[0-9]+" id="mark-operario-otro" class="machine-selector" placeholder="Ej. 1004">
                <small style="color:var(--blue-mid);font-style:italic">
                    Solo se permite usarlo si la API no devolvió el catálogo activo. Introduce solo el número de operario.
                </small>
            </div>
            <div class="mant-modal-field">
                <label for="mark-observaciones">Observaciones</label>
                <textarea id="mark-observaciones" class="machine-selector mant-textarea" rows="4" placeholder="Cualquier nota sobre la intervención…"></textarea>
            </div>
        </div>
        <div class="mant-modal-footer">
            <button type="button" class="machine-selector-clear mant-modal-btn-cancel" id="mark-modal-cancel" style="background:#a3b8d1">Cancelar</button>
            <button type="button" class="machine-selector-clear mant-modal-btn-ok" id="mark-modal-ok" style="background:#8c181a">✓ Marcar como hecha</button>
        </div>
    </div>
</div>

<?php
$_jsCommon    = __DIR__ . '/../assets/js/common.js';
$_jsView      = __DIR__ . '/../assets/js/view_mant_proximas.js';
$_jsCommonVer = file_exists($_jsCommon) ? filemtime($_jsCommon) : time();
$_jsViewVer   = file_exists($_jsView)   ? filemtime($_jsView)   : time();
?>
<script src="../assets/js/common.js?v=<?= $_jsCommonVer ?>"></script>
<script src="../assets/js/view_mant_proximas.js?v=<?= $_jsViewVer ?>"></script>
</body>
</html>
