#!/usr/bin/env bash
#
# Restauración de un backup de OEE Unificado.
#
# Uso:  ./restaurar.sh /ruta/al/oee_unificado_backup_AAAAMMDD_HHMMSS.tar.gz
#
# Extrae el paquete y guía la restauración de:
#   - PostgreSQL (plan_attainment)  ← lo más crítico
#   - .env y secretos
#   - código (si hiciera falta; normalmente se recupera de GitHub)
#
# NO restaura automáticamente la BD para evitar sobrescrituras accidentales:
# muestra el comando exacto y pide confirmación.
#
set -euo pipefail

PAQUETE="${1:-}"
[ -f "$PAQUETE" ] || { echo "Uso: $0 <paquete_backup.tar.gz>"; exit 1; }

WORK="$(mktemp -d /tmp/oee_restore.XXXXXX)"
echo "Extrayendo $PAQUETE …"
tar xzf "$PAQUETE" -C "$WORK"

echo
echo "Contenido del backup:"
echo "  · BD:        ${WORK}/plan_attainment.dump"
echo "  · .env:      ${WORK}/config/.env"
echo "  · código:    ${WORK}/codigo.tar.gz"
[ -f "${WORK}/GIT_COMMIT.txt" ] && echo "  · commit:    $(cat "${WORK}/GIT_COMMIT.txt")"
echo

echo "─── Restaurar PostgreSQL ───"
echo "ATENCIÓN: esto SOBRESCRIBE la base de datos plan_attainment actual."
read -r -p "¿Restaurar la BD ahora? (escribe 'SI' para confirmar): " resp
if [ "$resp" = "SI" ]; then
    echo "Recreando base de datos…"
    sudo -u postgres dropdb --if-exists plan_attainment
    sudo -u postgres createdb plan_attainment
    sudo -u postgres pg_restore -d plan_attainment "${WORK}/plan_attainment.dump"
    echo "BD restaurada."
else
    echo "BD NO restaurada. Para hacerlo manualmente:"
    echo "  sudo -u postgres dropdb --if-exists plan_attainment"
    echo "  sudo -u postgres createdb plan_attainment"
    echo "  sudo -u postgres pg_restore -d plan_attainment ${WORK}/plan_attainment.dump"
fi

echo
echo "─── .env y código ───"
echo "El .env del backup está en: ${WORK}/config/.env"
echo "El código está en:          ${WORK}/codigo.tar.gz  (normalmente: git clone del repo)"
echo
echo "Archivos extraídos en: $WORK  (bórralo cuando termines: rm -rf $WORK)"
