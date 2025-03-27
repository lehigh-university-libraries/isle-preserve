ARG ISLANDORA_DRUPAL_TAG=4
FROM islandora/imagemagick:alpine-3.20.2-imagemagick-7.1.1.36-r0 as imagemagick
FROM islandora/leptonica:alpine-3.20.2-leptonica-1.84.1-r0 as leptonica
FROM islandora/drupal:${ISLANDORA_DRUPAL_TAG}
COPY --link rootfs /
RUN --mount=type=cache,id=custom-drupal-composer-${TARGETARCH},sharing=locked,target=/root/.composer/cache \
    --mount=type=bind,from=imagemagick,source=/packages,target=/packages \
    --mount=type=bind,from=imagemagick,source=/etc/apk/keys,target=/etc/apk/keys-imagemagick \
    --mount=type=bind,from=leptonica,source=/packages,target=/packages-leptonica \
    --mount=type=bind,from=leptonica,source=/etc/apk/keys,target=/etc/apk/keys-leptonica \
    composer install -d /var/www/drupal && \
    chown -R nginx:nginx /var/www/drupal && \
    cp -r /etc/apk/keys-imagemagick/* /etc/apk/keys-leptonica/* /etc/apk/keys/ && \
    apk update && \
    apk upgrade --available && \
    apk add /packages/imagemagick-*.apk /packages-leptonica/leptonica-*.apk && \
    apk add --no-cache \
      php83-pdo_sqlite \
      php83-pecl-uploadprogress \
      php83-pecl-memcache \
      php83-pecl-xhprof \
      ffmpeg && \
    /var/www/drupal/scripts/ca-certs.sh && \
    cleanup.sh
