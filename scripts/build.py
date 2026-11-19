#!/usr/bin/env python3
"""Build the standalone Russian landing from its editable source."""
from pathlib import Path
import hashlib,json,re,shutil
root=Path(__file__).resolve().parent.parent
origin=json.loads((root/'site.json').read_text())['origin'].rstrip('/')
(root/'dist').mkdir(exist_ok=True)
page=(root/'src/index.html').read_text().replace('__SITE_ORIGIN__',origin)
(root/'dist/index.html').write_text(page)
for name in ('app.js','phone.js','styles.css','legal.css'):
 shutil.copyfile(root/'src'/name,root/'dist'/name)
# Folders copied as is: call page (/call/), server part on PHP (/api/), recordings of the agent (/audio/),
# demo agents page (/demo/, maintained separately, not edited here).
for folder in ('call','api','audio','demo','privacy','policy'):
 src=root/'src'/folder
 if src.is_dir():
  shutil.copytree(src,root/'dist'/folder,dirs_exist_ok=True)
(root/'dist/robots.txt').write_text('User-agent: *\nAllow: /\nDisallow: /call/\nDisallow: /api/\nSitemap: '+origin+'/sitemap.xml\n')
(root/'dist/sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'+origin+'/</loc></url><url><loc>'+origin+'/policy/</loc></url><url><loc>'+origin+'/privacy/</loc></url></urlset>\n')
# Хостинг отдаёт CSS и JS с кэшем на 45 дней: без метки браузер, уже заходивший на сайт, показывает старые стили
# и скрипты со свежей страницей. Ссылки на свои .css/.js получают отпечаток содержимого (?v=…): поменялся файл —
# поменялась ссылка, и браузер берёт новую версию; не поменялся — берёт из кэша.
def versioned(m):
 attr,path=m.group(1),m.group(2)
 f=root/'dist'/path.lstrip('/')
 if not f.is_file(): return m.group(0)
 return attr+'="'+path+'?v='+hashlib.sha256(f.read_bytes()).hexdigest()[:10]+'"'
for html in (root/'dist').rglob('*.html'):
 text=html.read_text()
 new=re.sub(r'\b(href|src)="(/[^"?#]+\.(?:css|js))"',versioned,text)
 if new!=text: html.write_text(new)
print('Built Russian Аллоалло landing.')
