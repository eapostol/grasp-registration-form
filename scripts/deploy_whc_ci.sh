#!/usr/bin/env bash
set -euo pipefail

# Use one authenticated connection for script transfer and deployment. The former
# SCP action opened several successive connections and failed during handshakes.
target="${1:-}"
branch="${2:-}"
case "$target:$branch" in
  staging:develop|prod:main) ;;
  *) echo 'Expected staging develop or prod main' >&2; exit 2 ;;
esac
: "${WHC_HOST:?}" "${WHC_PORT:?}" "${WHC_USER:?}" "${WHC_SSH_KEY:?}" "${RUNNER_TEMP:?}"

umask 077
key_file="$(mktemp "$RUNNER_TEMP/whc-deploy-key.XXXXXX")"
trap 'rm -f -- "$key_file"' EXIT
printf '%s\n' "$WHC_SSH_KEY" > "$key_file"

# The workflow installs WHC_KNOWN_HOSTS before invoking this script. Never accept
# a new or changed host key automatically. Target/branch are restricted above.
remote_command='umask 077; mkdir -p "$HOME/deploy/bin" && cat > "$HOME/deploy/bin/grasp_deploy.sh" && chmod 700 "$HOME/deploy/bin/grasp_deploy.sh" && bash "$HOME/deploy/bin/grasp_deploy.sh"'
ssh -T -o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes \
  -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 \
  -i "$key_file" -p "$WHC_PORT" "$WHC_USER@$WHC_HOST" \
  "$remote_command $target $branch" < scripts/grasp_deploy.sh
