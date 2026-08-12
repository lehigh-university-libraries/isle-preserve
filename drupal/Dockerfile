FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:a254a43aa63cda8d396b646d3925eda93289479a65ab95fcd727ccb64337ba0b

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
