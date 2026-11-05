FROM composer:2@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee
# Optional trust bundle is supplied by the cloud machine, never copied into the repository/image.
RUN --mount=type=secret,id=ca_bundle,target=/tmp/cloud-ca.crt \
    if [ -f /tmp/cloud-ca.crt ]; then export SSL_CERT_FILE=/tmp/cloud-ca.crt; fi; \
    apk add --no-cache postgresql-dev icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j2 pdo_pgsql intl bcmath pcntl \
    && apk del $PHPIZE_DEPS
WORKDIR /app/backend
