#!/usr/bin/env python3
"""Read-only readiness gate; never sends a harness command or installs a build."""
import json,pathlib
p=pathlib.Path(__file__).with_name('scenarios.json');doc=json.loads(p.read_text())
assert doc['schema_version']==1
assert {s['id'] for s in doc['scenarios']}=={f'SR{i:02}' for i in range(1,45)}
for s in doc['scenarios']:
    assert s['assertion'] and s['required_modes'] and s['cleanup'] and s['timeout_seconds']>0
    if s['full_status']=='BLOCKED':assert s['blockers']
    else:
        assert s['fixture']['checksum'] and s['baseline_assertions'] and s['real_action']
        raise SystemExit('Ready scenarios need the later game executor; do not auto-deploy from Phase 1')
print('44 scenario manifests validated; full-system scenarios explicitly BLOCKED')
