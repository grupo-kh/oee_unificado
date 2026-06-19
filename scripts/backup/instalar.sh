#!/usr/bin/env bash
#
# Instalación del backup semanal de OEE Unificado.
#
# 1) Monta el recurso de fs01 (rw) para backups, de forma persistente (fstab).
# 2) Programa el cron semanal (lunes 02:00).
#
# Editar las variables RECURSO_SMB y SUBCARPETA antes de ejecutar.
#
set -euo pipefail

# ─────────────── Configurar antes de ejecutar ───────────────
RECURSO_SMB="//fs01/dkh"                # recurso compartido de fs01 (montado rw aparte)
PUNTO_MONTAJE="/mnt/backup-oee"
SUBCARPETA="Backups/OEE_Unificado"       # subcarpeta dentro del recurso (se crea si no existe)
CRED_FILE="/etc/oee-smb.cred"            # reutiliza las credenciales ya creadas para dkh
HORARIO_CRON="0 2 * * 1"                 # lunes a las 02:00
SCRIPT="/home/aistudio/oee_unificado/scripts/backup/backup_semanal.sh"

DEST="${PUNTO_MONTAJE}/${SUBCARPETA}"

echo "=== 1) Montaje rw de ${RECURSO_SMB} en ${PUNTO_MONTAJE} ==="
sudo mkdir -p "$PUNTO_MONTAJE"
# Entrada fstab (rw, mismas credenciales que dkh). Idempotente.
LINEA="${RECURSO_SMB} ${PUNTO_MONTAJE} cifs credentials=${CRED_FILE},rw,iocharset=utf8,vers=3.0,uid=aistudio,gid=aistudio,file_mode=0640,dir_mode=0750 0 0"
if ! grep -q "${PUNTO_MONTAJE} cifs" /etc/fstab; then
    echo "$LINEA" | sudo tee -a /etc/fstab
fi
sudo mount -a
mkdir -p "$DEST"
echo "  destino de backups: $DEST"

echo "=== 2) Cron semanal ==="
# Línea de cron que exporta el destino y ejecuta el script.
CRON_LINE="${HORARIO_CRON} BACKUP_DEST='${DEST}' BACKUP_KEEP=8 ${SCRIPT} >/dev/null 2>&1"
# Instalar en el crontab del usuario (evitando duplicados).
( crontab -l 2>/dev/null | grep -v "$SCRIPT" ; echo "$CRON_LINE" ) | crontab -
echo "  cron instalado:"
crontab -l | grep "$SCRIPT"

echo
echo "=== Listo. Prueba manual: ==="
echo "  BACKUP_DEST='${DEST}' ${SCRIPT}"
