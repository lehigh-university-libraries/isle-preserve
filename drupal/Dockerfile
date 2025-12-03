FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:34e7b9ccf887bd583f64b1214946c5c98de199c421f2937b3d137a27cb967691

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
