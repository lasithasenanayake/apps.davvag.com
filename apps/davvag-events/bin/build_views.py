from pathlib import Path
import json
app=Path(__file__).resolve().parents[1]
manifest=json.loads((app/'app.json').read_text(encoding='utf-8'))
source=(app/'ui.html').read_text(encoding='utf-8')
count=0
for name,entry in manifest['components'].items():
    if entry['type']=='component' and name.endswith('-view'):
        (app/entry['location']/name/'partial.html').write_text(source,encoding='utf-8')
        count+=1
print(f'Updated {count} route views from ui.html.')
