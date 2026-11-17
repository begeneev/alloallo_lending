#!/usr/bin/env python3
"""Build the standalone Russian landing from its editable source."""
from pathlib import Path
import json,shutil
root=Path(__file__).resolve().parent.parent
origin=json.loads((root/'site.json').read_text())['origin'].rstrip('/')
(root/'dist').mkdir(exist_ok=True)
page=(root/'src/index.html').read_text().replace('__SITE_ORIGIN__',origin)
(root/'dist/index.html').write_text(page)
for name in ('app.js','styles.css'):
 shutil.copyfile(root/'src'/name,root/'dist'/name)
demo=root/'src'/'demo'
if demo.is_dir():
 shutil.copytree(demo,root/'dist'/'demo',dirs_exist_ok=True)
audio=root/'src'/'audio'
if audio.is_dir():
 shutil.copytree(audio,root/'dist'/'audio',dirs_exist_ok=True)
(root/'dist/robots.txt').write_text('User-agent: *\nAllow: /\nSitemap: '+origin+'/sitemap.xml\n')
(root/'dist/sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'+origin+'/</loc></url></urlset>\n')
print('Built Russian Аллоалло landing.')
