FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:de720f9222c2606f9f01aca4b951bc4f268face61285128b1a5f0b9eab54a5d8

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
