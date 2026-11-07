#!/bin/bash
# Run the prompt evaluation with a throwaway user on this server (2026-11-07).
# MODEL=<model> runs RegiBase's questions on that model for the length of the run only.
set -e
P=$(openssl rand -hex 12); U=aieval$RANDOM
OCC="sudo -u www-data php /var/www/nextcloud/occ"
OLD=$($OCC config:app:get ai_hub app_engines 2>/dev/null || echo '')
cleanup() {
  $OCC user:delete $U >/dev/null 2>&1 || true
  if [ -n "$MODEL" ]; then
    if [ -n "$OLD" ]; then $OCC config:app:set ai_hub app_engines --value="$OLD" >/dev/null; else $OCC config:app:delete ai_hub app_engines >/dev/null; fi
  fi
}
trap cleanup EXIT
OC_PASS=$P sudo -E -u www-data php /var/www/nextcloud/occ user:add --password-from-env $U >/dev/null
$OCC user:setting $U core lang ja
if [ -n "$MODEL" ]; then
  NEW=$(python3 -c "import json,sys;d=json.loads(sys.argv[1] or '{}');d=d if isinstance(d,dict) else {};d['regibase']={'provider':'claude','mode':'cli','model':sys.argv[2]};print(json.dumps(d))" "$OLD" "$MODEL")
  $OCC config:app:set ai_hub app_engines --value="$NEW" >/dev/null
  echo "model for RegiBase during the run: $MODEL"
fi
cd "$(dirname "$0")" && USER_=$U PASS_=$P URL=${URL:-https://ktec.local.jp} PW=${PW:-/root/.npm/_npx/9833c18b2d85bc59/node_modules/playwright/index.mjs} node regibase-eval.mjs
