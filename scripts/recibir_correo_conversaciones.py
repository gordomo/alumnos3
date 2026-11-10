#!/usr/bin/env python3
"""Recolector de las dos casillas locales. Instalar una copia root fuera del webroot.
No borra mensajes ni ejecuta contenido del correo. Solo biblioteca estándar.
"""
import argparse
import base64
import email
import email.policy
import json
import mailbox
import os
import re
import sqlite3
import subprocess
from email.utils import getaddresses
from html.parser import HTMLParser
from pathlib import Path
MAX_MAIL = 5 * 1024 * 1024

class TextoHTML(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.texto, self.ocultos = [], 0
    def handle_starttag(self, tag, attrs):
        if tag in ('script','style','head','svg'):
            self.ocultos += 1
        if not self.ocultos and tag in ('p','div','br','li','tr','blockquote'):
            self.texto.append('\n')
    def handle_endtag(self, tag):
        if tag in ('script','style','head','svg') and self.ocultos:
            self.ocultos -= 1
        if not self.ocultos and tag in ('p','div','li','tr','blockquote'):
            self.texto.append('\n')
    def handle_data(self, data):
        if not self.ocultos:
            self.texto.append(data)

def quitar_cita(texto):
    lineas=[]
    for linea in texto.replace('\r\n','\n').replace('\r','\n').split('\n'):
        if ''.join(lineas).strip() and re.match(r'^\s*(On .+wrote:|El .+escribi[oó]:|-+\s*(Original Message|Mensaje original)\s*-+)',linea,re.I):
            break
        if not linea.lstrip().startswith('>'):
            lineas.append(linea)
    return '\n'.join(lineas).strip() or texto.strip()

def parsear(raw, casilla):
    if len(raw)>MAX_MAIL:
        raise ValueError('correo_demasiado_extenso')
    msg=email.message_from_bytes(raw,policy=email.policy.default)
    froms=getaddresses([str(h) for h in msg.get_all('From',[])])
    remitente=froms[0][1].strip().lower() if len(froms)==1 else ''
    automatico=(str(msg.get('Auto-Submitted','no')).strip().lower()!='no'
                or str(msg.get('Precedence','')).strip().lower() in ('bulk','junk','list')
                or msg.get_content_type()=='multipart/report'
                or str(msg.get('Return-Path','')).strip()=='<>')
    destinos=[]
    for campo in ('Delivered-To','X-Original-To','Envelope-To','To','Cc'):
        destinos.extend(a.lower() for _,a in getaddresses([str(h) for h in msg.get_all(campo,[])]))
    local,dominio=casilla.lower().split('@')
    patron=re.compile(re.escape(local)+r'\+([a-f0-9]{32})@'+re.escape(dominio)+'$')
    tokens=sorted({m.group(1) for a in destinos if (m:=patron.fullmatch(a))})
    referencias=[]
    for campo in ('In-Reply-To','References'):
        for valor in msg.get_all(campo,[]):
            referencias.extend(re.findall(r'<([^<>\s]{1,180})>',str(valor)))
    ids=re.findall(r'<([^<>\s]{1,255})>',str(msg.get('Message-ID','')))
    body=msg.get_body(preferencelist=('plain','html'));texto=''
    if body is not None:
        try:
            texto=body.get_content()
        except (LookupError,UnicodeError):
            texto=(body.get_payload(decode=True) or b'').decode('utf-8',errors='replace')
        if not isinstance(texto,str):
            texto=''
        if body.get_content_type()=='text/html':
            html=TextoHTML();html.feed(texto);texto=''.join(html.texto)
    adjuntos=[str(p.get_filename() or 'Archivo adjunto')[:180] for p in msg.walk()
              if p.get_content_disposition()=='attachment' or p.get_filename()]
    return dict(casilla=casilla,remitente=remitente,automatico=automatico,tokens=tokens,
                referencias=list(dict.fromkeys(referencias))[-50:],message_id=ids[0] if len(ids)==1 else '',
                asunto=str(msg.get('Subject',''))[:255],mensaje=quitar_cita(texto)[:51000],
                adjuntos=adjuntos[:30],original=base64.b64encode(raw).decode('ascii'))

def recoger(config):
    casilla=config['casilla']
    sitios={'comunicacion_institutos@teambuilder.com.ar':'/www/wwwroot/innovateglobal.es',
            'comunicacion_clubes@teambuilder.com.ar':'/www/wwwroot/clubes.teambuilder.com.ar'}
    if casilla not in sitios:
        raise ValueError('casilla_no_permitida')
    ruta=Path(config['maildir']).resolve();sitio=Path(config['sitio']).resolve()
    if ruta!=Path('/www/vmail/teambuilder.com.ar')/casilla.split('@')[0] or not ruta.is_dir():
        raise ValueError('maildir_no_permitido')
    if str(sitio)!=sitios[casilla]:
        raise ValueError('sitio_no_permitido')
    db=sqlite3.connect(config['estado'])
    db.execute('CREATE TABLE IF NOT EXISTS procesados (clave TEXT PRIMARY KEY, estado TEXT NOT NULL, motivo TEXT, fecha TEXT DEFAULT CURRENT_TIMESTAMP)')
    buzon=mailbox.Maildir(str(ruta),create=False)
    resumen=dict(casilla=casilla,importados=0,ignorados=0,duplicados=0,errores=0)
    pendientes=[k for k in sorted(buzon.keys()) if not db.execute('SELECT 1 FROM procesados WHERE clave=?',(k,)).fetchone()]
    for clave in pendientes[:50]:
        try:
            with buzon.get_file(clave) as f:
                raw=f.read(MAX_MAIL+1)
            if len(raw)>MAX_MAIL:
                resultado=dict(estado='ignorado',motivo='correo_demasiado_extenso')
            else:
                try:
                    datos=parsear(raw,casilla)
                except (ValueError,RecursionError,TypeError,AttributeError):
                    datos=None
                    resultado=dict(estado='ignorado',motivo='mime_invalido')
                if datos is not None:
                    contenedor='clubesteambuildercomar-app-1' if casilla.startswith('comunicacion_clubes@') else 'innovateglobales-app-1'
                    proceso=subprocess.run(['docker','exec','-i','--user','www-data','--workdir','/var/www/html',contenedor,'php','bin/console','app:correo:importar','--no-interaction'],cwd='/',input=json.dumps(datos,ensure_ascii=False).encode('utf-8',errors='replace'),stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=45,check=False)
                    if proceso.returncode!=0:
                        raise RuntimeError('importador_no_disponible')
                    resultado=json.loads(proceso.stdout.decode('utf-8'))
                    if resultado.get('estado') not in ('importado','ignorado','duplicado'):
                        raise RuntimeError('respuesta_no_terminal')
            db.execute('INSERT OR IGNORE INTO procesados (clave,estado,motivo) VALUES (?,?,?)',(clave,resultado['estado'],resultado.get('motivo')));db.commit()
            resumen[{'importado':'importados','ignorado':'ignorados','duplicado':'duplicados'}[resultado['estado']]]+=1
        except (OSError,RuntimeError,ValueError,subprocess.TimeoutExpired):
            resumen['errores']+=1
            break
    buzon.close();db.close()
    return resumen

def main():
    os.umask(0o077)
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--config',required=True);args=parser.parse_args()
    fallo=False
    for config in json.loads(Path(args.config).read_text()):
        resultado=recoger(config)
        if any(resultado[c] for c in ('importados','ignorados','duplicados','errores')):
            print(json.dumps(resultado,ensure_ascii=False),flush=True)
        fallo=fallo or bool(resultado['errores'])
    return 1 if fallo else 0
if __name__=='__main__':
    raise SystemExit(main())
