# =============================================================
#  Verificacion semanal del backup de PostgreSQL plan_attainment.
#  La invoca el Programador de tareas de Windows cada lunes 09:17.
#
#  Comprueba:
#    1. Existe al menos un .dump en C:\backups
#    2. El mas reciente tiene fecha < 48h
#    3. Tamanio > 100 KB
#    4. pg_restore --list valida la estructura (>= 5 tablas mant_*)
#    5. backup.log tiene entradas OK recientes
#    6. La tarea PlanAttainment_Backup se ejecuto con LastTaskResult = 0
#
#  Salidas:
#    C:\backups\verify.log       - log completo de cada ejecucion
#    C:\backups\verify_alerts.log - SOLO entradas con problemas
#    Toast popup en Windows si algo falla (best-effort)
#  Codigo de salida:  0 = OK   1 = WARN   2 = ERROR
# =============================================================

$ErrorActionPreference = "Continue"

$BackupDir   = "C:\backups"
$LogPath     = Join-Path $BackupDir "verify.log"
$AlertsPath  = Join-Path $BackupDir "verify_alerts.log"
$PgRestore   = "C:\Program Files\PostgreSQL\16\bin\pg_restore.exe"
$TaskName    = "PlanAttainment_Backup"
$MaxHoursOld = 48
$MinSizeKB   = 100

if (-not (Test-Path $BackupDir)) { New-Item -ItemType Directory -Path $BackupDir | Out-Null }

$problems = @()
$infos    = @()
$stamp    = Get-Date -Format "yyyy-MM-dd HH:mm:ss"

function Add-Problem($severity, $msg) {
    $script:problems += [pscustomobject]@{ severity = $severity; msg = $msg }
}
function Add-Info($msg) {
    $script:infos += $msg
}

# --- 1. Existencia ---
$dumps = Get-ChildItem -Path $BackupDir -Filter "plan_attainment_*.dump" -ErrorAction SilentlyContinue |
         Sort-Object LastWriteTime -Descending
if (-not $dumps -or $dumps.Count -eq 0) {
    Add-Problem "ERROR" "No hay ningun .dump en $BackupDir"
} else {
    Add-Info "$($dumps.Count) dump(s) en $BackupDir"

    # --- 2. Frescura ---
    $latest = $dumps[0]
    $hoursOld = [math]::Round(((Get-Date) - $latest.LastWriteTime).TotalHours, 1)
    if ($hoursOld -gt $MaxHoursOld) {
        Add-Problem "ERROR" "El backup mas reciente tiene $hoursOld h (max permitido $MaxHoursOld h): $($latest.Name)"
    } else {
        Add-Info "Mas reciente: $($latest.Name) (hace $hoursOld h)"
    }

    # --- 3. Tamanio ---
    $sizeKB = [math]::Round($latest.Length / 1KB, 0)
    if ($sizeKB -lt $MinSizeKB) {
        Add-Problem "ERROR" "Backup demasiado pequenio: $sizeKB KB (min $MinSizeKB KB): $($latest.Name)"
    } else {
        Add-Info "Tamanio: $sizeKB KB"
    }

    # --- 4. Integridad pg_restore --list ---
    if (Test-Path $PgRestore) {
        $listing = & $PgRestore --list $latest.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            Add-Problem "ERROR" "pg_restore --list fallo (codigo $LASTEXITCODE) sobre $($latest.Name)"
        } else {
            $tables = ($listing | Select-String "TABLE public mant_").Count
            if ($tables -lt 5) {
                Add-Problem "WARN" "Solo $tables tablas mant_* en el dump (esperaba >= 5)"
            } else {
                Add-Info "Estructura del dump OK ($tables tablas mant_*)"
            }
        }
    } else {
        Add-Problem "WARN" "pg_restore.exe no encontrado en $PgRestore"
    }
}

# --- 5. backup.log ---
$blog = Join-Path $BackupDir "backup.log"
if (Test-Path $blog) {
    $tail = Get-Content $blog -Tail 50 -ErrorAction SilentlyContinue
    $okCount  = ($tail | Select-String "  OK  ").Count
    $errCount = ($tail | Select-String "  ERROR  ").Count
    Add-Info "backup.log (ultimas 50 lineas): $okCount OK, $errCount ERROR"
    if ($errCount -gt 0) {
        Add-Problem "WARN" "$errCount errores recientes en backup.log"
    }
} else {
    Add-Problem "WARN" "No existe backup.log (la tarea quizas no se ha ejecutado nunca)"
}

# --- 6. Estado de la tarea programada ---
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $task) {
    Add-Problem "ERROR" "Tarea programada $TaskName no registrada (ejecuta tools\register_backup_task.ps1)"
} else {
    $info = $task | Get-ScheduledTaskInfo
    $lastResult = $info.LastTaskResult
    $lastRun    = $info.LastRunTime
    if ($lastResult -ne 0 -and $lastResult -ne 267011) {  # 267011 = "task has not yet run"
        Add-Problem "ERROR" "Ultimo resultado de $TaskName = $lastResult (esperaba 0)"
    } else {
        Add-Info "Tarea ${TaskName}: LastRun=$lastRun, LastResult=$lastResult, NextRun=$($info.NextRunTime)"
    }
}

# --- Construir reporte ---
$errCount  = @($problems | Where-Object { $_.severity -eq "ERROR" }).Count
$warnCount = @($problems | Where-Object { $_.severity -eq "WARN"  }).Count
$status = if ($errCount -gt 0) { "ERROR" } elseif ($warnCount -gt 0) { "WARN" } else { "OK" }
$exit   = if ($errCount -gt 0) { 2 } elseif ($warnCount -gt 0) { 1 } else { 0 }

# Cabecera
$report  = "[$stamp] verify_backup.ps1 - $status"
$report += "`r`n  ($errCount errores, $warnCount avisos)"

# Detalle de problemas
foreach ($p in $problems) { $report += "`r`n  [$($p.severity)] $($p.msg)" }
# Detalle informativo
foreach ($i in $infos)    { $report += "`r`n  [INFO ] $i" }

# Volcar a log
Add-Content -Path $LogPath -Value $report
Add-Content -Path $LogPath -Value ""

# Si hay problemas, tambien al log de alertas + toast
if ($status -ne "OK") {
    Add-Content -Path $AlertsPath -Value $report
    Add-Content -Path $AlertsPath -Value ""

    # Toast notification (best-effort, no bloquea si falla)
    try {
        Add-Type -AssemblyName System.Windows.Forms
        $balloon = New-Object System.Windows.Forms.NotifyIcon
        $balloon.Icon = [System.Drawing.SystemIcons]::Warning
        $balloon.BalloonTipIcon  = if ($status -eq "ERROR") { 'Error' } else { 'Warning' }
        $balloon.BalloonTipTitle = "Backup PostgreSQL - $status"
        $balloon.BalloonTipText  = "Revisa C:\backups\verify_alerts.log ($errCount errores, $warnCount avisos)"
        $balloon.Visible = $true
        $balloon.ShowBalloonTip(15000)
        Start-Sleep -Seconds 16
        $balloon.Dispose()
    } catch {
        # ignorar fallos de toast (sesion sin desktop, etc.)
    }
}

# Eco para el Programador de tareas
Write-Host $report
exit $exit
