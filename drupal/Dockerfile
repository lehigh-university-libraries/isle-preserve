FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:202c3a1ceb394d711ec8c00dd93d68fa442a594564990da073cc101b83acb7d6

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
