FROM ghcr.io/lehigh-university-libraries/drupal:main@sha256:f3681ad3d9b748862ea0056acdd50bf7ecf79ef22989d71fd2ac0cab958092f8

COPY --link rootfs /

RUN apk add --no-cache poppler-utils=25.12.0-r1 && \
    composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal
