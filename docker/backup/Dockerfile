# Service de sauvegarde : restic + client MySQL, planifié par cron (02:00 Africa/Dakar = 02:00 UTC).
FROM alpine:3.20
RUN apk add --no-cache restic mysql-client tzdata ca-certificates \
    && mkdir -p /var/backup-state
COPY backup.sh restore-test.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/restore-test.sh \
    && echo '0 2 * * * /usr/local/bin/backup.sh >> /proc/1/fd/1 2>&1' > /etc/crontabs/root
CMD ["crond", "-f", "-l", "8"]
