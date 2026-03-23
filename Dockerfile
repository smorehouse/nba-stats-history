FROM php:8.4-apache

# AWS Lambda Web Adapter — proxies Lambda events to Apache
COPY --from=public.ecr.aws/awsguru/aws-lambda-web-adapter:0.8.4 \
  /lambda-adapter /opt/extensions/lambda-adapter

# AWS CLI for S3 database download and SSM parameter fetch
RUN apt-get update && apt-get install -y awscli && rm -rf /var/lib/apt/lists/*

COPY public/ /var/www/html/

ENV PORT=80
