#!/usr/bin/env python3
"""Run private Phase 1 checks, saving atomic progress and failure logs.
No install, game launch, live DB, network service, or shared harness operations.
"""
import hashlib,json,os,pathlib,subprocess,sys,time
ROOT=pathlib.Path(__file__).resolve().parents[1]
EXPECTED=pathlib.Path('/root/stobe-work/social-phase1/server')
if ROOT!=EXPECTED or os.environ.get('STOBE_DB_NAME')!='stobe_social_phase1_test':
    raise SystemExit('Run only from the isolated tree with its dedicated database')
OUT=ROOT.parent/'test-results';OUT.mkdir(exist_ok=True)
os.makedirs('/tmp/negtest',exist_ok=True)
for _f in ('stobe.log','kfp.log'):open('/tmp/negtest/'+_f,'a').close()
def php(code):return ['php','-r',code]
def suite(name):return php('try { require "tests/'+name+'.php"; } catch(Throwable $e) { fwrite(STDERR,get_class($e).": ".$e->getMessage()." at ".$e->getFile().":".$e->getLine()."\\n"); exit(1); }')
def mode_off():
    subprocess.run(php('require "lib/bootstrap.php";$GLOBALS["db"]->exec("UPDATE general_settings SET value=\'off\' WHERE id=\'SOCIAL_RELATIONSHIP_MODE\'");'),cwd=ROOT,check=True,timeout=30)
files=list((ROOT/'tests/social_relationship').glob('*'))+list((ROOT/'lib').glob('social_*.php'))+list((ROOT/'data').glob('social_*'))+list((ROOT/'tests').glob('social_*'))+[ROOT/'social_event.php']
hashers=hashlib.sha256()
server_head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
native_head=subprocess.check_output(['git','-C',str(ROOT.parent/'native-workspace'),'rev-parse','HEAD'],text=True).strip()
hashers.update(server_head.encode()+subprocess.check_output(['git','diff','--binary','HEAD'],cwd=ROOT))
for f in sorted(files):
    if f.is_file() and f.suffix in ('.php','.json','.sql','.py'):hashers.update(str(f.relative_to(ROOT)).encode()+f.read_bytes())
manifest={'started_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'source_hash':hashers.hexdigest(),'server_head':server_head,'native_head':native_head,'database':os.environ['STOBE_DB_NAME'],'deployment':'isolated','game_tests':'not_run','steps':[]}
def save():
    p=OUT/'manifest.tmp';p.write_text(json.dumps(manifest,indent=2));p.replace(OUT/'manifest.json')
steps=[('scenario_manifests',[sys.executable,'tests/social_relationship/validate_scenarios.py']),('social_unit',suite('social_relationship_unit')),('social_integration',suite('social_relationship_integration')),('social_save_migration',suite('social_playthrough_regression')),('social_concurrency',suite('social_concurrency_regression')),('social_scope',suite('social_scope_regression')),('social_combat',suite('social_combat_regression')),('social_unconscious',suite('social_unconscious_regression')),('social_care',suite('social_care_regression')),('social_property',suite('social_property_regression')),('social_witness',suite('social_witness_regression')),('social_recruitment',suite('social_recruitment_regression')),('legacy_negotiation',['env','STOBE_NEG_TEST_NO_SIGNAL=1','STOBE_NEG_STOBE_LOG=/tmp/negtest/stobe.log','STOBE_NEG_KFP_LOG=/tmp/negtest/kfp.log','php','tests/negotiation_engine_regression.php']),('mutation_proof',['bash','tests/social_relationship/mutation_proof.sh']),('social_http',[sys.executable,'tests/social_http_regression.py']),('inspect_tool',['php','tools/social_relationship_inspect.php','--events','5','--effects','5']),('legacy_relationship',suite('relationship_system_regression')),('legacy_stance',suite('relationship_stance_regression')),('legacy_rollback',suite('relationship_rollback_regression')),('native_rebuild',['cmake','--build',str(ROOT.parent/'build-tests')]),('native_portable',['ctest','--test-dir',str(ROOT.parent/'build-tests'),'--output-on-failure']),('native_emit',[str(ROOT.parent/'build-tests/social_protocol_tests'),'--emit']),('native_emit_structured',[str(ROOT.parent/'build-tests/social_protocol_tests'),'--emit-structured']),('native_php_contract',suite('social_relationship_unit')+[str(OUT/'native_emit.log'),str(OUT/'native_emit_structured.log')])]
try:
    for name,cmd in steps:
        if name.startswith('legacy'):mode_off()
        row={'name':name,'status':'running'};manifest['steps'].append(row);save()
        start=time.monotonic()
        result=subprocess.run(cmd,cwd=ROOT,capture_output=True,text=True,timeout=600)
        (OUT/(name+'.log')).write_text(result.stdout+result.stderr)
        row.update(status='passed' if result.returncode==0 else 'failed',exit_code=result.returncode,seconds=round(time.monotonic()-start,3),log=name+'.log');save()
        print(name+': '+row['status'],flush=True)
        if result.returncode:raise RuntimeError(name+' failed; see '+str(OUT/(name+'.log')))
    artifact=pathlib.Path('/mnt/c/KenshiModding/isolated/relationships-phase1/build/out/Stobe.dll')
    if not artifact.is_file():raise RuntimeError('Private DLL missing')
    manifest['private_dll_sha256']=hashlib.sha256(artifact.read_bytes()).hexdigest()
    manifest['status']='passed';save()
finally:
    mode_off()
    if manifest.get('status')!='passed':manifest['status']='failed';save()
