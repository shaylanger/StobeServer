#!/usr/bin/env python3
"""Rebuild private native copy. Does not install DLLs or operate the game."""
import hashlib,json,pathlib,shutil,subprocess
WORK=pathlib.Path('/root/stobe-work/social-phase1')
SRC=WORK/'native-workspace/components/STOBE/src'
BUILD=pathlib.Path('/mnt/c/KenshiModding/isolated/relationships-phase1/build')
assert SRC.is_dir() and (BUILD/'build.bat').is_file(), 'Private build locations required'
for p in SRC.iterdir():
    if p.is_file():shutil.copy2(p,BUILD/'src'/p.name)
with (WORK/'native-build.log').open('w') as log:
    subprocess.run(['cmd.exe','/c',r'C:\KenshiModding\isolated\relationships-phase1\build\build.bat'],stdout=log,stderr=log,check=True,timeout=600)
artifact=BUILD/'out/Stobe.dll'
manifest={'deployment':'private_uninstalled','dll_sha256':hashlib.sha256(artifact.read_bytes()).hexdigest(),'native_head':subprocess.check_output(['git','-C',str(WORK/'native-workspace'),'rev-parse','HEAD'],text=True).strip(),'sources':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(SRC.iterdir()) if p.is_file()},'build_script_sha256':hashlib.sha256((BUILD/'build.bat').read_bytes()).hexdigest()}
(BUILD/'build_manifest.json').write_text(json.dumps(manifest,indent=2))
print('Private DLL built; SHA256 '+manifest['dll_sha256'])
