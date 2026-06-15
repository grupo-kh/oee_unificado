<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();
// Vista 100% CRUD — solo accesible al técnico.
if (!Auth::isTecnico()) {
    header('Location: mantenimiento.php');
    exit;
}

// Modo de listado: 'activas' (default) o 'pausadas' (acceso exclusivo
// para técnico — el tile correspondiente solo aparece con rol técnico).
$modo = (string)($_GET['modo'] ?? 'activas');
if (!in_array($modo, ['activas','pausadas'], true)) $modo = 'activas';

$pageTitle    = $modo === 'pausadas'
    ? 'Mantenimiento · Máquinas pausadas'
    : 'Mantenimiento · Acciones por Máquina';
$backLink     = 'mantenimiento.php';
$hideFiltros  = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>
<script>
    // El JS lee este flag global para pedir al API "activas" o "pausadas".
    window.__ACCIONES_MODO = <?= json_encode($modo) ?>;
</script>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2><?= $modo === 'pausadas' ? 'Máquinas pausadas' : 'Acciones preventivas por máquina' ?></h2>
            <span class="view-card-info" id="info-line">—</span>
        </div>
        <div class="view-card-body">

            <?php if ($modo === 'pausadas'): ?>
            <div style="background:#fff8e1;border-left:5px solid #f59e0b;padding:12px 16px;margin-bottom:14px;border-radius:6px;font-size:13.5px;color:#6b3f00">
                <strong style="color:#92400e">⏸ Máquinas con tareas preventivas pausadas</strong> ·
                Aquí ves todas las máquinas que tienen al menos una tarea preventiva pausada,
                agrupadas por máquina. Las tareas pausadas no aparecen en el panel general ni
                se cuentan en cumplimiento. Para reanudar una tarea, abre la ficha de la
                máquina, edita la tarea y vacía el campo "Fecha de pausado".
                <a href="mant_acciones.php" style="float:right;color:#92400e;text-decoration:underline;font-weight:700">← Volver al panel principal</a>
            </div>
            <?php endif; ?>

            <div class="acc-toolbar">
                <div class="acc-search-box">
                    <svg class="acc-search-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="search" id="acc-search" placeholder="Buscar máquina por nombre o código…" autocomplete="off"
                           oninput="if (typeof renderMaquinas === 'function') renderMaquinas();">
                </div>
                <button type="button" id="acc-new-machine-btn" class="acc-btn acc-btn-primary">
                    <span class="acc-btn-plus">+</span> Crear nueva máquina
                </button>
                <button type="button" id="acc-export-list-xlsx" class="acc-btn acc-btn-secondary"
                        title="Exportar a Excel TODAS las máquinas (excluye SECUENCIA: E66, RACKS, PLATAFORMAS) con el detalle de sus tareas">
                    &#x2B07; Listado XLSX
                </button>
                <button type="button" id="acc-export-list-pdf" class="acc-btn acc-btn-secondary"
                        title="Exportar a PDF TODAS las máquinas (excluye SECUENCIA: E66, RACKS, PLATAFORMAS) con el detalle de sus tareas">
                    &#x2B07; Listado PDF
                </button>
                <span class="acc-counter" id="acc-counter">— máquinas</span>
            </div>

            <div id="acc-back-bar" class="acc-back-bar" style="display:none">
                <button type="button" id="acc-back-btn" class="acc-back-btn" title="Volver al listado completo">
                    <span class="acc-back-arrow">←</span> Volver
                </button>
                <span class="acc-back-label">Agrupación</span>
                <span class="acc-back-title" id="acc-back-title">—</span>
            </div>

            <div class="acc-grid" id="acc-machines">
                <div class="acc-empty">Cargando máquinas…</div>
            </div>

        </div>
    </div>
</main>

<!-- Modal · tareas de una máquina -->
<div id="acc-modal" class="acc-modal" aria-hidden="true">
    <div class="acc-modal-backdrop" id="acc-modal-backdrop"></div>
    <div class="acc-modal-dialog acc-modal-large" role="dialog" aria-modal="true">
        <div class="acc-modal-header">
            <div class="acc-modal-title-wrap">
                <span class="acc-modal-title" id="acc-modal-title">Máquina</span>
                <span class="acc-modal-cod"   id="acc-modal-cod">—</span>
            </div>
            <div class="acc-modal-actions">
                <button type="button" class="acc-icon-btn" id="acc-edit-machine-btn" title="Editar máquina">✎</button>
                <button type="button" class="acc-icon-btn acc-icon-btn-danger" id="acc-delete-machine-btn" title="Borrar máquina (solo si no tiene tareas)">×</button>
                <button type="button" class="acc-modal-close" id="acc-modal-close" aria-label="Cerrar">×</button>
            </div>
        </div>

        <div class="acc-modal-body">
            <div class="acc-tareas-toolbar">
                <span id="acc-tareas-count" class="acc-counter-inline">— tareas</span>
                <button type="button" class="acc-btn acc-btn-success" id="acc-add-btn">
                    <span class="acc-btn-plus">+</span> Añadir tarea
                </button>
                <button type="button" class="acc-btn acc-btn-secondary" id="acc-export-btn" title="Exportar a Excel las acciones preventivas de esta máquina">
                    &#x2B07; XLSX
                </button>
                <button type="button" class="acc-btn acc-btn-secondary" id="acc-tiempos-btn" title="Ver el tiempo total estimado de todas las tareas de esta máquina">
                    &#x23F1; Ver tiempos
                </button>
                <button type="button" class="acc-btn acc-btn-secondary" id="acc-tiempos-xlsx-btn" title="Descargar Excel con los tiempos estimados de esta máquina" style="background:#1a4a7a;color:#fff">
                    &#x2B07; Tiempos XLSX
                </button>
            </div>

            <div class="acc-table-wrap">
                <table class="acc-table">
                    <thead>
                        <tr>
                            <th style="width:140px">Tarea</th>
                            <th style="width:130px">Periodicidad</th>
                            <th>Descripción</th>
                            <th style="width:110px">Estado</th>
                            <th style="width:180px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="acc-tareas-tbody">
                        <tr><td colspan="5" class="acc-empty">—</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal · alta / edición de tarea -->
<!-- Modal · Tiempos por máquina (solo plan completo, sin pendientes) -->
<div id="acc-tiempos-modal" class="mant-modal" style="display:none" aria-hidden="true">
    <div class="mant-modal-backdrop" id="acc-tiempos-modal-backdrop"></div>
    <div class="mant-modal-dialog" role="dialog" aria-modal="true">
        <div class="mant-modal-header">
            <span>⏱ Tiempo del plan completo · <span id="acc-tiempos-modal-title">—</span></span>
            <button type="button" class="mant-modal-close" id="acc-tiempos-modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="mant-modal-body" id="acc-tiempos-modal-body">
            <div class="mant-empty">Cargando…</div>
        </div>
    </div>
</div>
<style>
/* Reutilizamos los mismos anchos del popup de tiempos de mant_proximas.php */
#acc-tiempos-modal .mant-modal-dialog {
    width: min(1100px, 96vw);
    max-width: none;
    max-height: 92vh;
}
#acc-tiempos-modal .mant-modal-body { overflow-x: hidden; overflow-y: auto; }
#acc-tiempos-modal .mant-table { width: 100%; table-layout: auto; border-collapse: collapse; }
#acc-tiempos-modal .mant-table th,
#acc-tiempos-modal .mant-table td {
    padding: 6px 8px; word-wrap: break-word; overflow-wrap: anywhere; font-size: 12.5px;
}
#acc-tiempos-modal .tiempos-tbl-per th:nth-child(1),
#acc-tiempos-modal .tiempos-tbl-per td:nth-child(1) { width: 28%; }
#acc-tiempos-modal .tiempos-tbl-per th:nth-child(n+2),
#acc-tiempos-modal .tiempos-tbl-per td:nth-child(n+2) { width: 36%; text-align: right; }
#acc-tiempos-modal .tiempos-tbl-task th:nth-child(1),
#acc-tiempos-modal .tiempos-tbl-task td:nth-child(1) { width: 72px; }
#acc-tiempos-modal .tiempos-tbl-task th:nth-child(2),
#acc-tiempos-modal .tiempos-tbl-task td:nth-child(2) { width: 110px; }
#acc-tiempos-modal .tiempos-tbl-task th:nth-child(3),
#acc-tiempos-modal .tiempos-tbl-task td:nth-child(3) { width: auto; }
#acc-tiempos-modal .tiempos-tbl-task th:nth-child(4),
#acc-tiempos-modal .tiempos-tbl-task td:nth-child(4) { width: 78px; text-align: right; }
#acc-tiempos-modal .tiempos-tbl-task th:nth-child(5),
#acc-tiempos-modal .tiempos-tbl-task td:nth-child(5) { width: 96px; text-align: center; }
</style>

<div id="acc-form-modal" class="acc-modal" aria-hidden="true">
    <div class="acc-modal-backdrop" id="acc-form-backdrop"></div>
    <div class="acc-modal-dialog acc-modal-form" role="dialog" aria-modal="true">
        <div class="acc-modal-header">
            <span class="acc-modal-title" id="acc-form-title">Nueva tarea</span>
            <button type="button" class="acc-modal-close" id="acc-form-close" aria-label="Cerrar">×</button>
        </div>
        <div class="acc-modal-body">
            <div class="acc-form-summary" id="acc-form-summary">—</div>

            <!-- Fila 1: Tarea + Periodicidad -->
            <div class="acc-form-row">
                <div class="acc-form-field">
                    <label for="acc-f-tarea">Tarea <span class="acc-required">*</span></label>
                    <input type="text" id="acc-f-tarea" maxlength="50" autocomplete="off" placeholder="ej. ENGRASE-1, 10759">
                    <small class="acc-hint">Identificador corto. Único dentro de la máquina.</small>
                </div>
                <div class="acc-form-field">
                    <label for="acc-f-periodicidad">Periodicidad <span class="acc-required">*</span></label>
                    <select id="acc-f-periodicidad">
                        <option value="">— Selecciona —</option>
                    </select>
                    <small class="acc-hint">El cambio de cadencia se aplica <strong>desde hoy en adelante</strong>. Las intervenciones ya registradas no se modifican.</small>
                </div>
            </div>

            <!-- Descripción a ancho completo -->
            <div class="acc-form-field">
                <label for="acc-f-desc">Descripción de la tarea <span class="acc-required">*</span></label>
                <textarea id="acc-f-desc" rows="3" maxlength="500" placeholder="Detalla la tarea preventiva a realizar…"></textarea>
            </div>

            <!-- Campos nuevos (migracion 006): 2 filas de 2 columnas -->
            <div class="acc-form-row">
                <div class="acc-form-field">
                    <label for="acc-f-alta-baja">Alta/Baja</label>
                    <select id="acc-f-alta-baja">
                        <option value="ALTA">ALTA</option>
                        <option value="BAJA">BAJA</option>
                    </select>
                    <small class="acc-hint">BAJA = no se planifica.</small>
                </div>
                <div class="acc-form-field">
                    <label for="acc-f-ip-interna">IP Interna</label>
                    <input type="text" id="acc-f-ip-interna" maxlength="50" autocomplete="off" placeholder="ej. IP12061">
                </div>
            </div>
            <div class="acc-form-row">
                <div class="acc-form-field">
                    <label for="acc-f-tipo-mant">Tipo de mantenimiento</label>
                    <select id="acc-f-tipo-mant">
                        <option value="">— Sin asignar —</option>
                        <option value="Preventivo">Preventivo</option>
                        <option value="Predictivo">Predictivo</option>
                    </select>
                </div>
                <div class="acc-form-field">
                    <label for="acc-f-tipo-real">Realización</label>
                    <select id="acc-f-tipo-real">
                        <option value="">— Sin asignar —</option>
                        <option value="Interno">Interno</option>
                        <option value="Externo">Externo</option>
                    </select>
                </div>
            </div>

            <!-- Migracion 011: tiempo estimado -->
            <div class="acc-form-row">
                <div class="acc-form-field">
                    <label for="acc-f-tiempo">Tiempo estimado (min)</label>
                    <input type="number" id="acc-f-tiempo" min="0" max="10000" step="1" placeholder="ej. 30">
                    <small class="acc-hint">Duración prevista en minutos. Déjalo vacío si no se conoce.</small>
                </div>
                <div class="acc-form-field"><!-- hueco para mantener el grid 2-col --></div>
            </div>

            <!-- Alta: fecha de primera revisión (full width) -->
            <div class="acc-form-field" id="acc-f-primera-wrap">
                <label for="acc-f-primera">Fecha de la primera revisión <span class="acc-required">*</span></label>
                <input type="date" id="acc-f-primera">
                <small class="acc-hint">A partir de aquí se calculan las próximas revisiones según la periodicidad.</small>
            </div>

            <!-- Edición: pausa de la tarea -->
            <div class="acc-form-field" id="acc-f-pausado-wrap" style="display:none">
                <label for="acc-f-pausado">Fecha de pausado</label>
                <input type="date" id="acc-f-pausado">
                <small class="acc-hint">Si se rellena, la tarea queda <strong>pausada</strong> desde esa fecha — no se planifica ni computa cumplimiento. Vacíalo para reanudar.</small>
            </div>

            <!-- Edición: bloqueo temporal con rango de fechas -->
            <div id="acc-f-bloqueo-wrap" style="display:none">
                <div class="acc-form-row">
                    <div class="acc-form-field">
                        <label for="acc-f-bloqueo-ini">Bloqueo · desde</label>
                        <input type="date" id="acc-f-bloqueo-ini">
                    </div>
                    <div class="acc-form-field">
                        <label for="acc-f-bloqueo-fin">Bloqueo · hasta</label>
                        <input type="date" id="acc-f-bloqueo-fin">
                    </div>
                </div>
                <small class="acc-hint">
                    Mientras la fecha de hoy esté dentro del rango, la tarea <strong>no se planifica ni cuenta como no realizada</strong>. Útil para máquinas fuera de producción o racks en stand-by. Deja ambos vacíos para mantener la tarea activa.
                </small>
            </div>
        </div>
        <div class="acc-modal-footer">
            <button type="button" class="acc-btn acc-btn-secondary" id="acc-form-cancel">Cancelar</button>
            <button type="button" class="acc-btn acc-btn-primary"   id="acc-form-save">Guardar</button>
        </div>
    </div>
</div>

<!-- Modal · alta / edición de máquina -->
<div id="acc-machine-modal" class="acc-modal" aria-hidden="true">
    <div class="acc-modal-backdrop" id="acc-machine-backdrop"></div>
    <div class="acc-modal-dialog" role="dialog" aria-modal="true">
        <div class="acc-modal-header">
            <span class="acc-modal-title" id="acc-machine-title">Crear nueva máquina</span>
            <button type="button" class="acc-modal-close" id="acc-machine-close" aria-label="Cerrar">×</button>
        </div>
        <div class="acc-modal-body">
            <div class="acc-form-field">
                <label for="acc-m-cod">Código de máquina <span class="acc-required">*</span></label>
                <input type="text" id="acc-m-cod" maxlength="120" autocomplete="off" placeholder="ej. 999, NUEVA-LINEA-1">
                <small class="acc-hint">Identificador interno, único. No se puede cambiar después.</small>
            </div>
            <div class="acc-form-field">
                <label for="acc-m-desc">Descripción <span class="acc-required">*</span></label>
                <input type="text" id="acc-m-desc" maxlength="200" autocomplete="off" placeholder="ej. Línea de soldadura nueva">
            </div>
            <div class="acc-form-field">
                <label for="acc-m-notas">Notas (opcional)</label>
                <textarea id="acc-m-notas" rows="2" maxlength="500" placeholder="Notas internas, ubicación, marca, etc."></textarea>
            </div>
        </div>
        <div class="acc-modal-footer">
            <button type="button" class="acc-btn acc-btn-secondary" id="acc-machine-cancel">Cancelar</button>
            <button type="button" class="acc-btn acc-btn-primary"   id="acc-machine-save">Guardar</button>
        </div>
    </div>
</div>

<!-- Modal · confirmación de borrado en cascada de máquina -->
<div id="acc-delete-confirm-modal" class="acc-modal" aria-hidden="true">
    <div class="acc-modal-backdrop" id="acc-delete-confirm-backdrop"></div>
    <div class="acc-modal-dialog acc-modal-danger" role="dialog" aria-modal="true">
        <div class="acc-modal-header acc-modal-header-danger">
            <span class="acc-modal-title">⚠ Borrar máquina permanentemente</span>
            <button type="button" class="acc-modal-close" id="acc-delete-confirm-close" aria-label="Cerrar">×</button>
        </div>
        <div class="acc-modal-body">
            <p class="acc-danger-lead">
                Vas a borrar la máquina
                <strong id="acc-del-desc">—</strong>
                <span class="acc-modal-cod" id="acc-del-cod">(—)</span>
                <strong>de forma definitiva</strong>.
                Esta operación NO se puede deshacer.
            </p>
            <div class="acc-danger-impact">
                <div class="acc-danger-row">
                    <span class="acc-danger-num" id="acc-del-tareas">0</span>
                    <span>tareas preventivas asociadas</span>
                </div>
                <div class="acc-danger-row">
                    <span class="acc-danger-num" id="acc-del-intervenciones">0</span>
                    <span>intervenciones del histórico (auditoría) <em>se borrarán también</em></span>
                </div>
                <div class="acc-danger-row">
                    <span class="acc-danger-num" id="acc-del-pendientes">0</span>
                    <span>marcas de pendiente</span>
                </div>
                <div class="acc-danger-row">
                    <span class="acc-danger-num" id="acc-del-overrides">0</span>
                    <span>overrides de periodicidad</span>
                </div>
            </div>
            <p class="acc-danger-foot">¿Confirmas el borrado total?</p>
        </div>
        <div class="acc-modal-footer">
            <button type="button" class="acc-btn acc-btn-secondary" id="acc-delete-confirm-cancel">Cancelar</button>
            <button type="button" class="acc-btn acc-btn-danger"    id="acc-delete-confirm-ok">Borrar definitivamente</button>
        </div>
    </div>
</div>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_mant_acciones.js';
$_jsCommonVer = file_exists($_jsCommon) ? filemtime($_jsCommon) : time();
$_jsViewVer   = file_exists($_jsView)   ? filemtime($_jsView)   : time();
?>
<script src="../assets/js/common.js?v=<?= $_jsCommonVer ?>"></script>
<script src="../assets/js/view_mant_acciones.js?v=<?= $_jsViewVer ?>"></script>
</body>
</html>
