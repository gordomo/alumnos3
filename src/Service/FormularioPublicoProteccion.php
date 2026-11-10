<?php
namespace App\Service;

/** Formularios firmados sin abrir sesiones públicas. La firma no contiene datos personales. */
class FormularioPublicoProteccion
{
    public function __construct(private string $secret) {}
    public function emitir(string $slug, string $tipo, ?int $ahora = null): string
    {
        $datos=($ahora ?? time()).'.'.bin2hex(random_bytes(16));
        return $datos.'.'.hash_hmac('sha256',$slug.'|'.$tipo.'|'.$datos,$this->secret);
    }
    public function validar(string $token, string $slug, string $tipo, ?int $ahora = null): bool
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D',$token,$m)) { return false; }
        $edad=($ahora ?? time())-(int)$m[1];
        return $edad >= 2 && $edad <= 7200 && hash_equals(hash_hmac('sha256',$slug.'|'.$tipo.'|'.$m[1].'.'.$m[2],$this->secret),$m[3]);
    }
    public function huella(string $valor): string { return hash_hmac('sha256',$valor,$this->secret); }
    public function contexto(string $slug, bool $preview = false): array
    {
        return ['slug'=>$slug,'preview'=>$preview,'tokens'=>['contacto'=>$this->emitir($slug,'contacto'),'preinscripcion'=>$this->emitir($slug,'preinscripcion')],'errores'=>[],'valores'=>[]];
    }
}
