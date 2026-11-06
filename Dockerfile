# ECR registry (<account>.dkr.ecr.<region>.amazonaws.com), passed in at build time
ARG ECR_REGISTRY
FROM ${ECR_REGISTRY}/aws-lambda-adapter:1.1.0 AS lambda-adapter

FROM php:8.4-cli

# AWS Lambda Web Adapter — proxies Lambda events to PHP built-in server
COPY --from=lambda-adapter /lambda-adapter /opt/extensions/lambda-adapter

# AWS CLI for S3 database download
RUN apt-get update && apt-get install -y awscli && rm -rf /var/lib/apt/lists/*

COPY public/ /var/task/public/

ENV PORT=8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/task/public"]
