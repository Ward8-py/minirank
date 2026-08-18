FROM php:8.0-cli
WORKDIR /app
COPY . /app
RUN php -r 'foreach (["pdo_sqlite", "session"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Missing required PHP extension: $e\n"); exit(1); } }'
RUN sed -i 's/\r$//' /app/docker/entrypoint.sh && chmod +x /app/docker/entrypoint.sh
EXPOSE 8000
ENTRYPOINT ["/app/docker/entrypoint.sh"]