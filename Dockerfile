FROM php:8.4-cli

# AWS Lambda Web Adapter — proxies Lambda events to PHP built-in server
COPY --from=100055760442.dkr.ecr.us-east-2.amazonaws.com/aws-lambda-adapter:1.1.0 \
  /lambda-adapter /opt/extensions/lambda-adapter

# AWS CLI for S3 database download and SSM parameter fetch
RUN apt-get update && apt-get install -y awscli && rm -rf /var/lib/apt/lists/*

COPY public/ /var/task/public/

ENV PORT=8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/task/public"]
