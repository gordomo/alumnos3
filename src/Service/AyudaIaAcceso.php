<?php

namespace App\Service;

use App\Entity\Instituto;

/** La prueba gratuita empieza cerrada: solo instituciones habilitadas explícitamente. */
class AyudaIaAcceso
{
    public function __construct(private bool $habilitada, private bool $soloPruebas, private GeminiAyudaCliente $cliente) {}

    public function estado(?Instituto $instituto): array
    {
        $permitido = $instituto !== null && $instituto->isActivo()
            && ($instituto->getPlan() === 'premium' || $instituto->isAyudaIaPrueba());
        if (!$permitido) {
            return ['disponible' => false, 'motivo' => 'plan'];
        }
        if ($this->soloPruebas && !$instituto->isAyudaIaPrueba()) {
            return ['disponible' => false, 'motivo' => 'prueba'];
        }
        return [
            'disponible' => $this->habilitada && $this->cliente->configurado(),
            'motivo' => $this->habilitada && $this->cliente->configurado() ? null : 'configuracion',
        ];
    }
}
