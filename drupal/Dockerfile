FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:e6eaceab580fefd42a5d45a8ffa6bd60adf7b646d5f317dc386d173c7a7d9215

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
