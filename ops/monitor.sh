#!/bin/sh
# L8-04 — Supervision minimale (cron toutes les 5 min sur l'hôte) : disponibilité HTTP, santé applicative,
# disque, mémoire, âge de la dernière sauvegarde. Une alerte e-mail est envoyée à ALERT_EMAIL (mail/sendmail requis).
set -u
cd "$(dirname "$0")/.." || exit 1
. ./.env

ALERTS=""
add() { ALERTS="$ALERTS\n- $1"; }

CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "https://$APP_DOMAIN/api/v1/health")
[ "$CODE" = "200" ] || add "API indisponible (HTTP $CODE)"

DISK=$(df -P / | awk 'NR==2 {gsub("%",""); print $5}')
[ "$DISK" -lt 85 ] || add "Disque occupé à ${DISK} %"

MEM=$(free | awk '/Mem:/ {printf "%d", $3/$2*100}')
[ "$MEM" -lt 90 ] || add "Mémoire utilisée à ${MEM} %"

LAST=$(docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backup cat /var/backup-state/last-success 2>/dev/null || echo 0)
AGE=$(( $(date +%s) - ${LAST:-0} ))
[ "$AGE" -lt 93600 ] || add "Dernière sauvegarde réussie il y a plus de 26 h"

for SVC in app queue scheduler mysql redis caddy; do
  docker compose -f docker-compose.yml -f docker-compose.prod.yml ps --status running --services | grep -qx "$SVC" || add "Service $SVC arrêté"
done

if [ -n "$ALERTS" ]; then
  printf "Alertes FDS Business (%s) :%b\n" "$(hostname)" "$ALERTS" | mail -s "[FDS] Alerte d'exploitation" "$ALERT_EMAIL"
fi
