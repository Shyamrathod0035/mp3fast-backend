FROM php:8.2-apache

# Install system dependencies (Python3 for yt-dlp, ffmpeg for conversion)
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    ffmpeg \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install yt-dlp to /usr/local/bin
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp \
    && chmod a+rx /usr/local/bin/yt-dlp

# Enable Apache mod_rewrite for custom routing/rewrites if needed
RUN a2enmod rewrite

# Set up working directory
COPY . /var/www/html/

# Create downloads directory and set permissions
RUN mkdir -p /var/www/html/downloads && chmod 777 /var/www/html/downloads

EXPOSE 80
