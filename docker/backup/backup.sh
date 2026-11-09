#!/bin/sh
# L8-03 — Sauvegarde quotidienne chiffrée (restic) : base MySQL (dump cohérent) + fichiers privés.
# Exécuté par le service « backup » (docker-compose.prod.yml). RPO visé : 24 h (D-validée 2026-11-04).
set -eu

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK=/tmp/fds-backup
mkdir -p "$WORK"

echo "[$STAMP] Dump MySQL"
mysqldump --host=mysql --user=root --password="$DB_ROOT_PASSWORD" \
  --single-transaction --routines --triggers --events --hex-blob --set-gtid-purged=OFF \
  "$DB_DATABASE" | gzip -9 > "$WORK/fds-$STAMP.sql.gz"

restic snapshots >/dev/null 2>&1 || restic init

echo "[$STAMP] Envoi restic"
restic backup --tag fds --host fds-prod "$WORK" /var/www/backend/storage/app
restic forget --tag fds --keep-daily "${BACKUP_RETENTION_DAILY:-14}" --keep-weekly "${BACKUP_RETENTION_WEEKLY:-8}" \
  --keep-monthly "${BACKUP_RETENTION_MONTHLY:-12}" --prune
restic check --read-data-subset=5%

rm -f "$WORK"/*.sql.gz
date -u +%s > /var/backup-state/last-success
echo "[$STAMP] Sauvegarde terminée"
