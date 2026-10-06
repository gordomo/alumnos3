<?php

namespace App\Service;

use App\Entity\Instituto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Borra un instituto y todo lo que cuelga de él, para siempre.
 *
 * El borrado que había antes era un `remove()` pelado sobre la entidad, y fallaba con cualquier
 * instituto -incluso uno vacío, porque siempre tiene al menos un usuario-: MySQL rechaza el
 * primer hijo que encuentra. Hay 24 tablas que apuntan al instituto y otras tantas que cuelgan
 * de sus alumnos, cursos y profesores.
 *
 * Se borra con SQL en orden de hoja a raíz y dentro de una transacción: o se va todo, o no se
 * toca nada. Con el ORM habría que hidratar decenas de miles de entidades para lograr lo mismo.
 *
 * Esto es para limpiar datos de prueba. Para un cliente que se va está la baja lógica, que
 * conserva el historial.
 */
class EliminarInstitutoService
{
    /**
     * El orden importa: cada tabla se borra antes que aquellas a las que apunta.
     *
     * Cada entrada es la tabla y el WHERE que la acota al instituto. Las que no tienen
     * instituto_id se alcanzan por su padre.
     */
    private const ORDEN = [
        // Lo que cuelga de pagos, deudas y saldos
        ['saldo_favor_aplicacion', 'saldo_favor_id IN (SELECT id FROM saldo_favor WHERE instituto_id = :id)'],
        ['pago_aplicacion', 'pago_id IN (SELECT p.id FROM alumnos_pagos p JOIN alumno a ON a.id = p.alumno_id WHERE a.instituto_id = :id)'],
        ['email_log', 'instituto_id = :id'],
        ['saldo_favor', 'instituto_id = :id'],
        ['alumnos_pagos', 'alumno_id IN (SELECT id FROM alumno WHERE instituto_id = :id)'],
        ['deuda_alumno', 'instituto_id = :id'],

        // Académico
        ['calificacion', 'instituto_id = :id'],
        ['evaluacion', 'instituto_id = :id'],
        ['tarea_entrega', 'instituto_id = :id'],
        ['tarea', 'instituto_id = :id'],
        ['asistencia_alumnos', 'alumno_id IN (SELECT id FROM alumno WHERE instituto_id = :id)'],
        ['asistencia_profesores', 'profesor_id IN (SELECT id FROM profesor WHERE instituto_id = :id)'],
        ['clase_dictada', 'instituto_id = :id'],
        ['material_curso', 'instituto_id = :id'],

        // Inscripciones y cursos
        ['alumno_curso', 'alumno_id IN (SELECT id FROM alumno WHERE instituto_id = :id)'],
        ['alumno_curso_historico', 'alumno_id IN (SELECT id FROM alumno WHERE instituto_id = :id)'],
        ['curso_horario', 'curso_id IN (SELECT id FROM curso WHERE instituto_id = :id)'],
        ['curso_profesor', 'curso_id IN (SELECT id FROM curso WHERE instituto_id = :id)'],

        // Profesores y su liquidación
        ['profesor_pago', 'profesor_id IN (SELECT id FROM profesor WHERE instituto_id = :id)'],
        ['profesor_curso_pago', 'instituto_id = :id'],

        // Agenda, facturación y configuración
        ['evento_agenda', 'instituto_id = :id'],
        ['billing_invoice', 'instituto_id = :id'],
        ['vencimiento', 'instituto_id = :id'],
        ['descuento_promocional', 'configuracion_id IN (SELECT id FROM instituto_configuracion WHERE instituto_id = :id)'],
        ['concepto_calificacion', 'instituto_id = :id'],
        ['area_evaluacion', 'instituto_id = :id'],
        ['periodo_academico', 'instituto_id = :id'],
        ['metodo_pago', 'instituto_id = :id'],

        // Y recién ahora las tablas principales
        ['alumno', 'instituto_id = :id'],
        ['curso', 'instituto_id = :id'],
        ['profesor', 'instituto_id = :id'],
        ['instituto_admin', 'instituto_id = :id'],
        ['instituto_configuracion', 'instituto_id = :id'],
        ['user', 'instituto_id = :id'],
        ['instituto', 'id = :id'],
    ];

    /**
     * Lo que se le muestra a quien va a borrar, para que sepa qué se lleva puesto.
     *
     * Son las cifras que le importan a una persona, no las 35 tablas.
     */
    private const RESUMEN = [
        'alumn@s' => 'SELECT COUNT(*) FROM alumno WHERE instituto_id = :id',
        'cursos' => 'SELECT COUNT(*) FROM curso WHERE instituto_id = :id',
        'profesor@s' => 'SELECT COUNT(*) FROM profesor WHERE instituto_id = :id',
        'usuari@s' => 'SELECT COUNT(*) FROM user WHERE instituto_id = :id',
        'pagos registrados' => 'SELECT COUNT(*) FROM alumnos_pagos p JOIN alumno a ON a.id = p.alumno_id WHERE a.instituto_id = :id',
        'notas cargadas' => 'SELECT COUNT(*) FROM calificacion WHERE instituto_id = :id',
        'asistencias tomadas' => 'SELECT COUNT(*) FROM asistencia_alumnos aa JOIN alumno a ON a.id = aa.alumno_id WHERE a.instituto_id = :id',
        'facturas' => 'SELECT COUNT(*) FROM billing_invoice WHERE instituto_id = :id',
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Cuántos registros de cada cosa se van a borrar. Solo devuelve lo que tiene algo.
     *
     * @return array<string, int>
     */
    public function resumen(Instituto $instituto): array
    {
        $conexion = $this->em->getConnection();
        $resumen = [];

        foreach (self::RESUMEN as $etiqueta => $sql) {
            $cantidad = (int) $conexion->fetchOne($sql, ['id' => $instituto->getId()]);
            if ($cantidad > 0) {
                $resumen[$etiqueta] = $cantidad;
            }
        }

        return $resumen;
    }

    /**
     * Borra el instituto entero. No se puede deshacer.
     *
     * Las solicitudes de alta no se borran: su clave es ON DELETE SET NULL, así que la solicitud
     * queda como registro de que ese instituto existió y quién lo pidió.
     *
     * @throws \Throwable si algo falla; en ese caso no se borró nada
     */
    public function eliminar(Instituto $instituto): void
    {
        $conexion = $this->em->getConnection();
        $id = $instituto->getId();

        $conexion->beginTransaction();

        try {
            foreach (self::ORDEN as [$tabla, $where]) {
                $conexion->executeStatement(
                    sprintf('DELETE FROM `%s` WHERE %s', $tabla, $where),
                    ['id' => $id]
                );
            }

            $conexion->commit();
        } catch (\Throwable $e) {
            $conexion->rollBack();

            throw $e;
        }

        // El instituto sigue en el mapa de identidad de Doctrine aunque ya no exista en la base.
        $this->em->clear();
    }
}
