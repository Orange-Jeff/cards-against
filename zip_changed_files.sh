#!/usr/bin/env bash
set -euo pipefail

# Create a .zip containing only changed files.
#
# Default mode (working tree):
#   - unstaged changes
#   - staged changes
#   - untracked files
#
# Optional mode (vs base):
#   ./zip_changed_files.sh --vs-base main
#   -> includes files changed in HEAD compared to merge-base with main.
#
# Optional custom output name:
#   ./zip_changed_files.sh --output my-changes.zip

if [[ ! -d .git ]]; then
  echo "Run this script from the repository root." >&2
  exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
  echo "The 'zip' command is not available in this environment." >&2
  exit 1
fi

mode="working"
base_ref=""
output=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --vs-base)
      mode="vs-base"
      base_ref="${2:-}"
      if [[ -z "$base_ref" ]]; then
        echo "Missing value for --vs-base" >&2
        exit 1
      fi
      shift 2
      ;;
    --output)
      output="${2:-}"
      if [[ -z "$output" ]]; then
        echo "Missing value for --output" >&2
        exit 1
      fi
      shift 2
      ;;
    -h|--help)
      sed -n '1,24p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

if [[ -z "$output" ]]; then
  ts="$(date +%Y%m%d-%H%M%S)"
  if [[ "$mode" == "vs-base" ]]; then
    safe_base="${base_ref//\//-}"
    output="changes-vs-${safe_base}-${ts}.zip"
  else
    output="changes-working-${ts}.zip"
  fi
fi

# Build unique file list in an associative set.
declare -A seen=()
files=()

add_file() {
  local f="$1"
  [[ -z "$f" ]] && return 0
  [[ -e "$f" ]] || return 0   # skip deleted/missing files
  case "$f" in
    *.tar.gz|*.zip|data/.last_startup_cleanup)
      return 0
      ;;
  esac
  [[ -n "${seen[$f]:-}" ]] && return 0
  seen["$f"]=1
  files+=("$f")
}

if [[ "$mode" == "vs-base" ]]; then
  while IFS= read -r -d '' f; do
    add_file "$f"
  done < <(git diff --name-only -z "${base_ref}...HEAD")
else
  while IFS= read -r -d '' f; do
    add_file "$f"
  done < <(git diff --name-only -z)

  while IFS= read -r -d '' f; do
    add_file "$f"
  done < <(git diff --cached --name-only -z)

  while IFS= read -r -d '' f; do
    add_file "$f"
  done < <(git ls-files --others --exclude-standard -z)
fi

if [[ ${#files[@]} -eq 0 ]]; then
  echo "No changed files found for mode: $mode"
  exit 0
fi

# Create archive with folder structure preserved.
zip -q "$output" "${files[@]}"

echo "Created: $output"
echo "Files included: ${#files[@]}"
for f in "${files[@]}"; do
  echo " - $f"
done
