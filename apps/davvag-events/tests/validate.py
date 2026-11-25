from pathlib import Path
import json, re, subprocess
app=Path(__file__).resolve().parents[1]
manifest=json.loads((app/'app.json').read_text(encoding='utf-8-sig'))
checks=0
for name,entry in manifest['components'].items():
    folder=app/entry['location']/name
    desc=json.loads((folder/'component.json').read_text(encoding='utf-8-sig'))
    for kind in ['files','js','css']:
        for resource in desc['resources'].get(kind,[]):
            assert (folder/resource['location']).is_file(),(name,resource)
            checks+=1
    if entry['type']=='service':
        source=(folder/desc['serviceHandler']['file']).read_text(encoding='utf-8-sig')
        cls=desc['serviceHandler']['class'].split('\\')[-1]
        assert re.findall(r'^final class ([A-Za-z_][A-Za-z0-9_]*)\b',source,re.M)==[cls], name
        assert 'namespace davvag_events;' in source
        block=source
        for method,settings in desc['serviceHandler']['methods'].items():
            phpname=settings['method'].lower()+method
            assert re.search(r'public function '+phpname+r'\(',block),phpname
            if settings['method']=='GET':assert 'parameters' in settings
            checks+=1
for path in app.rglob('*.json'):json.loads(path.read_text(encoding='utf-8-sig'));checks+=1
for path in app.rglob('*.js'):subprocess.run(['node','--check',str(path)],check=True,capture_output=True);checks+=1
for path in app.rglob('*.php'):subprocess.run(['C:/xampp/php/php.exe','-d','xdebug.mode=off','-l',str(path)],check=True,capture_output=True);checks+=1
assert (app/'assets'/manifest['description']['icon']).is_file()
assert set(manifest['dependencies'])=={'apps','schemas','workflows','plugins','php-extensions'}
print(f'{checks} descriptor, resource, JSON, PHP, and JavaScript checks passed.')
