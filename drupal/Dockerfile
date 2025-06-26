FROM ghcr.io/lehigh-university-libraries/docker-builds/drupal:main@sha256:a719be4c4a5d7a1d1321f6a2243aaa81ad9f8849d3d0166aeedb32ee06f70078

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
