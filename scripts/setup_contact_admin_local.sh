#!/usr/bin/env bash
set -euo pipefail

# Run in WSL, from any directory. Never accepts or prints private credentials.
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
private_dir="${XDG_CONFIG_HOME:-$HOME/.config}/grasp/reg-form-project"
runtime_file="$project_root/.ddev/docker-compose.contact-admin.local.yaml"
umask 077
mkdir -p "$private_dir"
if [[ ! -e "$private_dir/contact-admin.json" ]]; then
  python3 - "$project_root/config/contact-admin.example.json" "$private_dir/contact-admin.json" <<'PY'
import json
import sys
from pathlib import Path

config = json.loads(Path(sys.argv[1]).read_text())
config.update(project_id="grasp-511222", site_key="", api_key="",
              allowed_hostnames=["reg-form-project.ddev.site"], recipient="ed@edapostol.com")
with open(sys.argv[2], "x") as output:
    json.dump(config, output, indent=2)
    output.write("\n")
PY
  chmod 600 "$private_dir/contact-admin.json"
fi
if [[ ! -e "$runtime_file" ]]; then
  python3 - "$private_dir" "$runtime_file" <<'PY'
import json
import sys

# JSON is valid YAML; quoting also handles paths containing spaces.
config = {"services": {"web": {"volumes": [
    {"type": "bind", "source": sys.argv[1], "target": "/etc/grasp", "read_only": True}
]}}}
with open(sys.argv[2], "x") as output:
    json.dump(config, output, indent=2)
    output.write("\n")
PY
fi
printf 'Private configuration: %s/contact-admin.json\n' "$private_dir"
printf 'DDEV mount configuration: %s\n' "$runtime_file"
printf 'Existing files were preserved. Fill in site_key and api_key locally, then run ddev restart.\n'
