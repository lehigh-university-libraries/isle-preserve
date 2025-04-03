FROM us-docker.pkg.dev/lehigh-lts-images/internal/drupal:main

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
