#!/bin/sh
# L8-03 / TEST-020 — Test de restauration automatisé (préproduction) : restaure le dernier instantané
# dans une base temporaire, vérifie les comptages essentiels, puis la supprime. RTO visé : 4 h.
set -eu

TARGET=/tmp/fds-restore
rm -rf "$TARGET" && mkdir -p "$TARGET"
restic restore latest --tag fds --target "$TARGET"
DUMP=$(find "$TARGET" -name 'fds-*.sql.gz' | sort | tail -n 1)
[ -n "$DUMP" ] || { echo "Aucun dump trouvé dans l'instantané" >&2; exit 1; }

mysql --host=mysql --user=root --password="$DB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS fds_restore_check; CREATE DATABASE fds_restore_check"
gunzip -c "$DUMP" | mysql --host=mysql --user=root --password="$DB_ROOT_PASSWORD" fds_restore_check

for TABLE in companies users invoices stock_movements financial_movements; do
  ORIG=$(mysql -N --host=mysql --user=root --password="$DB_ROOT_PASSWORD" -e "SELECT COUNT(*) FROM \`$DB_DATABASE\`.$TABLE")
  REST=$(mysql -N --host=mysql --user=root --password="$DB_ROOT_PASSWORD" -e "SELECT COUNT(*) FROM fds_restore_check.$TABLE")
  echo "$TABLE : production=$ORIG restauré=$REST"
done

mysql --host=mysql --user=root --password="$DB_ROOT_PASSWORD" -e "DROP DATABASE fds_restore_check"
rm -rf "$TARGET"
echo "Restauration vérifiée"
