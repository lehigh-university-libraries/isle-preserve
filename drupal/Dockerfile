FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:5fe6fc3c8985ac88b801874da21a13e291ef788550b7788ed6b7b64b539571cd

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
