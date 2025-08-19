FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:b90b0a7e4059deb8cd39231b650f5b283f10a7d88ed711ce3b398cbb126809be

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
