<?php

namespace App\Service;

use App\Entity\Curso;
use App\Entity\InstitutoConfiguracion;
use App\Entity\Profesor;
use App\Entity\ProfesorCursoPago;
use App\Repository\AsistenciaProfesoresRepository;
use App\Repository\ProfesorCursoPagoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cuánto hay que pagarle a un profesor por un mes.
 *
 * Hay dos niveles de configuración. La del profesor (tipoPago, precioHora, montoFijoMensual,
 * porcentajeCurso) es la que se aplica por default a todos sus cursos, y es lo único que
 * existía. Encima de eso, cada curso puede tener su propia regla en ProfesorCursoPago: así el
 * mismo profesor cobra por hora en un curso y un porcentaje en otro, que es lo que el cliente
 * llama "combinación de varias opciones".
 *
 * Un instituto que no cargue ninguna regla por curso sigue liquidando por las cuatro
 * modalidades del profesor, con dos diferencias deliberadas respecto de la versión anterior:
 *
 * - Las horas se cuentan horario por horario. Antes se multiplicaba la cantidad de clases por
 *   Curso::getDuracion(), que devuelve la duración del primer horario, así que un curso con
 *   lunes de una hora y miércoles de dos liquidaba mal.
 * - El viático se paga por clase dictada. Antes se sumaba una sola vez por curso y por mes,
 *   sin importar cuántas clases hubiera. Esto sube lo que se le paga a un profesor con viático
 *   cargado, así que conviene revisarlo antes de la primera liquidación.
 */
class ProfesorPagoService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AsistenciaProfesoresRepository $asistenciaProfesoresRepository,
        private ProfesorCursoPagoRepository $reglaRepository
    ) {
    }

    /**
     * @return array{monto: float, detalle: array}
     */
    public function calcularPagoMensual(Profesor $profesor, int $mes, int $ano, ?Curso $cursoFiltro = null): array
    {
        $tipoPago = $profesor->getTipoPago() ?: 'por_hora';
        $reglas = $this->reglaRepository->findByProfesorIndexadoPorCurso($profesor);

        // El fijo mensual del profesor se paga una sola vez, no por curso.
        $montoFijoProfesor = in_array($tipoPago, ['fijo_mensual', 'combinado'], true)
            ? (float) ($profesor->getMontoFijoMensual() ?? 0)
            : 0.0;

        $cursos = $cursoFiltro ? [$cursoFiltro] : $profesor->getCursos()->toArray();

        $detalleCursos = [];
        $montoCursos = 0.0;

        foreach ($cursos as $curso) {
            $regla = $this->reglaEfectiva($profesor, $curso, $reglas, $tipoPago);
            if ($regla === null) {
                continue;
            }

            switch ($regla['modalidad']) {
                case ProfesorCursoPago::MODALIDAD_PORCENTAJE:
                    $fila = $this->calcularPorcentajeDelCurso($profesor, $curso, $mes, $ano, $regla);
                    break;
                case ProfesorCursoPago::MODALIDAD_FIJO_MENSUAL:
                    $fila = [
                        'monto_fijo_curso' => $regla['monto_fijo'],
                        'monto' => $regla['monto_fijo'],
                    ];
                    break;
                default:
                    $fila = $this->calcularHorasDelCurso($profesor, $curso, $mes, $ano, $regla);
            }

            $detalleCursos[] = array_merge([
                'curso' => $curso->getNombre(),
                'curso_id' => $curso->getId(),
                'modalidad' => $regla['modalidad'],
                'origen_regla' => $regla['origen'],
            ], $fila);

            $montoCursos += $fila['monto'];
        }

        $detalle = [
            // Se mantiene la clave de siempre para no romper lo que ya la lee.
            'tipo_pago' => $tipoPago,
            'mes' => $mes,
            'ano' => $ano,
            'base_porcentaje' => $this->basePorcentaje($profesor),
            'monto_fijo' => $montoFijoProfesor,
            'monto_cursos' => $montoCursos,
            'cursos' => $detalleCursos,
        ];

        // 'monto_porcentaje' existía cuando el único combinado posible era fijo + porcentaje.
        // Se sigue publicando para las pantallas viejas, ahora como el total de los cursos.
        $detalle['monto_porcentaje'] = $montoCursos;

        return [
            'monto' => $montoFijoProfesor + $montoCursos,
            'detalle' => $detalle,
        ];
    }

    /**
     * Qué regla se aplica a este curso: la del curso si está cargada y completa, y si no la
     * configuración del profesor.
     *
     * Devuelve null cuando el curso no aporta nada, que es el caso del profesor con fijo
     * mensual: ese monto ya se contó una vez y no corresponde repetirlo por curso.
     *
     * @param array<int, ProfesorCursoPago> $reglas
     * @return array{modalidad: string, origen: string, precio_hora: float, viatico: float, porcentaje: float, monto_fijo: float}|null
     */
    private function reglaEfectiva(Profesor $profesor, Curso $curso, array $reglas, string $tipoPago): ?array
    {
        $delCurso = $reglas[$curso->getId()] ?? null;

        if ($delCurso && $delCurso->isActivo() && $delCurso->estaCompleta()) {
            return [
                'modalidad' => $delCurso->getModalidad(),
                'origen' => 'curso',
                'precio_hora' => $delCurso->getPrecioHora() ?? (float) $profesor->getPrecioHora(),
                'viatico' => $delCurso->getViatico() ?? (float) $profesor->getViatico(),
                'porcentaje' => $delCurso->getPorcentaje() ?? 0.0,
                'monto_fijo' => $delCurso->getMontoFijo() ?? 0.0,
            ];
        }

        $porDefecto = [
            'origen' => 'profesor',
            'precio_hora' => (float) $profesor->getPrecioHora(),
            'viatico' => (float) $profesor->getViatico(),
            'porcentaje' => (float) ($profesor->getPorcentajeCurso() ?? 0),
            'monto_fijo' => 0.0,
        ];

        switch ($tipoPago) {
            case 'porcentaje':
            case 'combinado':
                return array_merge($porDefecto, ['modalidad' => ProfesorCursoPago::MODALIDAD_PORCENTAJE]);
            case 'fijo_mensual':
                return null;
            default:
                return array_merge($porDefecto, ['modalidad' => ProfesorCursoPago::MODALIDAD_POR_HORA]);
        }
    }

    /**
     * Horas dictadas en el mes por el valor hora, más el viático por clase.
     *
     * Las horas se cuentan horario por horario y no clases por la duración del curso: un curso
     * que tiene lunes de una hora y miércoles de dos no se puede liquidar con una sola
     * duración, y Curso::getDuracion() devuelve la del primer horario.
     */
    private function calcularHorasDelCurso(Profesor $profesor, Curso $curso, int $mes, int $ano, array $regla): array
    {
        [$inicioMes, $finMes] = $this->rangoDelMes($mes, $ano);

        $ausenciasPorDia = $this->ausenciasPorDiaDeSemana($profesor, $curso, $inicioMes, $finMes);

        $horarios = $curso->getHorarios();
        $clasesProgramadas = 0;
        $clasesDictadas = 0;
        $horas = 0.0;
        $ausenciasContadas = 0;

        if (count($horarios) > 0) {
            foreach ($horarios as $horario) {
                $numeroDia = $this->numeroDeDia($horario->getDia());
                if ($numeroDia === null) {
                    continue;
                }

                $duracion = (float) ($horario->getDuracion() ?? 0);
                $fechas = $this->fechasDelMesEnDia($curso, $inicioMes, $finMes, $numeroDia);

                $ausenciasDelDia = min($ausenciasPorDia[$numeroDia] ?? 0, count($fechas));
                $dictadas = count($fechas) - $ausenciasDelDia;

                $clasesProgramadas += count($fechas);
                $clasesDictadas += $dictadas;
                $ausenciasContadas += $ausenciasDelDia;
                $horas += $dictadas * $duracion;
            }
        } else {
            // Curso sin horarios cargados: queda el campo legacy de duración y no hay días
            // sobre los que contar, así que no se puede liquidar por hora.
            $clasesProgramadas = 0;
        }

        $precioHora = $regla['precio_hora'];
        $viatico = $regla['viatico'];
        $monto = ($horas * $precioHora) + ($clasesDictadas * $viatico);

        return [
            'clases_programadas' => $clasesProgramadas,
            'ausencias' => $ausenciasContadas,
            'cantidad_asistencias' => $clasesDictadas,
            // Se mantiene por compatibilidad con las pantallas que la muestran.
            'duracion_curso' => $clasesDictadas > 0 ? round($horas / $clasesDictadas, 4) : 0,
            'horas_trabajadas' => $horas,
            'precio_hora' => $precioHora,
            'viatico' => $clasesDictadas * $viatico,
            'monto' => $monto,
        ];
    }

    /**
     * Porcentaje sobre el total mensual del curso.
     *
     * La base la elige el instituto: lo que realmente entró ese mes, o lo que se facturó
     * (inscriptos por el precio del curso, cobrado o no).
     */
    private function calcularPorcentajeDelCurso(Profesor $profesor, Curso $curso, int $mes, int $ano, array $regla): array
    {
        $porcentaje = $regla['porcentaje'];
        $sobreCobrado = $this->basePorcentaje($profesor) === InstitutoConfiguracion::BASE_COBRADO;

        $inscriptos = $this->contarInscriptosEnElMes($curso, $mes, $ano);
        $precioCurso = (float) $curso->getPrecio();
        $facturado = $inscriptos * $precioCurso;
        $cobrado = $this->cobradoDelCurso($curso, $mes, $ano);

        $base = $sobreCobrado ? $cobrado : $facturado;
        $monto = ($base * $porcentaje) / 100;

        return [
            'cantidad_alumnos' => $inscriptos,
            'precio_curso' => $precioCurso,
            'facturado' => $facturado,
            'cobrado' => $cobrado,
            // 'total_curso' es la base que se usó: es lo que muestran las pantallas.
            'total_curso' => $base,
            'porcentaje' => $porcentaje,
            'monto' => $monto,
        ];
    }

    /**
     * Cuántos alumnos tenían el curso en ese mes.
     *
     * Se cuenta sobre alumno_curso_historico, que es la inscripción real, y se compara contra
     * los dos extremos del mes: alguien que se dio de baja el 20 estuvo ese mes, y alguien que
     * se inscribió el 20 también. Comparar solo contra el día 1 dejaba afuera a los dos.
     */
    private function contarInscriptosEnElMes(Curso $curso, int $mes, int $ano): int
    {
        [$inicioMes, $finMes] = $this->rangoDelMes($mes, $ano);

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT h.alumno)')
            ->from(\App\Entity\AlumnoCursoHistorico::class, 'h')
            ->andWhere('h.curso = :curso')
            ->andWhere('h.fechaAlta <= :finMes')
            ->andWhere('h.fechaBaja IS NULL OR h.fechaBaja >= :inicioMes')
            ->setParameter('curso', $curso)
            ->setParameter('inicioMes', $inicioMes)
            ->setParameter('finMes', $finMes)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Lo que efectivamente entró por ese curso en ese mes.
     *
     * Se descuenta el monto restante del pago: si alguien pagó de más, ese excedente quedó como
     * saldo a favor y todavía no es plata de este mes, así que no corresponde pagar comisión
     * sobre él.
     */
    private function cobradoDelCurso(Curso $curso, int $mes, int $ano): float
    {
        $total = $this->entityManager->createQueryBuilder()
            ->select('SUM(p.monto - COALESCE(p.montoRestante, 0))')
            ->from(\App\Entity\AlumnosPagos::class, 'p')
            ->andWhere('p.curso = :curso')
            ->andWhere('p.mes = :mes')
            ->andWhere('p.ano = :ano')
            ->setParameter('curso', $curso)
            ->setParameter('mes', $mes)
            ->setParameter('ano', $ano)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($total ?? 0);
    }

    private function basePorcentaje(Profesor $profesor): string
    {
        $instituto = $profesor->getInstituto();
        $configuracion = $instituto ? $instituto->getConfiguracion() : null;

        return $configuracion
            ? $configuracion->getBasePorcentajeProfesor()
            : InstitutoConfiguracion::BASE_COBRADO;
    }

    /**
     * Cuántas ausencias del profesor cayeron en cada día de la semana, para descontarlas del
     * horario que corresponde y no de una duración promedio.
     *
     * @return array<int, int>
     */
    private function ausenciasPorDiaDeSemana(Profesor $profesor, Curso $curso, \DateTime $inicioMes, \DateTime $finMes): array
    {
        $ausencias = $this->asistenciaProfesoresRepository->createQueryBuilder('ap')
            ->andWhere('ap.profesor = :profesor')
            ->andWhere('ap.curso = :curso')
            ->andWhere('ap.fecha >= :inicioMes')
            ->andWhere('ap.fecha <= :finMes')
            ->andWhere('ap.presente = false')
            ->setParameter('profesor', $profesor)
            ->setParameter('curso', $curso->getId())
            ->setParameter('inicioMes', $inicioMes)
            ->setParameter('finMes', $finMes)
            ->getQuery()
            ->getResult();

        $porDia = [];
        foreach ($ausencias as $ausencia) {
            if (!$ausencia->getFecha()) {
                continue;
            }

            $dia = (int) $ausencia->getFecha()->format('w');
            $porDia[$dia] = ($porDia[$dia] ?? 0) + 1;
        }

        return $porDia;
    }

    /**
     * Las fechas del mes que caen en ese día de la semana y están dentro del período del curso.
     *
     * @return \DateTime[]
     */
    private function fechasDelMesEnDia(Curso $curso, \DateTime $inicioMes, \DateTime $finMes, int $numeroDia): array
    {
        $fechas = [];
        $fecha = clone $inicioMes;

        while ($fecha <= $finMes) {
            if ((int) $fecha->format('w') === $numeroDia) {
                $antesDeEmpezar = $curso->getFechaInicio() && $fecha < $curso->getFechaInicio();
                $despuesDeTerminar = $curso->getFechaFin() && $fecha > $curso->getFechaFin();

                if (!$antesDeEmpezar && !$despuesDeTerminar) {
                    $fechas[] = clone $fecha;
                }
            }

            $fecha->modify('+1 day');
        }

        return $fechas;
    }

    /**
     * @return array{0: \DateTime, 1: \DateTime}
     */
    private function rangoDelMes(int $mes, int $ano): array
    {
        $inicioMes = new \DateTime(sprintf('%d-%02d-01 00:00:00', $ano, $mes));
        $finMes = (clone $inicioMes)->modify('last day of this month')->setTime(23, 59, 59);

        return [$inicioMes, $finMes];
    }

    /**
     * El día de la semana como número, tolerando tildes y mayúsculas.
     */
    private function numeroDeDia(?string $dia): ?int
    {
        if (!$dia) {
            return null;
        }

        $normalizado = strtolower(strtr(trim($dia), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        ]));

        $dias = [
            'domingo' => 0, 'lunes' => 1, 'martes' => 2, 'miercoles' => 3,
            'jueves' => 4, 'viernes' => 5, 'sabado' => 6,
        ];

        return $dias[$normalizado] ?? null;
    }

    /**
     * Resumen de liquidación de un profesor en un mes: lo calculado, lo ya pagado y el saldo.
     */
    public function obtenerLiquidacion(Profesor $profesor, int $mes, int $ano): array
    {
        $calculo = $this->calcularPagoMensual($profesor, $mes, $ano);

        $pagosRealizados = $this->entityManager->getRepository(\App\Entity\ProfesorPago::class)
            ->findByProfesorMesAno($profesor, $mes, $ano);

        $totalPagado = 0;
        foreach ($pagosRealizados as $pago) {
            $totalPagado += (float) $pago->getMonto();
        }

        return [
            'monto_calculado' => $calculo['monto'],
            'detalle_calculo' => $calculo['detalle'],
            'total_pagado' => $totalPagado,
            'pagos_realizados' => $pagosRealizados,
            'saldo_pendiente' => $calculo['monto'] - $totalPagado,
        ];
    }
}
