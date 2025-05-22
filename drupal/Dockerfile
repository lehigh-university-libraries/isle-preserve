FROM ghcr.io/lehigh-university-libraries/docker-builds/drupal:main@sha256:020f1e8c81a769614b50ef1f2d02daa7bb4be5cd6311487131864a7b6ffb424b

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
