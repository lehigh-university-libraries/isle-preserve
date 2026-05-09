FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:5df4c86990d46c575105a0f44a32c7a51ece97bd067191035ee7984e00ee3f6c

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
