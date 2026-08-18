FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:c5a36a5570af9c647183574921219f6879a59da2687166fd22fd14bff93bcaf2

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
