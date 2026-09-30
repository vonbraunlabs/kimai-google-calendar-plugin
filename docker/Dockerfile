# Kimai with this plugin baked in, for local development, pre-PR checks and test environments.
# The plugin is copied (not mounted): Kimai's entrypoint changes the owner of var/, which would
# otherwise rewrite the owner of the files in your working copy.
ARG KIMAI_VERSION=2.67.0
FROM kimai/kimai2:${KIMAI_VERSION}

COPY --chown=www-data:www-data . /opt/kimai/var/plugins/GoogleCalendarBundle

# Kimai's entrypoint installs Kimai itself on every start, but not plugins: without this the
# plugin tables don't exist and its page fails with "Table ... doesn't exist". The plugin
# install command is idempotent too, and applies new plugin migrations after a rebuild.
# It runs right after "kimai:reload" (warm cache): Kimai runs the migration in a subprocess
# with a 60 s timeout, and on a cold cache a slow machine exceeded it, killing the migration
# halfway (MySQL DDL is not transactional) and leaving tables behind without the migration
# being recorded. The grep fails the build if a new image changed the entrypoint.
RUN sed -i 's|^\( *\)/opt/kimai/bin/console kimai:reload --env="$APP_ENV"$|&\n\1/opt/kimai/bin/console -n kimai:bundle:google-calendar:install --env="$APP_ENV"|' /entrypoint.sh \
    && grep -q 'kimai:bundle:google-calendar:install' /entrypoint.sh
