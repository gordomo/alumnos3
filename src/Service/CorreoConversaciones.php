<?php
namespace App\Service;
use App\Entity\SolicitudPaginaPublica;

class CorreoConversaciones
{
    public function __construct(private bool $activo, private string $vertical) {}
    public function isActivo(): bool { return $this->activo; }
    public function casilla(): string { return ($this->vertical==='club'?'comunicacion_clubes':'comunicacion_institutos').'@teambuilder.com.ar'; }
    public function direccionRespuesta(SolicitudPaginaPublica $solicitud): string
    {
        if (!$solicitud->getCorreoToken()) { throw new \LogicException('La conversación necesita un identificador antes del envío.'); }
        return str_replace('@','+'.$solicitud->getCorreoToken().'@',$this->casilla());
    }
}
