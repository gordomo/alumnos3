<?php

namespace App\Controller;

use App\Entity\Curso;
use App\Form\CursoType;
use App\Repository\CursoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Helpers;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\AlumnoCursoHistorico;
use App\Service\DeudaService;
use App\Service\TokenService;
use App\Service\HorarioConflictService;
use App\Service\InstitutoTimezoneService;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * @Route("/instituto/curso")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class CursoController extends AbstractController
{
    private $logger;
    private $tokenService;
    private HorarioConflictService $horarioConflictService;
    private InstitutoTimezoneService $institutoTimezoneService;

    public function __construct(LoggerInterface $logger, TokenService $tokenService, HorarioConflictService $horarioConflictService, InstitutoTimezoneService $institutoTimezoneService)
    {
        $this->logger = $logger;
        $this->tokenService = $tokenService;
        $this->horarioConflictService = $horarioConflictService;
        $this->institutoTimezoneService = $institutoTimezoneService;
    }

    /**
     * @Route("/", name="app_curso_index", methods={"GET"})
     */
    public function index(CursoRepository $cursoRepository, Request $request, PaginatorInterface $paginator): Response
    {
        // Get search parameter from request
        $busqueda = $request->get('busqueda');
        $order = $request->get('order', 'desc');
        $sort = $request->get('sort', 'nombre');

        // Get current user's institute
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        $instituto = $user->getInstituto();

        // Obtener cursos activos y deshabilitados
        $cursosQueryBuilder = $this->createQuery($cursoRepository, $instituto, $sort, $order, false, $busqueda);
        $cursosDeshabilitadosQueryBuilder = $this->createQuery($cursoRepository, $instituto, $sort, $order, true, $busqueda);

        $cursos = $paginator->paginate(
            $cursosQueryBuilder,
            $request->query->getInt('page_active', 1),
            12,
            ['pageParameterName' => 'page_active']
        );

        $cursosDeshabilitados = $paginator->paginate(
            $cursosDeshabilitadosQueryBuilder,
            $request->query->getInt('page_disabled', 1),
            12,
            ['pageParameterName' => 'page_disabled']
        );

        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);

        return $this->render('curso/index.html.twig', [
            'cursos' => $cursos,
            'order' => $order,
            'sort' => $sort,
            'totalCursos' => $cursos->getTotalItemCount(),
            'cursosDeshabilitados' => $cursosDeshabilitados,
            'totalCursosDeshabilitados' => $cursosDeshabilitados->getTotalItemCount(),
            'busqueda' => $busqueda,
            'app_date_format' => $dateFormat,
        ]);
    }

    /**
     * Crea una consulta para obtener cursos con los filtros especificados
     */
    private function createQuery(
        CursoRepository $cursoRepository,
        $instituto,
        string $sort,
        string $order,
        bool $disabled,
        ?string $busqueda = null
    ): QueryBuilder {
        $qb = $cursoRepository->createQueryBuilder('c')
            ->leftJoin('c.horarios', 'h')->addSelect('h')
            ->where('c.instituto = :instituto')
            ->setParameter('instituto', $instituto);

        if ($busqueda) {
            $qb->andWhere('c.nombre LIKE :nombre')
               ->setParameter('nombre', '%' . $busqueda . '%');
        }

        if ($disabled) {
            // Para cursos deshabilitados, incluir:
            // 1. Cursos manualmente deshabilitados
            // 2. Cursos cuya fecha fin está en el pasado
            $qb->andWhere('c.disabled = :disabled')
               ->setParameter('disabled', true);
        } else {
            // Para cursos activos, solo incluir:
            // 1. Cursos no deshabilitados manualmente
            // 2. Cursos cuya fecha fin es futura o no tiene fecha fin
            $qb->andWhere('c.disabled = :disabled')
               ->setParameter('disabled', false);
        }

        // Incluir la columna de orden en el SELECT para compatibilidad con DISTINCT (paginador + MySQL)
        if ($sort === 'precio') {
            $qb->addSelect('c.precio + 0 AS HIDDEN orden_precio')
               ->orderBy('orden_precio', $order);
        } else {
            $qb->orderBy('c.'.$sort, $order);
        }

        return $qb;
    }

    /**
     * @Route("/new", name="app_curso_new", methods={"GET", "POST"})
     */
    public function new(Request $request, CursoRepository $cursoRepository): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        $curso = new Curso();
        $curso->setInstituto($instituto);
        // Una fila de horario por defecto 08:00-09:00
        $horarioDefault = new \App\Entity\CursoHorario();
        $horarioDefault->setHorarioInicio(new \DateTime('08:00'));
        $horarioDefault->setHorarioFin(new \DateTime('09:00'));
        $curso->addHorario($horarioDefault);
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        $form = $this->createForm(CursoType::class, $curso, [
            'allow_extra_fields' => true,
            'instituto' => $instituto,
            'date_format' => $dateFormat
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                // Verificar tokens antes de crear
                if (!$this->tokenService->hasEnoughTokens($instituto, 'curso.create')) {
                    $this->addFlash('danger', 'No tienes suficientes tokens para crear un curso. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                    return $this->renderForm('curso/new.html.twig', [
                        'curso' => $curso,
                        'form' => $form,
                        'date_format' => $dateFormat,
                    ]);
                }

                $this->removerHorariosVacios($curso);
                $curso->syncLegacyFromHorarios();

                // Verificar conflictos de horarios para cada profesor asignado
                $todosConflictos = [];
                $conflictosVistos = []; // Para evitar duplicados
                
                foreach ($curso->getProfesores() as $profesor) {
                    if (!$profesor->getId()) {
                        continue; // Profesor nuevo sin ID, no puede tener cursos existentes
                    }
                    $conflictos = $this->horarioConflictService->detectarConflictos($curso, $profesor);
                    
                    foreach ($conflictos as $conflicto) {
                        // Crear una clave única para identificar conflictos duplicados
                        // Basada en: curso_id + días + horarios
                        $claveUnica = $conflicto['curso']->getId() . '_' . 
                                     implode(',', $conflicto['dias']) . '_' . 
                                     $conflicto['horarioExistente'] . '_' . 
                                     $conflicto['horarioNuevo'];
                        
                        // Solo agregar si no hemos visto este conflicto antes
                        if (!isset($conflictosVistos[$claveUnica])) {
                            $conflictosVistos[$claveUnica] = true;
                            $todosConflictos[] = $conflicto;
                        }
                    }
                }
                
                // Si hay conflictos y el usuario no ha confirmado, mostrar modal y detener el guardado
                if (!empty($todosConflictos) && !$request->request->get('confirmar_conflictos_horarios')) {
                    // Guardar información de conflictos en la sesión para mostrar en el modal
                    $conflictosData = [];
                    foreach ($todosConflictos as $conflicto) {
                        $conflictosData[] = [
                            'curso_nombre' => $conflicto['curso']->getNombre(),
                            'dias' => $conflicto['dias'],
                            'horario_existente' => $conflicto['horarioExistente'],
                            'horario_nuevo' => $conflicto['horarioNuevo'],
                        ];
                    }
                    
                    $request->getSession()->set('conflictos_horarios_warning', [
                        'curso_id' => null, // Curso nuevo, aún no tiene ID
                        'conflictos' => $conflictosData,
                    ]);
                    
                    // Volver a mostrar el formulario con el modal
                    return $this->renderForm('curso/new.html.twig', [
                        'curso' => $curso,
                        'form' => $form,
                        'mostrar_confirmacion_conflictos' => true,
                        'date_format' => $dateFormat,
                    ]);
                }
                
                // Si llegamos aquí, el usuario confirmó o no hay conflictos - limpiar sesión
                $request->getSession()->remove('conflictos_horarios_warning');
                
                $cursoRepository->add($curso);
                
                // Consumir tokens después de guardar exitosamente
                $this->tokenService->consumeTokens(
                    $instituto,
                    'curso.create',
                    $this->getUser(),
                    'Crear curso: ' . $curso->getNombre(),
                    'Curso',
                    $curso->getId()
                );
                
                return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
            } else {
                $errors = $form->getErrors(true);
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        // Si viene el parámetro para limpiar conflictos, limpiar la sesión
        if ($request->query->get('limpiar_conflictos')) {
            $request->getSession()->remove('conflictos_horarios_warning');
        }
        
        // Verificar si hay advertencia de conflictos en la sesión
        $mostrarConfirmacionConflictos = false;
        if ($request->getSession()->has('conflictos_horarios_warning')) {
            $conflictosData = $request->getSession()->get('conflictos_horarios_warning');
            if ($conflictosData && (!isset($conflictosData['curso_id']) || $conflictosData['curso_id'] === null)) {
                $mostrarConfirmacionConflictos = true;
            }
        }

        return $this->renderForm('curso/new.html.twig', [
            'curso' => $curso,
            'form' => $form,
            'mostrar_confirmacion_conflictos' => $mostrarConfirmacionConflictos,
            'date_format' => $dateFormat,
        ]);
    }

    /**
     * @Route("/calendario", name="app_curso_calendario", methods={"GET"})
     */
    public function calendario(CursoRepository $cursoRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);
        $eventos = [];
        $cursosSinHorario = [];

        foreach ($cursos as $curso) {
            if (!$curso->getHorarioInicio() || !$curso->getHorarioFin() || !$curso->getFechaInicio() || !$curso->getFechaFin()) {
                $cursosSinHorario[] = $curso;
                continue;
            }

            try {
                $fechaInicio = $curso->getFechaInicio();
                $fechaFin = $curso->getFechaFin();
                $horaInicio = $curso->getHorarioInicio();
                $horaFin = $curso->getHorarioFin();
                $dias = $curso->getDias();

                // Crear un array de días de la semana (0 = Domingo, 1 = Lunes, etc.)
                $diasSemana = [
                    'Domingo' => 0,
                    'Lunes' => 1,
                    'Martes' => 2,
                    'Miercoles' => 3,
                    'Jueves' => 4,
                    'Viernes' => 5,
                    'Sabado' => 6
                ];

                // Convertir los días seleccionados a números
                $diasSeleccionados = array_map(function($dia) use ($diasSemana) {
                    return $diasSemana[$dia];
                }, $dias);

                // Generar eventos para cada día seleccionado en el rango de fechas
                $fechaActual = clone $fechaInicio;
                while ($fechaActual <= $fechaFin) {
                    $diaSemana = (int)$fechaActual->format('w');
                    
                    if (in_array($diaSemana, $diasSeleccionados)) {
                        // Construir string de profesores para el calendario
                        $profesores = $curso->getProfesores();
                        $profesorStr = '';
                        if ($profesores->count() > 0) {
                            $primerProfesor = $profesores->first();
                            $profesorStr = $primerProfesor->getNombre() . ' ' . $primerProfesor->getApellido();
                            if ($profesores->count() > 1) {
                                $profesorStr .= ' (+' . ($profesores->count() - 1) . ' más)';
                            }
                        } else {
                            $profesorStr = 'Sin profesor';
                        }
                        
                        $evento = [
                            'title' => $curso->getNombre(),
                            'start' => $fechaActual->format('Y-m-d') . 'T' . $horaInicio->format('H:i:s'),
                            'end' => $fechaActual->format('Y-m-d') . 'T' . $horaFin->format('H:i:s'),
                            'extendedProps' => [
                                'profesor' => $profesorStr,
                                'profesores' => $profesores->map(function($p) { return $p->getNombre() . ' ' . $p->getApellido(); })->toArray(), // Lista completa para el tooltip
                                'duracion' => $curso->getDuracion(),
                                'precio' => $curso->getPrecio()
                            ]
                        ];
                        $eventos[] = $evento;
                    }
                    
                    $fechaActual->modify('+1 day');
                }
            } catch (\Exception $e) {
                // Si hay algún error al procesar el curso, lo agregamos a la lista de cursos sin horario
                $cursosSinHorario[] = $curso;
            }
        }

        return $this->render('curso/calendario.html.twig', [
            'eventos' => json_encode($eventos),
            'cursosSinHorario' => $cursosSinHorario
        ]);
    }

    /**
     * @Route("/{id}", name="app_curso_show", methods={"GET"})
     */
    public function show(Curso $curso): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        return $this->render('curso/show.html.twig', [
            'curso' => $curso,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_curso_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Curso $curso, CursoRepository $cursoRepository, EntityManagerInterface $entityManager, \App\Service\DeudaService $deudaService): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        
        // Guardar fechas originales para comparar cambios
        $fechaInicioOriginal = $curso->getFechaInicio();
        $fechaFinOriginal = $curso->getFechaFin();
        
        // Guardar profesores originales para comparar cambios
        $profesoresOriginales = [];
        foreach ($curso->getProfesores() as $profesor) {
            $profesoresOriginales[] = $profesor->getId();
        }
        
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        $form = $this->createForm(CursoType::class, $curso, [
            'instituto' => $instituto,
            'date_format' => $dateFormat
        ]);

        // Si el curso no tiene horarios en la colección pero sí datos legacy (migración antigua), rellenar horarios para el formulario
        if ($curso->getHorarios()->count() === 0 && !empty($curso->getDias()) && $curso->getHorarioInicio() && $curso->getHorarioFin()) {
            foreach ($curso->getDias() as $dia) {
                $horario = new \App\Entity\CursoHorario();
                $horario->setDia($dia);
                $horario->setHorarioInicio($curso->getHorarioInicio() instanceof \DateTimeInterface ? clone $curso->getHorarioInicio() : new \DateTime($curso->getHorarioInicio()->format('H:i')));
                $horario->setHorarioFin($curso->getHorarioFin() instanceof \DateTimeInterface ? clone $curso->getHorarioFin() : new \DateTime($curso->getHorarioFin()->format('H:i')));
                $curso->addHorario($horario);
            }
        }

        // Si viene el parámetro para limpiar conflictos, limpiar la sesión
        if ($request->query->get('limpiar_conflictos')) {
            $request->getSession()->remove('conflictos_horarios_warning');
        }
        
        // Verificar si hay advertencia de conflictos en la sesión
        $mostrarConfirmacionConflictos = false;
        if ($request->getSession()->has('conflictos_horarios_warning')) {
            $conflictosData = $request->getSession()->get('conflictos_horarios_warning');
            if ($conflictosData && isset($conflictosData['curso_id']) && $conflictosData['curso_id'] == $curso->getId()) {
                $mostrarConfirmacionConflictos = true;
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Verificar tokens antes de editar
            if (!$this->tokenService->hasEnoughTokens($instituto, 'curso.edit')) {
                $this->addFlash('danger', 'No tienes suficientes tokens para editar un curso. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                return $this->renderForm('curso/edit.html.twig', [
                    'curso' => $curso,
                    'form' => $form,
                    'date_format' => $dateFormat,
                ]);
            }
            
            try {
                // Verificar si se cambiaron los profesores
                $profesoresNuevos = [];
                foreach ($curso->getProfesores() as $profesor) {
                    $profesoresNuevos[] = $profesor->getId();
                }
                
                // Verificar si hay cambios en los profesores y si el curso ya ha comenzado
                $profesoresCambiados = count(array_diff($profesoresOriginales, $profesoresNuevos)) > 0 || 
                                      count(array_diff($profesoresNuevos, $profesoresOriginales)) > 0;
                
                $cursoComenzado = $curso->getFechaInicio() <= new \DateTime();
                
                // Si hay cambios en los profesores y el curso ya comenzó, verificar si hay asistencias registradas
                if ($profesoresCambiados && $cursoComenzado) {
                    // Obtener el repositorio de asistencia de profesores
                    $asistenciaProfesoresRepository = $entityManager->getRepository('App\Entity\AsistenciaProfesores');
                    
                    // Buscar asistencias para los profesores actuales en este curso
                    $asistenciasExistentes = false;
                    foreach ($curso->getProfesores() as $profesor) {
                        $asistencias = $asistenciaProfesoresRepository->findBy([
                            'curso' => $curso,
                            'profesor' => $profesor
                        ]);
                        
                        if (count($asistencias) > 0) {
                            $asistenciasExistentes = true;
                            break;
                        }
                    }
                    
                    if ($asistenciasExistentes) {
                        // Si hay asistencias y no se confirmó la acción, mostrar advertencia
                        if (!$request->request->get('confirmar_cambio_profesor')) {
                            $this->addFlash('warning', 'Este curso ya ha comenzado y tiene registros de asistencia para los profesores actuales. 
                            Si remueve algún profesor del curso, las asistencias registradas de ese profesor serán transferidas a otro profesor del curso. 
                            Si desea continuar, confirme la acción.');
                            
                            return $this->renderForm('curso/edit.html.twig', [
                                'curso' => $curso,
                                'form' => $form,
                                'mostrar_confirmacion' => true,
                                'date_format' => $dateFormat,
                            ]);
                        } else {
                            // El usuario confirmó la acción, actualizar las asistencias
                            $profesoresAnteriores = $entityManager->getRepository('App\Entity\Profesor')->findBy([
                                'id' => $profesoresOriginales
                            ]);
                            
                            $profesoresActuales = $curso->getProfesores();
                            $profesoresActualesIds = [];
                            foreach ($profesoresActuales as $profesorActual) {
                                $profesoresActualesIds[] = $profesorActual->getId();
                            }
                            
                            // Identificar profesores que fueron completamente removidos del curso
                            $profesoresRemovidos = [];
                            foreach ($profesoresAnteriores as $profesorAnterior) {
                                if (!in_array($profesorAnterior->getId(), $profesoresActualesIds)) {
                                    $profesoresRemovidos[] = $profesorAnterior;
                                }
                            }
                            
                            // Solo transferir asistencias de profesores completamente removidos
                            if (count($profesoresRemovidos) > 0 && count($profesoresActuales) > 0) {
                                // Tomar el primer profesor actual como el que recibirá las asistencias
                                $profesorDestino = $profesoresActuales[0];
                                $asistenciasTransferidas = 0;
                                
                                foreach ($profesoresRemovidos as $profesorRemovido) {
                                    // Buscar todas las asistencias del profesor removido en este curso
                                    $asistencias = $asistenciaProfesoresRepository->findBy([
                                        'curso' => $curso,
                                        'profesor' => $profesorRemovido
                                    ]);
                                    
                                    foreach ($asistencias as $asistencia) {
                                        $asistencia->setProfesor($profesorDestino);
                                        $entityManager->persist($asistencia);
                                        $asistenciasTransferidas++;
                                    }
                                }
                                
                                if ($asistenciasTransferidas > 0) {
                                    $this->addFlash('success', 'Se han transferido ' . $asistenciasTransferidas . ' registro(s) de asistencia al profesor ' . $profesorDestino->getNombre() . ' ' . $profesorDestino->getApellido() . '.');
                                }
                            }
                        }
                    }
                }
                
                $this->removerHorariosVacios($curso);
                $curso->syncLegacyFromHorarios();

                // Verificar conflictos de horarios para cada profesor asignado
                // IMPORTANTE: Esto se ejecuta DESPUÉS de que el formulario ya actualizó $curso->getProfesores()
                $todosConflictos = [];
                $conflictosVistos = []; // Para evitar duplicados
                
                foreach ($curso->getProfesores() as $profesor) {
                    if (!$profesor->getId()) {
                        continue; // Profesor nuevo sin ID, no puede tener cursos existentes
                    }
                    
                    $conflictos = $this->horarioConflictService->detectarConflictos($curso, $profesor, $curso->getId());
                    
                    foreach ($conflictos as $conflicto) {
                        // Crear una clave única para identificar conflictos duplicados
                        // Basada en: curso_id + días + horarios
                        $claveUnica = $conflicto['curso']->getId() . '_' . 
                                     implode(',', $conflicto['dias']) . '_' . 
                                     $conflicto['horarioExistente'] . '_' . 
                                     $conflicto['horarioNuevo'];
                        
                        // Solo agregar si no hemos visto este conflicto antes
                        if (!isset($conflictosVistos[$claveUnica])) {
                            $conflictosVistos[$claveUnica] = true;
                            $todosConflictos[] = $conflicto;
                        }
                    }
                }
                
                // Si hay conflictos y el usuario no ha confirmado, mostrar modal y detener el guardado
                if (!empty($todosConflictos) && !$request->request->get('confirmar_conflictos_horarios')) {
                    // Guardar información de conflictos en la sesión para mostrar en el modal
                    $conflictosData = [];
                    foreach ($todosConflictos as $conflicto) {
                        $conflictosData[] = [
                            'curso_nombre' => $conflicto['curso']->getNombre(),
                            'dias' => $conflicto['dias'],
                            'horario_existente' => $conflicto['horarioExistente'],
                            'horario_nuevo' => $conflicto['horarioNuevo'],
                        ];
                    }
                    
                    $request->getSession()->set('conflictos_horarios_warning', [
                        'curso_id' => $curso->getId(),
                        'conflictos' => $conflictosData,
                    ]);
                    
                    // Volver a mostrar el formulario con el modal
                    return $this->renderForm('curso/edit.html.twig', [
                        'curso' => $curso,
                        'form' => $form,
                        'mostrar_confirmacion_conflictos' => true,
                        'date_format' => $dateFormat,
                    ]);
                }
                
                // Si llegamos aquí, el usuario confirmó o no hay conflictos - limpiar sesión
                $request->getSession()->remove('conflictos_horarios_warning');
                
                // Verificar si las fechas han cambiado
                $fechasModificadas = ($fechaInicioOriginal != $curso->getFechaInicio() || 
                                   $fechaFinOriginal != $curso->getFechaFin());
                
                // Si las fechas han cambiado, actualizar todos los registros en AlumnoCursoHistorico
                if ($fechasModificadas) {
                    // No necesitamos actualizar el histórico ya que ahora usamos las fechas del curso directamente
                    // Solo regeneramos las deudas usando las nuevas fechas del curso
                    
                    // Actualizar las deudas asociadas a este curso
                    $resultadoActualizacionDeudas = $deudaService->actualizarDeudasPorCambioFechas(
                        $curso,
                        $fechaInicioOriginal,
                        $fechaFinOriginal,
                        $curso->getFechaInicio(),
                        $curso->getFechaFin()
                    );
                    
                    $mensajeDeudas = sprintf(
                        'Se han actualizado las deudas: %d creadas, %d eliminadas.',
                        $resultadoActualizacionDeudas['creadas'],
                        $resultadoActualizacionDeudas['eliminadas']
                    );
                    
                    $this->addFlash('success', $mensajeDeudas);
                }
                
                // Guardar los cambios en el curso
                $cursoRepository->add($curso);
                $entityManager->flush();

                // Consumir tokens después de guardar exitosamente
                $this->tokenService->consumeTokens(
                    $instituto,
                    'curso.edit',
                    $this->getUser(),
                    'Editar curso: ' . $curso->getNombre(),
                    'Curso',
                    $curso->getId()
                );

                return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Ocurrió un error al actualizar el curso: ' . $e->getMessage());
            }
        } elseif ($form->isSubmitted()) {
            $errors = $form->getErrors(true);
            foreach ($errors as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->renderForm('curso/edit.html.twig', [
            'curso' => $curso,
            'form' => $form,
            'mostrar_confirmacion_conflictos' => $mostrarConfirmacionConflictos,
            'date_format' => $dateFormat,
        ]);
    }

    /**
     * @Route("/{id}", name="app_curso_delete", methods={"POST"})
     */
    public function delete(Request $request, Curso $curso, CursoRepository $cursoRepository): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        if ($curso->getAlumnos()->count() > 0) {
            $this->addFlash('danger', 'El curso tiene alumnos asociados y no puede ser eliminado.');
            return $this->redirectToRoute('app_curso_index');
        }

        // Verificar tokens antes de eliminar
        if (!$this->tokenService->hasEnoughTokens($instituto, 'curso.delete')) {
            $this->addFlash('danger', 'No tienes suficientes tokens para eliminar un curso. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
            return $this->redirectToRoute('app_curso_index');
        }

        if ($this->isCsrfTokenValid('delete'.$curso->getId(), $request->request->get('_token'))) {
            $cursoRepository->remove($curso);
            
            // Consumir tokens después de eliminar exitosamente
            $this->tokenService->consumeTokens(
                $instituto,
                'curso.delete',
                $this->getUser(),
                'Eliminar curso: ' . $curso->getNombre(),
                'Curso',
                $curso->getId()
            );
        }

        return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @Route("/habilitar/{id}", name="app_curso_habilitar", methods={"GET"})
     */
    public function habilitar(Request $request, Curso $curso, CursoRepository $cursoRepository, \App\Service\DeudaService $deudaService, EntityManagerInterface $entityManager): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        
        // Habilitar el curso
        $cursoRepository->habilitar($curso);
        
        // Verificar y generar deudas para todos los alumnos del curso
        $totalDeudas = 0;
        $alumnosAfectados = 0;
        
        foreach ($curso->getAlumnos() as $alumno) {
            // Buscar el histórico activo para este alumno y curso
            $historico = $entityManager->getRepository('App\Entity\AlumnoCursoHistorico')
                ->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'activo' => true
                ]);
                
            // Si no hay histórico activo, crear uno nuevo
            if (!$historico) {
                $historico = new AlumnoCursoHistorico();
                $historico->setAlumno($alumno);
                $historico->setCurso($curso);
                $historico->setFechaAlta(new \DateTime());
                $historico->setActivo(true);
                $entityManager->persist($historico);
            }
            
            // Generar deudas usando las fechas del curso
            $resultado = $deudaService->generarDeudasParaHistorico($historico);
            
            $totalDeudas += $resultado['creadas'];
            if ($resultado['creadas'] > 0) {
                $alumnosAfectados++;
            }
        }
        
        if ($totalDeudas > 0) {
            $this->addFlash('success', sprintf(
                'Se han generado %d deudas para %d alumnos inscritos en el curso %s', 
                $totalDeudas, 
                $alumnosAfectados,
                $curso->getNombre()
            ));
        }

        return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @Route("/deshabilitar/{id}", name="app_curso_deshabilitar", methods={"GET"})
     */
    public function deshabilitar(Request $request, Curso $curso, CursoRepository $cursoRepository, \App\Service\DeudaService $deudaService): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_curso_index');
        }
        
        // Deshabilitar el curso (usando el método remove que en realidad lo marca como deshabilitado)
        $cursoRepository->remove($curso);
        
        // Calcular fecha actual para cancelar solo deudas futuras
        $fechaActual = new \DateTime();
        
        // Actualizar las deudas por finalización del curso
        $deudasCanceladas = $deudaService->actualizarDeudasPorFinalizacionCurso($curso, $fechaActual);
        
        if ($deudasCanceladas > 0) {
            $this->addFlash('success', sprintf('Se han cancelado %d deudas futuras para el curso %s', $deudasCanceladas, $curso->getNombre()));
        }
        
        return $this->redirectToRoute('app_curso_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Obtiene la fecha y hora de inicio del curso para un día específico
     */
    private function getHoraInicio($curso, $dia): string
    {
        $horaInicio = $curso->getHorarioInicio();
        if (!$horaInicio) {
            throw new \Exception('Horario de inicio no establecido');
        }

        $diaSemana = $this->getDiaSemana($dia);
        
        // Obtener la fecha del próximo día de la semana
        $fecha = new \DateTime();
        
        $fecha->modify('next ' . $diaSemana);
        
        // Combinar la fecha con la hora de inicio
        $fecha->setTime($horaInicio->format('H'), $horaInicio->format('i'));
        
        return $fecha->format('Y-m-d\TH:i:s');
    }

    /**
     * Obtiene la fecha y hora de fin del curso para un día específico
     */
    private function getHoraFin($curso, $dia): string
    {
        $horaFin = $curso->getHorarioFin();
        if (!$horaFin) {
            throw new \Exception('Horario de fin no establecido');
        }

        $diaSemana = $this->getDiaSemana($dia);
        
        // Obtener la fecha del próximo día de la semana
        $fecha = new \DateTime();
        $fecha->modify('next ' . $diaSemana);
        
        // Combinar la fecha con la hora de fin
        $fecha->setTime($horaFin->format('H'), $horaFin->format('i'));
        
        return $fecha->format('Y-m-d\TH:i:s');
    }

    /**
     * Convierte el nombre del día en español al formato de PHP
     */
    private function getDiaSemana($dia): string
    {
        $dias = [
            'Lunes' => 'monday',
            'Martes' => 'tuesday',
            'Miercoles' => 'wednesday',
            'Jueves' => 'thursday',
            'Viernes' => 'friday',
            'Sábado' => 'saturday',
            'Domingo' => 'sunday'
        ];
        
        return $dias[$dia];
    }

    /**
     * Quita de la colección del curso los horarios sin día o sin inicio/fin (filas vacías del formulario).
     */
    private function removerHorariosVacios(Curso $curso): void
    {
        $toRemove = [];
        foreach ($curso->getHorarios() as $h) {
            if (!$h->getDia() || !$h->getHorarioInicio() || !$h->getHorarioFin()) {
                $toRemove[] = $h;
            }
        }
        foreach ($toRemove as $h) {
            $curso->removeHorario($h);
        }
    }

    /**
     * Calcula la duración en horas entre dos horarios
     * @return float Duración en formato decimal (ej: 1.5 para 1 hora y 30 minutos)
     */
    private function calcularDuracion(\DateTime $inicio, \DateTime $fin): float
    {
        $intervalo = $inicio->diff($fin);
        $horas = $intervalo->h;
        $minutos = $intervalo->i;
        
        // Convertir a formato decimal (ej: 1:30 -> 1.5)
        return $horas + ($minutos / 60);
    }

    /**
     * @Route("/api/cursos/{id}/precio", name="app_curso_precio", methods={"GET"})
     */
    public function getPrecio(CursoRepository $cursoRepository, int $id): JsonResponse
    {
        $curso = $cursoRepository->find($id);
        
        if (!$curso) {
            return $this->json(['error' => 'Curso no encontrado'], 404);
        }

        return $this->json([
            'precio' => $curso->getPrecio()
        ]);
    }

}
