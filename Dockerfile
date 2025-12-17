FROM dunglas/frankenphp:latest

# Install dependencies needed for Redis extension
RUN apt-get update && apt-get install -y \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Install Redis extension
RUN install-php-extensions redis

# Verify the extension is loaded
RUN php -m | grep redis
