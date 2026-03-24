# nba-stats-history

## Rules
- Always explain proposed changes and wait for explicit approval before editing any files.

## GitHub / SSH
When setting up git remotes, use `git@github-claude` instead of `git@github.com` to ensure the correct SSH key (`~/.ssh/id_ed25519_claude`) is used for this personal GitHub account.

Example:
```
git remote add origin git@github-claude:smorehouse/nba-stats-history.git
```

## Project Overview
NBA player stats history viewer. View any player's game-by-game performance over the last N days.

## Tech Stack
- **Data loader**: Python (`nba_api`) — fetches game logs and loads into SQLite
- **Database**: SQLite (`db/nba.sqlite`)
- **Web app**: PHP (vanilla, no framework)
- **Deployment**: Docker container on AWS Lambda via SAM
- **CI/CD**: GitHub Actions — builds Docker image, pushes to ECR, deploys via SAM on version tag push
- **Versioning**: Semver via git tags — use `/release` slash command to create new versions

## Project Structure
```
cloudformation/  — IAM and SAM CloudFormation templates
db/              — SQLite database (gitignored)
loader/          — Python data loader scripts + venv
public/          — PHP web app (document root)
.github/         — GitHub Actions deploy workflows
Dockerfile       — PHP Lambda container image (based on bref/php-84-fpm)
Dockerfile.loader — Python loader container image
```

## Commands
- Start dev server: `./start.sh`
- Load data: `loader/.venv/bin/python loader/load_gamelog.py`
- Upload DB to S3: `aws s3 cp db/nba.sqlite s3://BUCKET/nba.sqlite`
- Release new version: `/release` (analyzes changes, proposes semver bump, tags + deploys after approval)

## AWS Setup
- Password stored in SSM: `/nba-stats/app-password`
- GitHub Actions needs `AWS_ROLE_ARN` secret (OIDC role for deployment)