FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:e244b56eb447c03de21ebc2e9fda6a360bc093616a1331769be40a770cf46e5a

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
