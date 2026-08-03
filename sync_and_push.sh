#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   ./sync_and_push.sh
#   ./sync_and_push.sh "chore: custom commit message"

if ! command -v git >/dev/null 2>&1; then
  echo "Error: git is not installed or not available on PATH."
  exit 1
fi

if [[ ! -d .git ]]; then
  echo "Error: run this script from the repository root."
  exit 1
fi

branch="$(git rev-parse --abbrev-ref HEAD)"
if [[ -z "$branch" || "$branch" == "HEAD" ]]; then
  echo "Error: detached HEAD; switch to a branch first."
  exit 1
fi

if ! git remote get-url origin >/dev/null 2>&1; then
  echo "Error: remote 'origin' is not configured."
  exit 1
fi

commit_msg="${1:-chore: sync before leaving ($(date -u +"%Y-%m-%d %H:%M:%S UTC"))}"

has_changes=0
if ! git diff --quiet || ! git diff --cached --quiet; then
  has_changes=1
fi
if [[ -n "$(git ls-files --others --exclude-standard)" ]]; then
  has_changes=1
fi

if [[ "$has_changes" -eq 1 ]]; then
  echo "Staging changes..."
  git add -A

  echo "Committing changes..."
  git commit -m "$commit_msg"
else
  echo "No local changes to commit."
fi

echo "Pulling latest changes with rebase on '$branch'..."
git pull --rebase origin "$branch"

echo "Pushing '$branch' to origin..."
git push origin "$branch"

echo "Sync complete."
