#!/usr/bin/env bash
set -euo pipefail

SECONDS=0
echo ""
echo "================================================================================"
echo "=== NBA Stats Loader — $(date '+%Y-%m-%d %H:%M:%S') ===================================="
echo "================================================================================"
echo ""

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

source "${SCRIPT_DIR}/.env"

echo "==> Running loader, fantasy roster sync and position sync..."
docker rm -f nba-loader 2>/dev/null || true
docker run --name nba-loader \
  --env GOOGLE_SHEET_ID="$GOOGLE_SHEET_ID" \
  --env FANTRAX_LEAGUE_ID="$FANTRAX_LEAGUE_ID" \
  --entrypoint sh \
  "$IMAGE" \
  -c 'python load_gamelog.py && {
        python sync_fantasy.py "$GOOGLE_SHEET_ID" /tmp/nba.sqlite || echo "WARNING: fantasy sync failed"
        python sync_positions.py "$FANTRAX_LEAGUE_ID" /tmp/nba.sqlite || echo "WARNING: position sync failed"
      }'

docker cp nba-loader:/tmp/nba.sqlite "${DB_DIR}/nba.sqlite"
docker rm nba-loader

# Build loader_runs table from log history + current run
echo "==> Saving run history..."
sqlite3 "${DB_DIR}/nba.sqlite" "CREATE TABLE IF NOT EXISTS loader_runs (
    run_date TEXT PRIMARY KEY,
    duration_seconds INTEGER,
    total_players INTEGER,
    total_games INTEGER
);"

LOGFILE="${SCRIPT_DIR}/loader.log"
if [ -f "$LOGFILE" ]; then
    run_date="" players="" games=""
    while IFS= read -r line; do
        if [[ "$line" == *"NBA Stats Loader —"* ]]; then
            run_date=$(echo "$line" | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}')
        elif [[ "$line" == Done.* ]]; then
            players=$(echo "$line" | grep -oE '^Done\. [0-9]+' | grep -oE '[0-9]+')
            games=$(echo "$line" | grep -oE '[0-9]+ games' | grep -oE '[0-9]+')
        elif [[ "$line" == "==> Finished at"* ]]; then
            if [[ -n "$run_date" && -n "$players" && -n "$games" ]]; then
                mins=$(echo "$line" | grep -oE '[0-9]+m' | grep -oE '[0-9]+')
                secs=$(echo "$line" | grep -oE '[0-9]+s' | grep -oE '[0-9]+')
                dur=$(( mins * 60 + secs ))
                sqlite3 "${DB_DIR}/nba.sqlite" "INSERT OR IGNORE INTO loader_runs VALUES ('${run_date}', ${dur}, ${players}, ${games});"
            fi
            run_date="" players="" games=""
        fi
    done < "$LOGFILE"
fi

CUR_PLAYERS=$(sqlite3 "${DB_DIR}/nba.sqlite" "SELECT COUNT(*) FROM players")
CUR_GAMES=$(sqlite3 "${DB_DIR}/nba.sqlite" "SELECT COUNT(*) FROM game_logs")
sqlite3 "${DB_DIR}/nba.sqlite" "INSERT OR REPLACE INTO loader_runs VALUES ('$(date '+%Y-%m-%d %H:%M:%S')', ${SECONDS}, ${CUR_PLAYERS}, ${CUR_GAMES});"

echo "==> Uploading to S3..."
aws s3 cp "${DB_DIR}/nba.sqlite" "s3://${S3_BUCKET}/${S3_KEY}" --region "$AWS_REGION"

echo "==> Done! Database uploaded to s3://${S3_BUCKET}/${S3_KEY}"

ELAPSED=$SECONDS
echo "==> Finished at $(date '+%Y-%m-%d %H:%M:%S') ($((ELAPSED / 60))m $((ELAPSED % 60))s)"
