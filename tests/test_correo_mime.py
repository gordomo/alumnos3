import sys
sys.dont_write_bytecode=True
import importlib.util
import unittest
from pathlib import Path
from email.message import EmailMessage
spec=importlib.util.spec_from_file_location('colector',Path(__file__).resolve().parents[1]/'scripts/recibir_correo_conversaciones.py')
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
class CorreoMIME(unittest.TestCase):
 def mensaje(self):
  m=EmailMessage();m['From']='María <persona@example.test>';m['To']='comunicacion_institutos+'+'a'*32+'@teambuilder.com.ar';m['Subject']='Consulta de inglés';m['Message-ID']='<nuevo@example.test>';m['In-Reply-To']='<tb-anterior@teambuilder.com.ar>';return m
 def test_texto_y_correlacion(self):
  m=self.mensaje();m.set_content('Muchas gracias.\n\nEl martes escribió:\n> Texto anterior');r=mod.parsear(m.as_bytes(),'comunicacion_institutos@teambuilder.com.ar')
  self.assertEqual(r['remitente'],'persona@example.test');self.assertEqual(r['tokens'],['a'*32]);self.assertEqual(r['referencias'],['tb-anterior@teambuilder.com.ar']);self.assertEqual(r['mensaje'],'Muchas gracias.');self.assertFalse(r['automatico'])
 def test_html_y_adjuntos(self):
  m=self.mensaje();m.set_content('<html><head><style>oculto</style></head><body><p>Hola &amp; gracias</p><script>alert(1)</script><img src="https://rastreador.example/pixel"></body></html>',subtype='html');m.add_attachment(b'ejemplo',maintype='application',subtype='pdf',filename='programa.pdf');r=mod.parsear(m.as_bytes(),'comunicacion_institutos@teambuilder.com.ar')
  self.assertEqual(r['mensaje'],'Hola & gracias');self.assertEqual(r['adjuntos'],['programa.pdf']);self.assertNotIn('rastreador',r['mensaje']);self.assertNotIn('alert',r['mensaje'])
 def test_otro_producto_no_tiene_token(self):
  m=self.mensaje();m.set_content('Hola');r=mod.parsear(m.as_bytes(),'comunicacion_clubes@teambuilder.com.ar');self.assertEqual(r['tokens'],[])
 def test_automaticos(self):
  m=self.mensaje();m['Auto-Submitted']='auto-replied';m.set_content('Vacaciones');self.assertTrue(mod.parsear(m.as_bytes(),'comunicacion_institutos@teambuilder.com.ar')['automatico'])
 def test_grande(self):
  with self.assertRaises(ValueError):mod.parsear(b'x'*(mod.MAX_MAIL+1),'comunicacion_institutos@teambuilder.com.ar')
if __name__=='__main__':unittest.main()
