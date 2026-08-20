<?php

namespace App\Service;

use App\Entity\Curso;
use App\Entity\Profesor;
use App\Entity\ProfesorCursoPago;
use App\Repository\ProfesorCursoPagoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Guarda las reglas de pago por curso de un profesor desde la grilla.
 *
 * Es un upsert con los cursos del profesor como lista blanca: lo que llegue de un curso que no
 * es suyo se descarta, así que no alcanza con agregar un input desde el navegador.
 */
class ProfesorCursoPagoService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProfesorCursoPagoRepository $reglaRepository
    ) {
    }

    /**
     * @param Curso[] $cursos cursos del profesor, la lista blanca
     * @param array<int|string, mixed> $modalidades por id de curso
     * @param array<int|string, mixed> $valores por id de curso: precio_hora, viatico, porcentaje, monto_fijo
     * @param array<int|string, mixed> $activas por id de curso, presente si la regla está prendida
     *
     * @return array{guardadas: int, apagadas: int, incompletas: string[]}
     */
    public function guardarReglas(Profesor $profesor, array $cursos, array $modalidades, array $valores, array $activas): array
    {
        $existentes = $this->reglaRepository->findByProfesorIndexadoPorCurso($profesor);

        $guardadas = 0;
        $apagadas = 0;
        $incompletas = [];

        foreach ($cursos as $curso) {
            $cursoId = $curso->getId();
            $regla = $existentes[$cursoId] ?? null;
            $prendida = !empty($activas[$cursoId]);

            if (!$prendida) {
                // Sin regla propia el curso vuelve a liquidarse con la config del profesor. La
                // regla no se borra: queda el registro de lo que se había acordado.
                if ($regla && $regla->isActivo()) {
                    $regla->setActivo(false);
                    $regla->setUpdatedAt(new \DateTime());
                    $apagadas++;
                }

                continue;
            }

            if (!$regla) {
                $regla = new ProfesorCursoPago();
                $regla->setInstituto($profesor->getInstituto());
                $regla->setProfesor($profesor);
                $regla->setCurso($curso);
                $this->entityManager->persist($regla);
            }

            $modalidad = (string) ($modalidades[$cursoId] ?? ProfesorCursoPago::MODALIDAD_POR_HORA);
            if (!array_key_exists($modalidad, ProfesorCursoPago::MODALIDADES)) {
                $modalidad = ProfesorCursoPago::MODALIDAD_POR_HORA;
            }

            $delCurso = $valores[$cursoId] ?? [];

            $regla->setActivo(true);
            $regla->setModalidad($modalidad);
            $regla->setPrecioHora($this->numeroONull($delCurso['precio_hora'] ?? null));
            $regla->setViatico($this->numeroONull($delCurso['viatico'] ?? null));
            $regla->setPorcentaje($this->numeroONull($delCurso['porcentaje'] ?? null));
            $regla->setMontoFijo($this->numeroONull($delCurso['monto_fijo'] ?? null));
            $regla->setUpdatedAt(new \DateTime());

            if (!$regla->estaCompleta()) {
                $incompletas[] = $curso->getNombre();
            }

            $guardadas++;
        }

        $this->entityManager->flush();

        return [
            'guardadas' => $guardadas,
            'apagadas' => $apagadas,
            'incompletas' => $incompletas,
        ];
    }

    /**
     * Un campo vacío queda en null y no en cero: null significa "usá el valor del profesor",
     * mientras que cero significa "acá no se paga nada".
     */
    private function numeroONull($valor): ?string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }

        return number_format((float) $valor, 2, '.', '');
    }
}
