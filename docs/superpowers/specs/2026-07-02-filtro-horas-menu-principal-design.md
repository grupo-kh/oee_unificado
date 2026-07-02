# Filtro por horas en el menú principal (vistas de paro) — Diseño

**Fecha:** 2026-07-02
**Proyecto:** oee_unificado (web "v2", systemd `oee-unificado-v2` en :8091)
**Rama:** feat/evolucion-motivos

## 1. Objetivo

Añadir al filtro principal de la cabecera un filtro por franja horaria
(check "Filtrar por horas" + Hora desde/Hasta) que acote las vistas basadas en
paros a esa franja del día. Los paros tienen timestamp real (`his_prod_paro.Fecha_ini`).

## 2. Alcance y limitación (verificado)

- **SÍ filtra por hora** (tienen timestamp): gráfica de "Motivos de paro generales"
  y demás vistas de paro (motivo drill, matriz2, top motivos, maq_motivo_temporal,
  of_paros).
- **NO filtra por hora** (no es posible): OEE global + tabla de máquinas
  (`oee_unificado.php`), que salen de `F_his_ct`, la cual solo agrega por DÍA
  (verificado en sesiones previas: no desglosa por hora). Ese bloque sigue por día.
- Se AVISA en la UI para evitar confusión.

## 3. Componentes

### 3.1 Helper compartido `includes/helpers.php`
Función que centraliza la lógica fecha+hora (extraída del patrón ya probado en
`oee_unificado_hist_maquina.php`, incluye cruce de medianoche):

```php
/**
 * Devuelve [sqlFragment, params] para filtrar una columna datetime por rango de
 * fechas y (opcionalmente) franja horaria. Soporta franja que cruza medianoche.
 * @param string $col     columna datetime cualificada (p.ej. "hpp.Fecha_ini")
 * @param string $fdesde  YYYY-MM-DD
 * @param string $fhasta  YYYY-MM-DD
 * @param string $hDesde  HH:MM o '' (sin filtro horario)
 * @param string $hHasta  HH:MM o ''
 * @return array{0:string,1:array}
 */
function filtroFechaHora(string $col, string $fdesde, string $fhasta, string $hDesde='', string $hHasta=''): array
{
    $horaOk = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$hDesde)
           && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$hHasta)
           && $hDesde !== $hHasta;
    if (!$horaOk) {
        return ["CAST($col AS DATE) BETWEEN ? AND ?", [$fdesde,$fhasta]];
    }
    $hh = "CONVERT(varchar(5), $col, 108)";
    if ($hDesde < $hHasta) { // franja normal (mismo día)
        return [
            "(CAST($col AS DATE) BETWEEN ? AND ? AND $hh >= ? AND $hh < ?)",
            [$fdesde,$fhasta,$hDesde,$hHasta]
        ];
    }
    // cruza medianoche
    $fdesdeP1 = date('Y-m-d', strtotime($fdesde.' +1 day'));
    $fhastaP1 = date('Y-m-d', strtotime($fhasta.' +1 day'));
    return [
        "((CAST($col AS DATE) BETWEEN ? AND ? AND $hh >= ?)"
        ." OR (CAST($col AS DATE) BETWEEN ? AND ? AND $hh < ?))",
        [$fdesde,$fhasta,$hDesde,$fdesdeP1,$fhastaP1,$hHasta]
    ];
}
```

Nota: `hist_maquina.php` mantiene su implementación actual (ya funciona); no se
refactoriza para no arriesgar. El helper es para los 6 endpoints nuevos. (Opcional
futuro: migrar hist_maquina al helper.)

### 3.2 Endpoints con soporte horario (6)
En cada uno: leer `hora_desde`/`hora_hasta` (getParam), y sustituir su condición
actual `CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?` por el fragmento del helper
sobre `hpp.Fecha_ini` (la columna de inicio del paro):
- `oee_unificado_drill.php` (gráfica de motivos generales — la vista principal)
- `oee_unificado_motivo_drill.php`
- `oee_unificado_matriz2.php`
- `oee_unificado_maq_motivo_temporal.php`
- `oee_unificado_of_paros.php`
- `oee_unificado_top_analisis.php`

En cada endpoint hay que localizar el filtro de fecha por paro y su posición de
params, y encajar el fragmento respetando el orden de los `?`.

### 3.3 UI (cabecera de oee_unificado_v2.html)
En la fila de fechas (Desde/Hasta), añadir:
- Check `#chkHoras` "Filtrar por horas".
- Inputs `#hDesde` `#hHasta` (type=time, ocultos hasta activar), valores por
  defecto 06:00 / 14:00.
- Aviso discreto: "El filtro horario aplica a los paros; el OEE se calcula por día."
- `common()` ya añade `hora_desde`/`hora_hasta` cuando el check está activo (patrón
  idéntico al que se usó en el histograma; verificar que `common()` lee estos ids).
- Indicador en la línea RANGO (`iRango`) cuando el filtro horario está activo.

## 4. Flujo de datos

`common()` (URLSearchParams) → si `#chkHoras` activo y horas válidas, añade
`hora_desde`/`hora_hasta` → llega a todos los endpoints. Los 6 de paro lo aplican;
`oee_unificado.php` lo ignora (no lo lee). Al cambiar el check/horas → `App.loadMain()`
recarga KPIs+tabla+motivos.

## 5. Verificación

- Helper: prueba unitaria CLI (fragmento + params) para los 3 casos (sin hora,
  normal, cruce medianoche).
- Endpoints: `curl` a `oee_unificado_drill.php?...&metrica=disponibilidad&hora_desde=06:00&hora_hasta=08:00`
  → menos horas/motivos que sin filtro. Repetir para los otros.
- No-regresión: sin `hora_desde`/`hora_hasta`, cada endpoint devuelve lo mismo que hoy.
- Visual: en la cabecera, activar el check, fijar 06:00-08:00 → la gráfica de
  motivos y las vistas de paro se reducen; el OEE global no cambia (esperado).
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.

## 6. Riesgos / notas

- Cada endpoint tiene su propio armado de WHERE/params: hay que encajar el
  fragmento con cuidado del orden de `?` (riesgo principal). Verificar uno a uno.
- El OEE por día conviviendo con paros por hora puede confundir → de ahí el aviso.
- `matriz2` usa además PostgreSQL para categorías, pero el filtro de fecha/hora es
  sobre la consulta MAPEX de paros; solo se toca esa.
- No-regresión imprescindible: el filtro OFF = comportamiento actual byte a byte.
