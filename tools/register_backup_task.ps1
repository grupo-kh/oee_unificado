# =============================================================
#  Registra la tarea programada de backup diario en Windows.
#  Ejecutar UNA VEZ como Administrador. La tarea quedara permanente.
# =============================================================

$ErrorActionPreference = "Stop"

$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole] "Administrator")
if (-not $isAdmin) {
    Write-Host "ERROR: ejecuta esto en una PowerShell ELEVADA." -ForegroundColor Red
    exit 1
}

$TaskName    = "PlanAttainment_Backup"
$ScriptPath  = "C:\xampp\htdocs\oee_unificado\tools\backup_postgres.ps1"
$RunHour     = 2     # 02:13 AM (minuto :13 para no clavarnos en :00)
$RunMin      = 13

if (-not (Test-Path $ScriptPath)) {
    Write-Host "ERROR: no existe $ScriptPath" -ForegroundColor Red
    exit 2
}

# Borrar la tarea si ya existia
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Write-Host "Tarea $TaskName ya existia - la sobrescribo." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$ScriptPath`""

$trigger = New-ScheduledTaskTrigger -Daily -At "${RunHour}:${RunMin}"

$principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -DontStopIfGoingOnBatteries `
    -AllowStartIfOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

Register-ScheduledTask `
    -TaskName  $TaskName `
    -Action    $action `
    -Trigger   $trigger `
    -Principal $principal `
    -Settings  $settings `
    -Description "Backup diario de la base PostgreSQL plan_attainment ($RunHour`:$RunMin AM, retencion 30 dias)" `
    | Out-Null

Write-Host ""
Write-Host "OK. Tarea $TaskName registrada." -ForegroundColor Green
Write-Host "  Hora      : $RunHour`:$RunMin AM diario" -ForegroundColor Gray
Write-Host "  Script    : $ScriptPath" -ForegroundColor Gray
Write-Host "  Salida en : C:\backups\plan_attainment_YYYYMMDD_HHMM.dump" -ForegroundColor Gray
Write-Host "  Log       : C:\backups\backup.log" -ForegroundColor Gray
Write-Host ""
Write-Host "Para probarla ya mismo (sin esperar a $RunHour`:$RunMin):" -ForegroundColor Cyan
Write-Host "    Start-ScheduledTask -TaskName $TaskName" -ForegroundColor White
