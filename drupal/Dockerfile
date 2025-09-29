FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:111d99ddcd022f61150803399619178b9fb777b42368a10396631ee2bd8fcb79

COPY --link rootfs /

RUN composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
