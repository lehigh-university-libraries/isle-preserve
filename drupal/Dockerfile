FROM ghcr.io/lehigh-university-libraries/drupal:main

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
