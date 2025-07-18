FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:b50f719f8b6f8bc28237782052f454987644faa3b520e7a38150eeebe32196ab

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
