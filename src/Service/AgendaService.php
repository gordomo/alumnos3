<?php

namespace App\Service;

use App\Entity\Alumno;
use App\Entity\Curso;
use App\Entity\EventoAgenda;
use App\Entity\Instituto;
use App\Entity\Profesor;
use App\Entity\User;
use App\Repository\CursoRepository;
use App\Repository\EvaluacionRepository;
use App\Repository\EventoAgendaRepository;
use App\Repository\TareaRepository;

/**
 * Arma la agenda: todas las fechas que le importan a quien está mirando, en un solo lugar.
 *
 * Casi todo lo que devuelve es información que ya existía desperdigada en otras pantallas: las
 * clases salen de los horarios del curso, los exámenes de las evaluaciones, las entregas de las
 * tareas y los vencimientos de la configuración del instituto. Lo único propio de la agenda son
 * los EventoAgenda, que es lo que el instituto carga a mano.
 *
 * Los eventos se piden por rango, el que el calendario tiene a la vista, así que no se expande
 * un año entero de clases para mostrar un mes.
 *
 * El alcance depende del rol y se resuelve acá una sola vez: el profesor ve sus cursos, el
 * alumn@ los suyos, y el administrador todo el instituto.
 */
class AgendaService
{
    public const FUENTE_CLASE = 'clase';
    public const FUENTE_EXAMEN = 'examen';
    public const FUENTE_TAREA = 'tarea';
    public const FUENTE_VENCIMIENTO = 'vencimiento';
    public const FUENTE_EVENTO = 'evento';

    public const FUENTES = [
        self::FUENTE_CLASE => ['etiqueta' => 'Clases', 'icono' => 'bi-easel', 'color' => '#0d6efd'],
        self::FUENTE_EXAMEN => ['etiqueta' => 'Evaluaciones', 'icono' => 'bi-file-earmark-text', 'color' => '#6f42c1'],
        self::FUENTE_TAREA => ['etiqueta' => 'Entregas de tareas', 'icono' => 'bi-journal-text', 'color' => '#fd7e14'],
        self::FUENTE_VENCIMIENTO => ['etiqueta' => 'Vencimientos', 'icono' => 'bi-cash-coin', 'color' => '#dc3545'],
        self::FUENTE_EVENTO => ['etiqueta' => 'Eventos del instituto', 'icono' => 'bi-pin-angle', 'color' => '#20c997'],
    ];

    private const DIAS_SEMANA = [
        'domingo' => 0, 'lunes' => 1, 'martes' => 2, 'miercoles' => 3,
        'jueves' => 4, 'viernes' => 5, 'sabado' => 6,
    ];

    public function __construct(
        private CursoRepository $cursoRepository,
        private EvaluacionRepository $evaluacionRepository,
        private TareaRepository $tareaRepository,
        private EventoAgendaRepository $eventoRepository,
        private DeudaCalculatorService $deudaCalculator
    ) {
    }

    /**
     * Eventos para el calendario, en el formato que espera FullCalendar.
     *
     * @param string[]|null $fuentes null trae todas las que el rol puede ver
     * @return array<int, array<string, mixed>>
     */
    public function eventos(User $user, \DateTimeInterface $desde, \DateTimeInterface $hasta, ?array $fuentes = null): array
    {
        $contexto = $this->contexto($user);
        if (!$contexto['instituto']) {
            return [];
        }

        $permitidas = $this->fuentesVisibles($contexto);
        $pedidas = $fuentes === null ? $permitidas : array_values(array_intersect($fuentes, $permitidas));

        $feriados = $this->diasFeriados($contexto['instituto'], $desde, $hasta);

        $eventos = [];

        if (in_array(self::FUENTE_CLASE, $pedidas, true)) {
            $eventos = array_merge($eventos, $this->clases($contexto, $desde, $hasta, $feriados));
        }
        if (in_array(self::FUENTE_EXAMEN, $pedidas, true)) {
            $eventos = array_merge($eventos, $this->examenes($contexto, $desde, $hasta));
        }
        if (in_array(self::FUENTE_TAREA, $pedidas, true)) {
            $eventos = array_merge($eventos, $this->entregas($contexto, $desde, $hasta));
        }
        if (in_array(self::FUENTE_VENCIMIENTO, $pedidas, true)) {
            $eventos = array_merge($eventos, $this->vencimientos($contexto, $desde, $hasta));
        }
        if (in_array(self::FUENTE_EVENTO, $pedidas, true)) {
            $eventos = array_merge($eventos, $this->eventosPropios($contexto, $desde, $hasta));
        }

        return $eventos;
    }

    /**
     * Qué fuentes tiene sentido ofrecerle a este usuario.
     *
     * @return string[]
     */
    public function fuentesVisiblesPara(User $user): array
    {
        return $this->fuentesVisibles($this->contexto($user));
    }

    /**
     * Quién está mirando y sobre qué cursos.
     *
     * @return array{instituto: ?Instituto, rol: string, cursos: Curso[], alumno: ?Alumno, profesor: ?Profesor}
     */
    private function contexto(User $user): array
    {
        $instituto = $user->getInstituto();

        // Un profesor que además administra el instituto ve todo: se resuelve por el rol más
        // amplio, no por tener ficha de profesor.
        if (in_array('ROLE_ADMIN_INSTITUTO', $user->getRoles(), true)) {
            return [
                'instituto' => $instituto,
                'rol' => 'admin',
                'cursos' => $instituto ? $this->cursoRepository->findByInstituto($instituto) : [],
                'alumno' => null,
                'profesor' => $user->getProfesor(),
            ];
        }

        if (in_array('ROLE_PROFESOR', $user->getRoles(), true) && $user->getProfesor()) {
            $profesor = $user->getProfesor();

            // findByProfesor no filtra por instituto, así que se filtra acá.
            $cursos = array_values(array_filter(
                $this->cursoRepository->findByProfesor($profesor),
                static fn(Curso $curso) => $curso->getInstituto() === $instituto
            ));

            return [
                'instituto' => $instituto,
                'rol' => 'profesor',
                'cursos' => $cursos,
                'alumno' => null,
                'profesor' => $profesor,
            ];
        }

        if (in_array('ROLE_ALUMNO', $user->getRoles(), true) && $user->getAlumno()) {
            $alumno = $user->getAlumno();

            // Las inscripciones activas son la fuente de verdad de qué cursa hoy.
            $cursos = [];
            foreach ($alumno->getCursosHistoricos() as $historico) {
                if ($historico->isActivo() && $historico->getCurso()) {
                    $cursos[$historico->getCurso()->getId()] = $historico->getCurso();
                }
            }

            return [
                'instituto' => $alumno->getInstituto(),
                'rol' => 'alumno',
                'cursos' => array_values($cursos),
                'alumno' => $alumno,
                'profesor' => null,
            ];
        }

        return ['instituto' => null, 'rol' => 'ninguno', 'cursos' => [], 'alumno' => null, 'profesor' => null];
    }

    /**
     * @return string[]
     */
    private function fuentesVisibles(array $contexto): array
    {
        switch ($contexto['rol']) {
            case 'admin':
                return array_keys(self::FUENTES);
            case 'profesor':
                // Al profesor no le corresponde ver los vencimientos de las cuotas de los alumn@s.
                return [self::FUENTE_CLASE, self::FUENTE_EXAMEN, self::FUENTE_TAREA, self::FUENTE_EVENTO];
            case 'alumno':
                return [self::FUENTE_CLASE, self::FUENTE_EXAMEN, self::FUENTE_TAREA, self::FUENTE_VENCIMIENTO, self::FUENTE_EVENTO];
        }

        return [];
    }

    /**
     * Los días que caen dentro de un feriado o receso, indexados por Y-m-d.
     *
     * @return array<string, EventoAgenda>
     */
    private function diasFeriados(Instituto $instituto, \DateTimeInterface $desde, \DateTimeInterface $hasta): array
    {
        $dias = [];

        foreach ($this->eventoRepository->findFeriadosEnRango($instituto, $desde, $hasta) as $feriado) {
            $dia = \DateTime::createFromFormat('Y-m-d', $feriado->getFechaInicio()->format('Y-m-d'));
            $ultimo = $feriado->getFechaFinEfectiva()->format('Y-m-d');

            // Un receso puede empezar antes del rango pedido: igual se recorre desde su inicio,
            // que es lo que asegura que los días de este mes queden marcados.
            while ($dia && $dia->format('Y-m-d') <= $ultimo) {
                $dias[$dia->format('Y-m-d')] = $feriado;
                $dia->modify('+1 day');
            }
        }

        return $dias;
    }

    /**
     * Una clase por cada día que el curso se dicta dentro del rango.
     *
     * Se recorre horario por horario, no el campo viejo de días y hora única del curso: un curso
     * con lunes a las 17 y jueves a las 8 tiene que dibujarse en su hora real cada día.
     */
    private function clases(array $contexto, \DateTimeInterface $desde, \DateTimeInterface $hasta, array $feriados): array
    {
        $eventos = [];
        $color = self::FUENTES[self::FUENTE_CLASE]['color'];

        foreach ($contexto['cursos'] as $curso) {
            $inicioCurso = $curso->getFechaInicio();
            $finCurso = $curso->getFechaFin();

            foreach ($curso->getHorarios() as $horario) {
                $numeroDia = $this->numeroDeDia($horario->getDia());
                if ($numeroDia === null || !$horario->getHorarioInicio()) {
                    continue;
                }

                $dia = \DateTime::createFromFormat('Y-m-d H:i:s', $desde->format('Y-m-d') . ' 00:00:00');
                $ultimo = $hasta->format('Y-m-d');

                while ($dia->format('Y-m-d') <= $ultimo) {
                    $fecha = $dia->format('Y-m-d');
                    $coincideDia = (int) $dia->format('w') === $numeroDia;
                    $dentroDelCurso = (!$inicioCurso || $dia >= $this->aMedianoche($inicioCurso))
                        && (!$finCurso || $dia <= $this->aMedianoche($finCurso));

                    if ($coincideDia && $dentroDelCurso) {
                        $feriado = $feriados[$fecha] ?? null;

                        $eventos[] = [
                            'title' => ($feriado ? '(sin clase) ' : '') . $curso->getNombre(),
                            'start' => $fecha . 'T' . $horario->getHorarioInicio()->format('H:i:s'),
                            'end' => $horario->getHorarioFin()
                                ? $fecha . 'T' . $horario->getHorarioFin()->format('H:i:s')
                                : null,
                            // Un día feriado la clase se muestra apagada en lugar de esconderse:
                            // así se entiende por qué no hay asistencia tomada ese día.
                            'backgroundColor' => $feriado ? '#ced4da' : $color,
                            'borderColor' => $feriado ? '#ced4da' : $color,
                            'textColor' => $feriado ? '#495057' : null,
                            'extendedProps' => [
                                'fuente' => self::FUENTE_CLASE,
                                'curso' => $curso->getNombre(),
                                'detalle' => $feriado
                                    ? 'No hay clase: ' . $feriado->getTitulo()
                                    : $this->profesoresDelCurso($curso),
                                'suspendida' => $feriado !== null,
                            ],
                        ];
                    }

                    $dia->modify('+1 day');
                }
            }
        }

        return $eventos;
    }

    private function examenes(array $contexto, \DateTimeInterface $desde, \DateTimeInterface $hasta): array
    {
        $eventos = [];
        $color = self::FUENTES[self::FUENTE_EXAMEN]['color'];

        foreach ($this->evaluacionRepository->findEnRangoPorCursos($contexto['cursos'], $desde, $hasta) as $evaluacion) {
            $curso = $evaluacion->getCurso();
            $area = $evaluacion->getArea();

            $eventos[] = [
                'title' => $evaluacion->getNombre(),
                'start' => $evaluacion->getFecha()->format('Y-m-d'),
                'allDay' => true,
                'backgroundColor' => $color,
                'borderColor' => $color,
                'extendedProps' => [
                    'fuente' => self::FUENTE_EXAMEN,
                    'curso' => $curso ? $curso->getNombre() : null,
                    'detalle' => $area ? 'Área: ' . $area->getNombre() : null,
                ],
            ];
        }

        return $eventos;
    }

    private function entregas(array $contexto, \DateTimeInterface $desde, \DateTimeInterface $hasta): array
    {
        $eventos = [];
        $color = self::FUENTES[self::FUENTE_TAREA]['color'];

        foreach ($this->tareaRepository->findConEntregaEnRango($contexto['cursos'], $desde, $hasta) as $tarea) {
            $curso = $tarea->getCurso();

            $eventos[] = [
                'title' => 'Entrega: ' . $tarea->getTitulo(),
                'start' => $tarea->getFechaEntrega()->format('Y-m-d'),
                'allDay' => true,
                'backgroundColor' => $color,
                'borderColor' => $color,
                'extendedProps' => [
                    'fuente' => self::FUENTE_TAREA,
                    'curso' => $curso ? $curso->getNombre() : null,
                    'detalle' => 'Se pidió el ' . $tarea->getFecha()->format('d/m/Y'),
                ],
            ];
        }

        return $eventos;
    }

    /**
     * Los días de vencimiento de cada mes del rango.
     *
     * Al administrador se le muestra el vencimiento del instituto; al alumn@, solo los meses en
     * los que realmente debe algo, que es lo único accionable para él.
     */
    private function vencimientos(array $contexto, \DateTimeInterface $desde, \DateTimeInterface $hasta): array
    {
        $instituto = $contexto['instituto'];
        $vencimientos = $instituto->getVencimientos()->toArray();
        if (!$vencimientos) {
            return [];
        }

        usort($vencimientos, static fn($a, $b) => $a->getOrden() <=> $b->getOrden());
        $color = self::FUENTES[self::FUENTE_VENCIMIENTO]['color'];

        // Meses del alumn@ con cuota abierta, para no avisarle de un vencimiento que ya pagó.
        $mesesConDeuda = null;
        if ($contexto['rol'] === 'alumno') {
            $mesesConDeuda = [];
            foreach ($this->deudaCalculator->calcularDeudasAlumno($contexto['alumno']) as $deuda) {
                $mesesConDeuda[sprintf('%d-%02d', $deuda['ano'], $deuda['mes'])] = true;
            }
        }

        $eventos = [];
        $mes = \DateTime::createFromFormat('Y-m-d H:i:s', $desde->format('Y-m') . '-01 00:00:00');
        $ultimoMes = $hasta->format('Y-m');

        while ($mes->format('Y-m') <= $ultimoMes) {
            $clave = $mes->format('Y-m');

            if ($mesesConDeuda === null || isset($mesesConDeuda[$clave])) {
                foreach ($vencimientos as $vencimiento) {
                    $dia = (int) $vencimiento->getDiaVencimiento();
                    $ultimoDiaDelMes = (int) $mes->format('t');
                    // Un vencimiento el 31 en un mes de 30 cae el último día, no en el mes que viene.
                    $fecha = sprintf('%s-%02d', $clave, min($dia, $ultimoDiaDelMes));

                    $interes = (float) $vencimiento->getPorcentajeInteres();

                    $eventos[] = [
                        'title' => $mesesConDeuda !== null
                            ? 'Vence tu cuota'
                            : 'Vencimiento de cuotas',
                        'start' => $fecha,
                        'allDay' => true,
                        'backgroundColor' => $color,
                        'borderColor' => $color,
                        'extendedProps' => [
                            'fuente' => self::FUENTE_VENCIMIENTO,
                            'curso' => null,
                            'detalle' => $interes > 0
                                ? sprintf('Después de este día se aplica %s%% de recargo', rtrim(rtrim(number_format($interes, 2, ',', '.'), '0'), ','))
                                : 'Sin recargo configurado',
                        ],
                    ];
                }
            }

            $mes->modify('first day of next month');
        }

        return $eventos;
    }

    private function eventosPropios(array $contexto, \DateTimeInterface $desde, \DateTimeInterface $hasta): array
    {
        $eventos = [];
        $rol = $contexto['rol'];

        foreach ($this->eventoRepository->findEnRango($contexto['instituto'], $desde, $hasta) as $evento) {
            if ($rol === 'profesor' && !$evento->visibleParaProfesor()) {
                continue;
            }
            if ($rol === 'alumno' && !$evento->visibleParaAlumno()) {
                continue;
            }

            // El curso propio del evento lo limita a quien cursa o dicta ese curso.
            if ($evento->getCurso() && $rol !== 'admin' && !$this->estaEnLosCursos($evento->getCurso(), $contexto['cursos'])) {
                continue;
            }

            $color = $evento->getTipoColor();
            $fin = $evento->getFechaFin();

            $eventos[] = [
                'title' => $evento->getTitulo(),
                'start' => $evento->isTodoElDia()
                    ? $evento->getFechaInicio()->format('Y-m-d')
                    : $evento->getFechaInicio()->format('Y-m-d\TH:i:s'),
                // FullCalendar trata el fin de un evento de todo el día como exclusivo, así que
                // se le suma un día para que el último quede pintado.
                'end' => $fin
                    ? ($evento->isTodoElDia()
                        ? (clone $fin)->modify('+1 day')->format('Y-m-d')
                        : $fin->format('Y-m-d\TH:i:s'))
                    : null,
                'allDay' => $evento->isTodoElDia(),
                'backgroundColor' => $color,
                'borderColor' => $color,
                'extendedProps' => [
                    'fuente' => self::FUENTE_EVENTO,
                    'tipo' => $evento->getTipoEtiqueta(),
                    'curso' => $evento->getCurso() ? $evento->getCurso()->getNombre() : null,
                    'detalle' => $evento->getDescripcion(),
                    'eventoId' => $evento->getId(),
                ],
            ];
        }

        return $eventos;
    }

    /**
     * @param Curso[] $cursos
     */
    private function estaEnLosCursos(Curso $curso, array $cursos): bool
    {
        foreach ($cursos as $propio) {
            if ($propio->getId() === $curso->getId()) {
                return true;
            }
        }

        return false;
    }

    private function profesoresDelCurso(Curso $curso): ?string
    {
        $nombres = [];
        foreach ($curso->getProfesores() as $profesor) {
            $nombres[] = $profesor->getNombre() . ' ' . $profesor->getApellido();
        }

        return $nombres ? implode(', ', $nombres) : null;
    }

    private function aMedianoche(\DateTimeInterface $fecha): \DateTime
    {
        return \DateTime::createFromFormat('Y-m-d H:i:s', $fecha->format('Y-m-d') . ' 00:00:00');
    }

    private function numeroDeDia(?string $dia): ?int
    {
        if (!$dia) {
            return null;
        }

        $normalizado = strtolower(strtr(trim($dia), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        ]));

        return self::DIAS_SEMANA[$normalizado] ?? null;
    }
}
