<?php

namespace App\Service;

use App\Entity\Curso;
use App\Entity\EventoAgenda;
use App\Entity\Instituto;
use App\Repository\EventoAgendaRepository;

/**
 * Qué días de un rango son feriado o receso.
 *
 * Vive aparte porque lo usan dos cosas que tienen que coincidir: la agenda, que tapa la clase de
 * ese día, y la liquidación del profesor, que no se la paga. Si cada una lo calculara por su
 * lado, el calendario podría decir que no hubo clase y la liquidación cobrarla igual.
 */
class FeriadoService
{
    public function __construct(private EventoAgendaRepository $eventoRepository)
    {
    }

    /**
     * Los días que caen dentro de un feriado o receso, indexados por Y-m-d.
     *
     * Un feriado asociado a un curso solo cuenta para ese curso; sin curso es del instituto
     * entero. Pasando $curso se obtienen los que le aplican a ese curso.
     *
     * @return array<string, EventoAgenda>
     */
    public function diasFeriados(
        Instituto $instituto,
        \DateTimeInterface $desde,
        \DateTimeInterface $hasta,
        ?Curso $curso = null
    ): array {
        $dias = [];

        foreach ($this->eventoRepository->findFeriadosEnRango($instituto, $desde, $hasta) as $feriado) {
            if (!$this->aplicaAlCurso($feriado, $curso)) {
                continue;
            }

            $dia = \DateTime::createFromFormat('Y-m-d H:i:s', $feriado->getFechaInicio()->format('Y-m-d') . ' 00:00:00');
            $ultimo = $feriado->getFechaFinEfectiva()->format('Y-m-d');

            // Se recorre desde el inicio real del evento y no desde el inicio del rango: un
            // receso que arrancó antes igual tiene que marcar sus días de este mes.
            while ($dia && $dia->format('Y-m-d') <= $ultimo) {
                $dias[$dia->format('Y-m-d')] = $feriado;
                $dia->modify('+1 day');
            }
        }

        return $dias;
    }

    /**
     * Si un feriado le corresponde a un curso.
     *
     * Sin curso en el evento es del instituto y le aplica a todos. Sin curso en la pregunta
     * (la agenda, que mira varios a la vez) se acepta cualquiera y el llamador decide.
     */
    public function aplicaAlCurso(EventoAgenda $feriado, ?Curso $curso): bool
    {
        if (!$feriado->getCurso() || !$curso) {
            return true;
        }

        return $feriado->getCurso()->getId() === $curso->getId();
    }
}
