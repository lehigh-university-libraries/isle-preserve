FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:0b45561e50d261b4195fe42e6857ab8fa5f5ba665752413811e72dcfc9d4dbce

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
