# =============================================================
#  Backup diario de la base PostgreSQL plan_attainment
#  Lo invoca el Programador de tareas de Windows una vez al dia.
#
#  Genera:    C:\backups\plan_attainment_YYYYMMDD.dump  (formato custom)
#  Conserva:  los 30 backups mas recientes; los mas viejos se borran.
# =============================================================

$ErrorActionPreference = "Stop"

$BackupDir   = "C:\backups"
$KeepDays    = 30
$PgDump      = "C:\Program Files\PostgreSQL\16\bin\pg_dump.exe"
$PgHost      = "127.0.0.1"
$PgUser      = "postgres"
$PgDb        = "plan_attainment"
$PgPassword  = "efaaf0b0477aa06702ee9ca145ffe7f9"

if (-not (Test-Path $BackupDir)) { New-Item -ItemType Directory -Path $BackupDir | Out-Null }

$stamp = Get-Date -Format "yyyyMMdd_HHmm"
$out   = Join-Path $BackupDir "plan_attainment_$stamp.dump"
$log   = Join-Path $BackupDir "backup.log"

$env:PGPASSWORD = $PgPassword
try {
    & $PgDump -U $PgUser -h $PgHost -F c -f $out $PgDb
    if ($LASTEXITCODE -ne 0) { throw "pg_dump fallo con codigo $LASTEXITCODE" }
    $sz = (Get-Item $out).Length
    "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  OK  $out  ($([math]::Round($sz/1MB,2)) MB)" |
        Add-Content -Path $log
} catch {
    "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  ERROR  $($_.Exception.Message)" |
        Add-Content -Path $log
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
    exit 1
} finally {
    Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
}

# Rotacion: borra .dump mas antiguos que $KeepDays
Get-ChildItem -Path $BackupDir -Filter "plan_attainment_*.dump" |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) } |
    ForEach-Object {
        "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  PURGE  $($_.FullName)" |
            Add-Content -Path $log
        Remove-Item $_.FullName -Force
    }
