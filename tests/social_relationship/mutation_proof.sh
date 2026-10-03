#!/bin/bash
# Mutation proof (REL phase 3): each mutation must make tests/social_unconscious_regression.php fail.
# Usage: bash tests/social_relationship/mutation_proof.sh   (works on a /tmp copy; needs the stobe_social_phase1_test DB)
SRC=/root/stobe-work/social-phase1/server
rm -rf /tmp/mut && rsync -a --exclude .git --exclude log "$SRC/" /tmp/mut/ && mkdir -p /tmp/mut/log
run() { bash "$SRC/tests/social_relationship/runtest.sh" tests/social_unconscious_regression.php /tmp/mut | tail -1; }
reset() { rsync -a "$SRC/lib/" /tmp/mut/lib/; }
F=/tmp/mut/lib/social_interpreter.php
python3 - "$F" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
o="$believedThief = $knownThief ?? $remembered;"
assert o in s; open(p,'w').write(s.replace(o,"$believedThief = $knownThief ?? (($ko['state']['objective']['transfers'][0]['taker'] ?? null) ?: $remembered);"))
PY
echo "M1 blame the objective taker: $(run)"; reset
python3 - "$F" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
o="if (($victim['conscious'] ?? null) === true) {"
assert o in s; open(p,'w').write(s.replace(o,"if (true) {",1))
PY
echo "M2 enslavement charged while unconscious: $(run)"; reset
python3 - "$F" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
o="if ($missing > 0 && $squadOnly) {"
assert o in s; open(p,'w').write(s.replace(o,"if (false) {"))
PY
echo "M3 no squad exemption: $(run)"; reset
python3 - "$F" <<'PY'
import sys; p=sys.argv[1]; s=open(p).read()
o="foreach ($moved as $key => $qty) $missing +="
assert o in s; open(p,'w').write(s.replace(o,"foreach ($baseline as $key => $qty) $missing +="))
PY
echo "M4 every missing item counts (no objective transfer needed): $(run)"; reset
echo "unmutated: $(run)"
