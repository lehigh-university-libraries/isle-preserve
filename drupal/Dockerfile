FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:9527ceafc3dea36681f95bdebff73ea155230b9c9d587911db6e07bf43a66cb2

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
