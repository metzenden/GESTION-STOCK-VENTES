#!/bin/sh
# L8-01 — Provisionnement d'un VPS Ubuntu 24.04 (à exécuter en root, une fois). Relisez avant exécution.
# Durcit SSH (clé uniquement, pas de root), pare-feu (22/80/443), mises à jour de sécurité automatiques, Docker.
set -eu
DEPLOY_USER=${DEPLOY_USER:-fds}

apt-get update && apt-get -y upgrade
apt-get install -y ufw fail2ban unattended-upgrades ca-certificates curl mailutils

# Utilisateur de déploiement (la clé publique doit être fournie dans /root/deploy_key.pub)
id "$DEPLOY_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$DEPLOY_USER"
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
[ -f /root/deploy_key.pub ] && install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /root/deploy_key.pub "/home/$DEPLOY_USER/.ssh/authorized_keys"

# SSH : clés uniquement, root interdit
cat > /etc/ssh/sshd_config.d/90-fds.conf <<'CONF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
MaxAuthTries 3
CONF
systemctl reload ssh

# Pare-feu : seuls SSH et HTTP(S) sont ouverts ; aucun port interne exposé (MySQL, Redis…)
ufw default deny incoming && ufw default allow outgoing
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp
ufw --force enable

dpkg-reconfigure -f noninteractive unattended-upgrades
systemctl enable --now fail2ban

# Docker (dépôt officiel)
curl -fsSL https://get.docker.com | sh
usermod -aG docker "$DEPLOY_USER"

# Supervision toutes les 5 minutes
echo "*/5 * * * * $DEPLOY_USER /home/$DEPLOY_USER/fds/ops/monitor.sh" > /etc/cron.d/fds-monitor
echo "Provisionnement terminé. Déployez l'application dans /home/$DEPLOY_USER/fds (voir docs/09-exploitation.md)."
