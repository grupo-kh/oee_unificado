#!/usr/bin/env bash
#
# Backup semanal de OEE Unificado → almacenamiento externo (fs01).
#
# Respalda lo que NO se puede regenerar fácilmente:
#   1) Base de datos PostgreSQL (plan_attainment): dump comprimido.
#   2) Configuración sensible (.env, secretos de config/).
#   3) Snapshot del código (sin vendor/, cache/, .git): por si GitHub no estuviera.
#
# Todo se empaqueta en un único .tar.gz con fecha, y se copia al destino externo.
# Rota: conserva las últimas N copias y borra las más antiguas.
#
# Pensado para ejecutarse por cron (ver instalar_cron.sh). Es idempotente y
# registra todo en un log.
#
set -euo pipefail

# ─────────────── Configuración ───────────────
PROJ_DIR="/home/aistudio/oee_unificado"
DEST_DIR="${BACKUP_DEST:-/mnt/backup-oee}"   # destino externo (montaje fs01 rw)
RETENER="${BACKUP_KEEP:-8}"                   # nº de backups a conservar (8 semanas ≈ 2 meses)
PG_DB="plan_attainment"
LOG="${PROJ_DIR}/scripts/backup/backup.log"

STAMP="$(date +%Y%m%d_%H%M%S)"
WORK="$(mktemp -d /tmp/oee_backup.XXXXXX)"
NOMBRE="oee_unificado_backup_${STAMP}"
ARCHIVO="${NOMBRE}.tar.gz"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }
fail() { log "ERROR: $*"; rm -rf "$WORK"; exit 1; }

log "===== Inicio backup ${STAMP} ====="

# ─────────────── 0) Comprobaciones ───────────────
[ -d "$PROJ_DIR" ] || fail "No existe el proyecto: $PROJ_DIR"
mkdir -p "$DEST_DIR" 2>/dev/null || true
[ -d "$DEST_DIR" ] || fail "Destino no accesible: $DEST_DIR (¿montaje fs01 montado y con escritura?)"
touch "${DEST_DIR}/.write_test_${STAMP}" 2>/dev/null \
  && rm -f "${DEST_DIR}/.write_test_${STAMP}" \
  || fail "Sin permiso de escritura en $DEST_DIR"

# ─────────────── 1) Dump PostgreSQL ───────────────
log "Volcando PostgreSQL ($PG_DB)…"
if sudo -u postgres pg_dump -Fc "$PG_DB" > "${WORK}/${PG_DB}.dump" 2>>"$LOG"; then
    log "  dump OK ($(du -h "${WORK}/${PG_DB}.dump" | cut -f1))"
else
    fail "pg_dump falló"
fi

# ─────────────── 2) Configuración sensible ───────────────
log "Copiando configuración…"
mkdir -p "${WORK}/config"
[ -f "${PROJ_DIR}/.env" ] && cp "${PROJ_DIR}/.env" "${WORK}/config/.env"
# Secretos opcionales (firma de tokens QR, etc.)
[ -f "${PROJ_DIR}/config/qr_secret.dat" ] && cp "${PROJ_DIR}/config/qr_secret.dat" "${WORK}/config/" 2>/dev/null || true

# ─────────────── 3) Snapshot del código ───────────────
log "Empaquetando código…"
tar czf "${WORK}/codigo.tar.gz" -C "$PROJ_DIR" \
    --exclude='vendor' --exclude='cache' --exclude='.git' \
    --exclude='node_modules' --exclude='scripts/backup/backup.log' \
    . 2>>"$LOG" || fail "tar del código falló"
# Referencia del commit actual (para saber qué versión es).
( cd "$PROJ_DIR" && git rev-parse HEAD 2>/dev/null > "${WORK}/GIT_COMMIT.txt" ) || true

# ─────────────── 4) Empaquetar todo ───────────────
log "Creando paquete final…"
tar czf "/tmp/${ARCHIVO}" -C "$WORK" . 2>>"$LOG" || fail "empaquetado final falló"
TAM="$(du -h "/tmp/${ARCHIVO}" | cut -f1)"

# ─────────────── 5) Copiar al destino externo ───────────────
log "Copiando a destino externo ($DEST_DIR)…"
cp "/tmp/${ARCHIVO}" "${DEST_DIR}/${ARCHIVO}" || fail "copia al destino falló"
log "  backup guardado: ${DEST_DIR}/${ARCHIVO} (${TAM})"

# ─────────────── 6) Rotación ───────────────
log "Rotando (conservar ${RETENER})…"
ls -1t "${DEST_DIR}"/oee_unificado_backup_*.tar.gz 2>/dev/null | tail -n +$((RETENER + 1)) | while read -r viejo; do
    rm -f "$viejo" && log "  eliminado antiguo: $(basename "$viejo")"
done

# ─────────────── Limpieza ───────────────
rm -f "/tmp/${ARCHIVO}"
rm -rf "$WORK"
log "===== Backup completado OK ====="
