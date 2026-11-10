<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Contadores atómicos persistentes. No contienen texto, usuarios ni respuestas. */
class AyudaIaCuota
{
    public function __construct(
        private Connection $conexion,
        private int $limiteInstitutoDia,
        private int $limiteGlobalDia,
        private int $limiteGlobalMinuto
    ) {}

    /** Devuelve segundos para reintentar, o cero si reservó una llamada. */
    public function consumir(int $institutoId, ?int $ahora = null): int
    {
        $ahora = $ahora ?? time();
        $dia = intdiv($ahora, 86400);
        $minuto = intdiv($ahora, 60);
        // Orden fijo para evitar interbloqueos entre instituciones concurrentes.
        $ventanas = [
            ['global-dia-' . $dia, $this->limiteGlobalDia, ($dia + 1) * 86400],
            ['global-minuto-' . $minuto, $this->limiteGlobalMinuto, ($minuto + 1) * 60],
            ['instituto-' . $institutoId . '-dia-' . $dia, $this->limiteInstitutoDia, ($dia + 1) * 86400],
        ];
        $this->conexion->beginTransaction();
        try {
            foreach ($ventanas as [$clave, $limite, $vence]) {
                $this->conexion->executeStatement('INSERT IGNORE INTO ayuda_ia_cuota (clave, usos, vence) VALUES (?, 0, ?)', [$clave, $vence]);
                $cambio = $this->conexion->executeStatement('UPDATE ayuda_ia_cuota SET usos = usos + 1 WHERE clave = ? AND usos < ?', [$clave, max(0, $limite)]);
                if ($cambio !== 1) {
                    $this->conexion->rollBack();
                    return max(1, $vence - $ahora);
                }
            }
            $this->conexion->commit();
        } catch (\Throwable $error) {
            $this->conexion->rollBack();
            throw $error;
        }
        return 0;
    }
}
