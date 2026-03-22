FROM bref/php-84-fpm:2

# Copy the application code
COPY public/ /var/task/public/

# Bref FPM handler serves from /var/task
CMD ["public/index.php"]
