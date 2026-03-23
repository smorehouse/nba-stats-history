#!/usr/bin/env bash
set -euo pipefail

AWS_REGION="us-east-2"
ECR_REGISTRY="100055760442.dkr.ecr.${AWS_REGION}.amazonaws.com"
ECR_REPOSITORY="nba-stats-loader"
S3_BUCKET="nba-stats-db-nba-stats"
S3_KEY="nba.sqlite"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DB_DIR="${SCRIPT_DIR}/db"
mkdir -p "$DB_DIR"

echo "==> Logging in to ECR..."
aws ecr get-login-password --region "$AWS_REGION" | \
  docker login --username AWS --password-stdin "$ECR_REGISTRY"

echo "==> Pulling latest loader image..."
# Get the latest image tag
LATEST_TAG=$(aws ecr describe-images \
  --repository-name "$ECR_REPOSITORY" \
  --region "$AWS_REGION" \
  --query 'sort_by(imageDetails,&imagePushedAt)[-1].imageTags[0]' \
  --output text)

IMAGE="${ECR_REGISTRY}/${ECR_REPOSITORY}:${LATEST_TAG}"
docker pull "$IMAGE"

echo "==> Running loader..."
docker rm -f nba-loader 2>/dev/null || true
docker run --name nba-loader \
  --entrypoint python \
  "$IMAGE" \
  load_gamelog.py

docker cp nba-loader:/tmp/nba.sqlite "${DB_DIR}/nba.sqlite"
docker rm nba-loader

echo "==> Uploading to S3..."
aws s3 cp "${DB_DIR}/nba.sqlite" "s3://${S3_BUCKET}/${S3_KEY}" --region "$AWS_REGION"

echo "==> Done! Database uploaded to s3://${S3_BUCKET}/${S3_KEY}"
