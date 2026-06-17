# =============================================================
#  Registra la tarea programada de verificacion semanal de backup.
#  Ejecutar UNA VEZ como Administrador.
#
#  Lo que hace: cada lunes 09:17 lanza tools\verify_backup.ps1.
#  Si encuentra problemas: log en C:\backups\verify_alerts.log + toast.
# =============================================================

$ErrorActionPreference = "Stop"

$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole] "Administrator")
if (-not $isAdmin) {
    Write-Host "ERROR: ejecuta esto en una PowerShell ELEVADA." -ForegroundColor Red
    exit 1
}

$TaskName    = "PlanAttainment_VerifyBackup"
$ScriptPath  = "C:\xampp\htdocs\oee_unificado\tools\verify_backup.ps1"

if (-not (Test-Path $ScriptPath)) {
    Write-Host "ERROR: no existe $ScriptPath" -ForegroundColor Red
    exit 2
}

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Write-Host "Tarea $TaskName ya existia - la sobrescribo." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$ScriptPath`""

# Lunes a las 09:17. Si quieres cambiar el dia: -DaysOfWeek Monday,Wednesday,Friday
$trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday -At "09:17"

# Corre como el usuario actual interactivo (asi puede mostrar toast).
# Si lo necesitas como SYSTEM (sin popups), cambia el principal.
$principal = New-ScheduledTaskPrincipal `
    -UserId (whoami) `
    -LogonType Interactive `
    -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -DontStopIfGoingOnBatteries `
    -AllowStartIfOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

Register-ScheduledTask `
    -TaskName  $TaskName `
    -Action    $action `
    -Trigger   $trigger `
    -Principal $principal `
    -Settings  $settings `
    -Description "Verificacion semanal del backup de PostgreSQL plan_attainment (lunes 09:17)" `
    | Out-Null

Write-Host ""
Write-Host "OK. Tarea $TaskName registrada." -ForegroundColor Green
Write-Host "  Cuando    : todos los lunes a las 09:17" -ForegroundColor Gray
Write-Host "  Script    : $ScriptPath" -ForegroundColor Gray
Write-Host "  Log       : C:\backups\verify.log" -ForegroundColor Gray
Write-Host "  Alertas   : C:\backups\verify_alerts.log (solo si hay problemas)" -ForegroundColor Gray
Write-Host "  Toast popup en la sesion del usuario si encuentra fallos." -ForegroundColor Gray
Write-Host ""
Write-Host "Para probarla ya:" -ForegroundColor Cyan
Write-Host "    Start-ScheduledTask -TaskName $TaskName" -ForegroundColor White
Write-Host ""
Write-Host "Para borrarla en el futuro:" -ForegroundColor Cyan
Write-Host "    Unregister-ScheduledTask -TaskName $TaskName -Confirm:`$false" -ForegroundColor White
