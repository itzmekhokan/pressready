# PressReady GitHub Action image.
#
# The whole point of this image is to bake PressReady + its PHPCS 4.x toolchain
# (PHPCompatibilityWP) into an isolated filesystem so consumer repos pinned to
# squizlabs/php_codesniffer ^3.13 can never clash with it. Consumers call the
# action with one `uses:` line; nothing from this image leaks onto their runner.
FROM php:8.3-cli

# Composer (from the official image) + git/unzip so it can fetch dist packages.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/pressready

# Install PressReady + the pre-release WP compat ruleset INTO THE IMAGE (never
# the consumer repo). The alpha ruleset needs dev stability; the PHPCS installer
# plugin must be allowed in non-interactive mode or the standards won't register.
RUN composer init --no-interaction --name=itzmekhokan/pressready-action \
 && composer config minimum-stability dev \
 && composer config prefer-stable true \
 && composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
 && composer config --no-plugins allow-plugins.phpcsstandards/phpcsutils true \
 && composer require \
      itzmekhokan/pressready:^1.3 \
      phpcompatibility/phpcompatibility-wp:3.0.0-alpha2 \
      --no-interaction --no-progress \
 && ln -s /opt/pressready/vendor/bin/pressready /usr/local/bin/pressready

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
