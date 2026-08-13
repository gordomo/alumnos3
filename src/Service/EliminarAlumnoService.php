<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\AsistenciaAlumnos;
use App\Entity\Calificacion;
use App\Entity\DeudaAlumno;
use App\Entity\EmailLog;
use App\Entity\PagoAplicacion;
use App\Entity\SaldoFavor;
use App\Entity\SaldoFavorAplicacion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Borrado de un alumno con todo lo que cuelga de él.
 *
 * Hace falta un servicio para esto porque casi todas las claves foráneas hacia alumno son
 * RESTRICT: solo la tabla de unión alumno_curso cascadea. Antes el controller borraba nada
 * más que las asistencias, así que el flush terminaba en una violación de integridad
 * ("Cannot delete or update a parent row") y el borrado nunca se completaba.
 *
 * El orden importa: se borra de las hojas hacia la raíz, porque las tablas intermedias se
 * referencian entre sí (pago_aplicacion apunta al pago y a la deuda, y alumnos_pagos y
 * deuda_alumno apuntan al histórico de la inscripción).
 *
 * Todo va en una transacción: si algo falla, el alumno queda intacto en lugar de a medio
 * borrar.
 */
class EliminarAlumnoService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Qué cuelga del alumno hoy. Sirve para avisarle al operador antes de borrar, con los
     * mismos números que después se van a borrar.
     *
     * @return array<string, int>
     */
    public function resumenDependencias(Alumno $alumno): array
    {
        $pagoIds = $this->idsDe(AlumnosPagos::class, $alumno);
        $deudaIds = $this->idsDe(DeudaAlumno::class, $alumno);
        $saldoIds = $this->idsDe(SaldoFavor::class, $alumno);
        $historicoIds = $this->idsDe(AlumnoCursoHistorico::class, $alumno);

        return [
            'pagos' => count($pagoIds),
            'deudas' => count($deudaIds),
            'saldosFavor' => count($saldoIds),
            'inscripciones' => count($historicoIds),
            'asistencias' => count($this->idsDe(AsistenciaAlumnos::class, $alumno)),
            'emails' => count($this->idsDe(EmailLog::class, $alumno)),
            'calificaciones' => $historicoIds
                ? $this->contarPor(Calificacion::class, 'cursoHistorico', $historicoIds)
                : 0,
        ];
    }

    /**
     * Borra el alumno y todo lo suyo. Devuelve cuántas filas se borraron de cada cosa.
     *
     * @return array<string, int>
     */
    public function eliminar(Alumno $alumno): array
    {
        $pagoIds = $this->idsDe(AlumnosPagos::class, $alumno);
        $deudaIds = $this->idsDe(DeudaAlumno::class, $alumno);
        $saldoIds = $this->idsDe(SaldoFavor::class, $alumno);
        $historicoIds = $this->idsDe(AlumnoCursoHistorico::class, $alumno);

        $borradas = [];

        $this->entityManager->getConnection()->beginTransaction();

        try {
            // 1. Aplicaciones de pagos: apuntan al pago y a la deuda, así que van primero.
            $borradas['aplicacionesPago'] = $this->borrarPorCampos(PagoAplicacion::class, [
                'pago' => $pagoIds,
                'deuda' => $deudaIds,
            ]);

            // 2. Aplicaciones de saldos a favor: apuntan al saldo y a la deuda.
            $borradas['aplicacionesSaldo'] = $this->borrarPorCampos(SaldoFavorAplicacion::class, [
                'saldoFavor' => $saldoIds,
                'deuda' => $deudaIds,
            ]);

            // 3. Historial de emails. Sus FK a pago y deuda son SET NULL, pero la del alumno
            //    es RESTRICT, así que hay que borrar las filas igual.
            $borradas['emails'] = $this->borrarPorCampos(EmailLog::class, ['alumno' => [$alumno->getId()]]);

            // 4. Calificaciones: cuelgan de la inscripción, no del alumno.
            $borradas['calificaciones'] = $this->borrarPorCampos(Calificacion::class, [
                'cursoHistorico' => $historicoIds,
            ]);

            // 5. Saldos a favor, que además referencian el pago que los originó.
            $borradas['saldosFavor'] = $this->borrarPorCampos(SaldoFavor::class, [
                'alumno' => [$alumno->getId()],
                'pagoOrigen' => $pagoIds,
            ]);

            // 6. Pagos y deudas, que referencian la inscripción.
            $borradas['pagos'] = $this->borrarPorCampos(AlumnosPagos::class, ['alumno' => [$alumno->getId()]]);
            $borradas['deudas'] = $this->borrarPorCampos(DeudaAlumno::class, ['alumno' => [$alumno->getId()]]);

            // 7. Asistencias.
            $borradas['asistencias'] = $this->borrarPorCampos(AsistenciaAlumnos::class, ['alumno' => [$alumno->getId()]]);

            // 8. Las inscripciones históricas, ya sin nada que las referencie.
            $borradas['inscripciones'] = $this->borrarPorCampos(AlumnoCursoHistorico::class, ['alumno' => [$alumno->getId()]]);

            // 9. El alumno. alumno_curso (la tabla de unión) cascadea sola en la base, pero se
            //    limpia la colección para que Doctrine no intente reinsertar la relación.
            foreach ($alumno->getCurso()->toArray() as $curso) {
                $alumno->removeCurso($curso);
            }

            // El usuario de acceso hay que tomarlo antes: la FK vive en alumno.user_id, así que
            // al borrar el alumno se pierde la referencia y el usuario queda pudiendo entrar a
            // un panel sin alumno detrás.
            $usuario = $alumno->getUser();

            $this->entityManager->remove($alumno);
            $this->entityManager->flush();

            $borradas['usuario'] = $usuario && $this->usuarioQuedaHuerfano($usuario) ? 1 : 0;
            if ($borradas['usuario'] === 1) {
                // Las FK de calificacion, evaluacion y email_log hacia user son SET NULL, así
                // que se puede borrar sin perder esas filas: solo se pierde quién lo hizo.
                $this->entityManager->remove($usuario);
                $this->entityManager->flush();
            }

            $this->entityManager->getConnection()->commit();
        } catch (\Throwable $e) {
            $this->entityManager->getConnection()->rollBack();
            throw $e;
        }

        return $borradas;
    }

    /**
     * Si este usuario ya no lo usa nadie más y se puede borrar.
     *
     * Se pregunta en vez de intentar y atajar el error, porque todo esto corre dentro de una
     * transacción: una violación de integridad acá haría rollback del borrado completo.
     */
    private function usuarioQuedaHuerfano(User $usuario): bool
    {
        $referencias = [
            [\App\Entity\Profesor::class, 'user'],
            [\App\Entity\InstitutoAdmin::class, 'user'],
            [\App\Entity\BillingInvoice::class, 'approvedBy'],
        ];

        foreach ($referencias as [$entidad, $campo]) {
            $cantidad = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from($entidad, 'e')
                ->andWhere(sprintf('e.%s = :usuario', $campo))
                ->setParameter('usuario', $usuario)
                ->getQuery()
                ->getSingleScalarResult();

            if ($cantidad > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ids de las filas de una entidad que pertenecen al alumno.
     *
     * @return int[]
     */
    private function idsDe(string $entidad, Alumno $alumno): array
    {
        $filas = $this->entityManager->createQueryBuilder()
            ->select('e.id')
            ->from($entidad, 'e')
            ->andWhere('e.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($filas, 'id'));
    }

    private function contarPor(string $entidad, string $campo, array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($entidad, 'e')
            ->andWhere(sprintf('e.%s IN (:ids)', $campo))
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Borra las filas de una entidad que matcheen cualquiera de los campos dados.
     *
     * Se usa DQL con listas de ids en lugar de recorrer entidades: son varias tablas y el
     * orden es lo que importa, así que conviene que cada paso sea una sola sentencia.
     *
     * @param array<string, int[]> $campos campo => ids aceptados
     */
    private function borrarPorCampos(string $entidad, array $campos): int
    {
        $condiciones = [];
        $parametros = [];

        $i = 0;
        foreach ($campos as $campo => $ids) {
            if (!$ids) {
                continue;
            }
            $clave = 'ids' . $i++;
            $condiciones[] = sprintf('e.%s IN (:%s)', $campo, $clave);
            $parametros[$clave] = $ids;
        }

        if (!$condiciones) {
            return 0;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->delete($entidad, 'e')
            ->where(implode(' OR ', $condiciones));

        foreach ($parametros as $clave => $valor) {
            $qb->setParameter($clave, $valor);
        }

        return (int) $qb->getQuery()->execute();
    }
}
