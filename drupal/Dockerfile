FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:c281a81ef6c38c3a24f715ba418ca6c6209bf2aef16db15e7a9d8e935f3d27f5

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
