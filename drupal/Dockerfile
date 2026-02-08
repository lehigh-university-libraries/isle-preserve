FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:deb2c758ab4ea32d675ace1546770390f79f143276667d5bcc058f58ab3111fc

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
