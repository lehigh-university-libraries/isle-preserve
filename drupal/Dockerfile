FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:bcbaaf80e0ef679775031deec102586a0467d7aab2db31b455b19d7a36a1f40e

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
