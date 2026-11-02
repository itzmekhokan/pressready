# PressReady GitHub Action image.
#
# The whole point of this image is to bake PressReady + its PHPCS 4.x toolchain
# (PHPCompatibilityWP) into an isolated filesystem so consumer repos pinned to
# squizlabs/php_codesniffer ^3.13 can never clash with it. Consumers call the
# action with one `uses:` line; nothing from this image leaks onto their runner.
#
# Supply chain: base images are pinned by digest (Dependabot bumps them), and
# PressReady is built from the checked-out source with the dependency set locked
# in composer.lock — so the image is exactly the tagged commit, which is what
# its build-provenance attestation claims.

# php:8.3-cli
FROM php:8.3-cli@sha256:600bce7c7acf524756e5aac989f4a763aed709960d7326970bb670f46efee53a

# Composer (from the official image, composer:2) + git/unzip so it can fetch dist packages.
COPY --from=composer:2@sha256:4ed71c38c6d788eb4e4e3747b5332f5b40d5b5a6fcadd5062a1a70986a118e0a /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/pressready

# Install PressReady's locked toolchain INTO THE IMAGE (never the consumer repo).
# Dev deps are included: require-dev carries the pre-release WP compat ruleset
# (PHP 8.2–8.4 detection). composer.json already allows the PHPCS installer
# plugins, so the standards register non-interactively.
COPY . .
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-interaction --no-progress --prefer-dist \
 && vendor/bin/phpcs -i | grep -q Pressready \
 && ln -s /opt/pressready/bin/pressready /usr/local/bin/pressready

# Stamp the release version (the image has no .git tree to `git describe`),
# read by pressready_version() the same way the phar's stamp is.
ARG PRESSREADY_VERSION=""
RUN if [ -n "$PRESSREADY_VERSION" ]; then printf '%s\n' "$PRESSREADY_VERSION" > VERSION; fi

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
