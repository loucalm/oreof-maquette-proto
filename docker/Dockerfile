FROM dannebicque/oreof-symfony-app:latest

# L'image ORéOF est orientée MySQL ; le prototype tourne sur SQLite.
RUN apt-get update \
    && apt-get install -y --no-install-recommends php8.5-sqlite3 \
    && rm -rf /var/lib/apt/lists/*
