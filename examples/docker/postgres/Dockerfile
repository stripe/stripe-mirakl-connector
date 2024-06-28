FROM postgres:14.0-alpine

# Create the ssl directory
RUN mkdir -p /etc/ssl/private

# Copy SSL certificates from the build context to the container
COPY certs/server.crt /etc/ssl/certs/server.crt
COPY certs/server.key /etc/ssl/private/server.key
COPY certs/ca.crt /etc/ssl/certs/root.crt
#
# Copy PostgreSQL configuration files
COPY config/postgresql.conf /etc/postgresql/postgresql.conf
COPY config/pg_hba.conf /etc/postgresql/pg_hba.conf

# Set ownership and permissions for the SSL certificates and config files
RUN chown postgres:postgres /etc/ssl/private/server.key \
    && chmod 600 /etc/ssl/private/server.key \
    && chown postgres:postgres /etc/ssl/certs/server.crt \
    && chmod 600 /etc/ssl/certs/server.crt \
    && chown postgres:postgres /etc/ssl/certs/root.crt \
    && chmod 600 /etc/ssl/certs/root.crt \
    && chown -R postgres:postgres /etc/postgresql \
    && chown -R postgres:postgres /var/lib/postgresql

ENV PGSSLROOTCERT=/etc/ssl/certs/root.crt
ENV PGSSLCERT=/etc/ssl/certs/server.crt
ENV PGSSLKEY=/etc/ssl/private/server.key

CMD ["postgres", "-c", "config_file=/etc/postgresql/postgresql.conf"]

