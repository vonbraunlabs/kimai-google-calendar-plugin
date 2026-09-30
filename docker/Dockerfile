# Kimai with this plugin baked in, for local development, pre-PR checks and test environments.
# The plugin is copied (not mounted): Kimai's entrypoint changes the owner of var/, which would
# otherwise rewrite the owner of the files in your working copy.
ARG KIMAI_VERSION=2.67.0
FROM kimai/kimai2:${KIMAI_VERSION}

COPY --chown=www-data:www-data . /opt/kimai/var/plugins/GoogleCalendarBundle
