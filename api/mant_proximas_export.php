<?php
/**
 * Stub: endpoint retirado.
 *
 * Antes generaba el "Calendario" XLSX o PDF a partir de los filtros activos
 * en la vista mant_proximas.php (botones "Calendario XLSX" / "Calendario PDF").
 *
 * Esos dos botones se han eliminado del UI: ahora la única descarga desde
 * mant_proximas.php es "Tiempos por máquina (XLSX)" → api/mant_proximas_tiempos_export.php.
 *
 * Si llega aquí una petición es porque algún bookmark, link externo o app
 * antigua todavía lo invoca; respondemos con 410 Gone para que sea explícito.
 */
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Endpoint retirado: usa api/mant_proximas_tiempos_export.php\n";
echo "(antes generaba el calendario XLSX/PDF; los botones se han suprimido).\n";
