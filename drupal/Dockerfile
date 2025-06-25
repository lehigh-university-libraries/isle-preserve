FROM ghcr.io/lehigh-university-libraries/docker-builds/drupal:main@sha256:9eb15f2f8786e95eb211d104ad9f75c4dc4444b68826ad4dcf4f186a929e2b0a

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
