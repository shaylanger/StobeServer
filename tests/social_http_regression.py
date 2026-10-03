#!/usr/bin/env python3
"""Private loopback endpoint checks. Never starts Apache or touches Kenshi."""
import json, os, pathlib, socket, subprocess, time, urllib.request, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
assert os.environ.get('STOBE_DB_NAME')=='stobe_social_phase1_test', 'Dedicated database required'
def php(code):
    return subprocess.check_output(['php','-r', 'require "lib/bootstrap.php";'+code],cwd=ROOT,text=True)
keys=['SOCIAL_RELATIONSHIP_MODE']
meta_keys=['PLAYTHROUGH_SESSION','PLAYTHROUGH_AUTO_SWITCH']
old=json.loads(php('$r=$GLOBALS["db"]->fetchAll("SELECT id,value FROM general_settings WHERE id IN (\'SOCIAL_RELATIONSHIP_MODE\',\'PLAYTHROUGH_SESSION\',\'PLAYTHROUGH_AUTO_SWITCH\')");echo json_encode($r);'))
meta_old=json.loads(php('$r=$GLOBALS["db"]->fetchAll("SELECT key,value FROM stobe_meta.settings WHERE key IN (\'PLAYTHROUGH_SESSION\',\'PLAYTHROUGH_AUTO_SWITCH\')");echo json_encode($r);'))
def meta(k,v):
    php('$GLOBALS["db"]->exec("INSERT INTO stobe_meta.settings(key,value) VALUES($1,$2::jsonb) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value",'+json.dumps([k,v])+');')
def setting(k,v):
    php('$GLOBALS["db"]->exec("INSERT INTO general_settings(id,value) VALUES($1,$2) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value",'+json.dumps([k,v])+');')
def rows():return int(php('echo $GLOBALS["db"]->fetchOne("SELECT COUNT(*) AS n FROM social_event_inbox")["n"];'))
token='a'*32
server=None
try:
    meta('PLAYTHROUGH_AUTO_SWITCH','true')
    meta('PLAYTHROUGH_SESSION',json.dumps(dict(status='ready',token=token,character_id='httpcampaign',load_id=1,client_id='httpsession')))
    event=json.loads(subprocess.check_output(['php','-r','ob_start();require "tests/social_relationship_unit.php";ob_end_clean();echo json_encode(fixture(1));'],cwd=ROOT,text=True))
    event.update(campaign_id='httpcampaign',native_session_id='httpsession',event_id='http1',incident_id='http1',event_kind='combat')
    php('$GLOBALS["db"]->exec("DELETE FROM social_event_inbox WHERE campaign_id=\'httpcampaign\'");')
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    with open(ROOT/'tests/social_http_server.log','w') as log:
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(ROOT)],cwd=ROOT,stdout=log,stderr=log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.05)
        else:raise RuntimeError('Private server startup failed')
        n=0
        def request(payload=event,receipt=token,method='POST'):
            body=payload if isinstance(payload,bytes) else json.dumps(payload).encode()
            req=urllib.request.Request(f'http://127.0.0.1:{port}/social_event.php',data=body,method=method,headers={'Content-Type':'application/json','X-STOBE-PLAYTHROUGH':receipt})
            try:
                with urllib.request.urlopen(req,timeout=10) as r:return r.status,json.loads(r.read())
            except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
        def check(ok,label):
            global n
            assert ok,label
            n+=1
        setting('SOCIAL_RELATIONSHIP_MODE','off');before=rows()
        check(request()[1]['status']=='disabled' and rows()==before,'off has no capture')
        setting('SOCIAL_RELATIONSHIP_MODE','shadow')
        check(request(receipt='bad')[0]==409,'bad receipt')
        check(request()[1]['status']=='captured','valid capture')
        check(request()[1]['status']=='duplicate','duplicate retry')
        changed=dict(event,facts={'message':'changed'})
        check(request(changed)[0]==409,'conflicting ID')
        check(request(dict(event,timeline_epoch='2'))[0]==409,'stale load')
        check(request(dict(event,native_session_id='wrong'))[0]==409,'wrong native session')
        check(request(dict(event,event_kind='semantic'))[0]==422,'client semantic rejected')
        check(request(b'{')[0]==422,'malformed JSON')
        check(request(b' '*65537)[0]==422,'oversized body')
        check(request(method='PUT')[0]==405,'method rejected')
        check(rows()==before+1,'only one accepted event persisted')
        print(f'{n} HTTP endpoint checks passed')
finally:
    if server is not None:server.terminate();server.wait(timeout=10)
    for k in keys:php('$GLOBALS["db"]->exec("DELETE FROM general_settings WHERE id=$1",'+json.dumps([k])+');')
    for r in old:setting(r['id'],r['value'])

    for k in meta_keys:php('$GLOBALS["db"]->exec("DELETE FROM stobe_meta.settings WHERE key=$1",'+json.dumps([k])+');')
    for r in meta_old:meta(r["key"],r["value"])
