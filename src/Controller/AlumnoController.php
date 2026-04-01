<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Form\AlumnoType;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
use App\Repository\EmailLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\HistorialCursosService;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\AsistenciaAlumnosRepository;
use App\Service\DeudaService;
use App\Service\InstitutoTimezoneService;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @Route("/instituto/alumno")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class AlumnoController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private HistorialCursosService $historialCursosService;
    private DeudaService $deudaService;
    private InstitutoTimezoneService $institutoTimezoneService;
    private UserPasswordHasherInterface $passwordHasher;
    private \App\Service\DeudaCalculatorService $deudaCalculator;

    public function __construct(
        EntityManagerInterface $entityManager,
        HistorialCursosService $historialCursosService,
        DeudaService $deudaService,
        InstitutoTimezoneService $institutoTimezoneService,
        UserPasswordHasherInterface $passwordHasher,
        \App\Service\DeudaCalculatorService $deudaCalculator
    ) {
        $this->entityManager = $entityManager;
        $this->historialCursosService = $historialCursosService;
        $this->deudaService = $deudaService;
        $this->institutoTimezoneService = $institutoTimezoneService;
        $this->passwordHasher = $passwordHasher;
        $this->deudaCalculator = $deudaCalculator;
    }

    /**
     * @Route("/", name="app_alumno_index", methods={"GET", "POST"})
     */
    public function index(Request $request, AlumnoRepository $alumnoRepository, CursoRepository $cursoRepository, PaginatorInterface $paginator): Response
    {
        $limit = $request->get('limit', 10);
        $currentPage = $request->get('page', 1);
        $busqueda = $request->get('busqueda', '');
        $order = $request->get('order', 'asc');
        $sort = $request->get('sort', 'apellido');
        $activo = $request->get('activo', 'todos');
        $cursoSelected = $request->get('cursoSelected', '0');
        $totalAgregados = $request->get('totalAgregados', '');
        $alumnosQueNoGuardadamos = $request->get('alumnosQueNoGuardadamos', []);

        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        $instituto = $user->getInstituto();
        
        $alumnosQuery = $this->createQuery($alumnoRepository, $instituto, $sort, $order, $busqueda, $activo, $cursoSelected);

        $alumnos = $paginator->paginate(
            $alumnosQuery, 
            $currentPage, 
            $limit
        );

        // Sincronizar deudas calculadas on-demand con la tabla para cada alumno de esta página
        // Esto asegura que la campanita y otros indicadores funcionen correctamente
        foreach ($alumnos as $alumno) {
            if ($alumno->getActivo()) {
                $this->deudaCalculator->sincronizarDeudasConTabla($alumno);
            }
        }
        
        // Refrescar entity manager para cargar las deudas sincronizadas
        $this->entityManager->clear();
        
        // Recargar alumnos con las deudas sincronizadas
        $alumnosQuery = $this->createQuery($alumnoRepository, $instituto, $sort, $order, $busqueda, $activo, $cursoSelected);
        $alumnos = $paginator->paginate(
            $alumnosQuery, 
            $currentPage, 
            $limit
        );

        return $this->render('alumno/index.html.twig', [
            'alumnos' => $alumnos,
            'busqueda' => $busqueda,
            'total' => $alumnos->getTotalItemCount(),
            'order' => $order,
            'sort' => $sort,
            'activo' => $activo,
            'totalAgregados' => $totalAgregados,
            'alumnosQueNoGuardadamos' => $alumnosQueNoGuardadamos,
            'cursos' => $cursoRepository->findBy(['instituto' => $instituto]),
            'cursoSelected' => $cursoSelected
        ]);
    }

    /**
     * Crea una consulta para obtener alumnos con los filtros especificados
     */
    private function createQuery(
        AlumnoRepository $alumnoRepository,
        $instituto,
        string $sort,
        string $order,
        ?string $busqueda = null,
        ?string $activo = null,
        ?int $cursoSelected = null
    ) {
        $qb = $alumnoRepository->createQueryBuilder('a')
            ->distinct()
            ->leftJoin('a.curso', 'c')
            ->leftJoin('a.deudas', 'd')
            ->addSelect('d')
            ->leftJoin('d.aplicaciones', 'ap')
            ->addSelect('ap')
            ->where('a.instituto = :instituto')
            ->setParameter('instituto', $instituto);

        if ($busqueda) {
            $qb->andWhere('a.apellido LIKE :busqueda OR a.nombre LIKE :busqueda')
               ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        if ($activo != 'todos') {
            $qb->andWhere('a.activo = :activo')
                ->setParameter('activo', $activo);
        }

        if ($cursoSelected != 0) {
            $qb->andWhere('c.id = :curso')
                ->setParameter('curso', $cursoSelected);
        }

        // Ordenamiento por nombre o apellido
        if ($sort === 'nombre') {
            $qb->orderBy('a.nombre', $order);
        } else {
            $qb->orderBy('a.apellido', $order);
        }

        return $qb;
    }

    /**
     * @Route("/new", name="app_alumno_new", methods={"GET", "POST"})
     */
    public function new(Request $request, AlumnoRepository $alumnoRepository, DeudaService $deudaService): Response
    {
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        $instituto = $user->getInstituto();
        $alumno = new Alumno();
        $alumno->setInstituto($instituto);

        $form = $this->createForm(AlumnoType::class, $alumno, ['is_edit' => false, 'instituto' => $instituto]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {

                try {
                    $modoGeneracionDeuda = $form->get('modoGeneracionDeuda')->getData() ?? 'inscripcion';
                    $comenzarDeudaProximoMes = ($modoGeneracionDeuda === 'proximo_mes');
                    $email = $alumno->getEmail();
                    
                    // Verificar si el email ya existe en usuarios
                    $usuarioExistente = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
                    if ($usuarioExistente) {
                        $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                        return $this->renderForm('alumno/new.html.twig', [
                            'alumno' => $alumno,
                            'form' => $form,
                            'hermanos' => $alumno->getHermanos()
                        ]);
                    }
                    
                    // Crear el usuario para el alumno (opcional - solo si se quiere permitir login)
                    // Por ahora lo creamos pero sin rol específico, se puede activar después
                    $user = new User();
                    $user->setEmail($email);
                    $user->setRoles(['ROLE_ALUMNO']); // Rol para alumnos
                    $user->setInstituto($instituto);
                    
                    // Generar una contraseña temporal (se puede cambiar después)
                    $plainPassword = bin2hex(random_bytes(4)); // Genera una contraseña aleatoria de 8 caracteres
                    $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
                    $user->setPassword($hashedPassword);

                    // Establecer la relación bidireccional
                    $user->setAlumno($alumno);
                    $alumno->setUser($user);

                    // Guardar el usuario primero
                    $this->entityManager->persist($user);
                    
                    // Guardar el alumno
                    $alumnoRepository->add($alumno);
                    
                    // Crear historial para cada curso seleccionado y generar deudas
                    foreach ($alumno->getCurso() as $curso) {
                        // Utilizamos el método mejorado, que ya maneja la generación de deudas básicas
                        $historico = $this->historialCursosService->crearHistorialConDeudasHastaFinDeAno(
                            $alumno,
                            $curso,
                            $comenzarDeudaProximoMes,
                            $modoGeneracionDeuda
                        );
                        
                        // Asegurarnos de generar las deudas hasta fin de año
                        $finDeAno = $this->getFinDeAnoInstituto($instituto);
                        $fechaInicioDeuda = $this->resolverFechaInicioDeuda($curso, $comenzarDeudaProximoMes);
                        $deudaService->generarDeudasParaPeriodo(
                            $alumno,
                            $curso,
                            $historico,
                            $fechaInicioDeuda,
                            $finDeAno,
                            false // No sobrescribir deudas existentes
                        );
                    }
                    
                    // setearHermandad ahora se maneja automáticamente por el transformer del formulario
                    // Solo necesitamos asegurarnos de que los hermanos se guarden correctamente
                    // El transformer ya convirtió las entidades a IDs en el campo hermanos
                    // Pero necesitamos establecer la relación bidireccional
                    $this->setearHermandadBidireccional($alumno, $alumnoRepository);
                    
                    // Guardar todo
                    $this->entityManager->flush();

                    $this->addFlash('success', 'Alumno creado correctamente.');
                    // Usuario creado - la información está en el formulario
                    return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'UNIQ_') !== false) {
                        $this->addFlash('danger', 'Ya existe un alumno con ese DNI.');
                    } else {
                        $this->addFlash('danger', 'Ocurrió un error al crear el alumno. ' . $e->getMessage());
                    }
                }
            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->renderForm('alumno/new.html.twig', [
            'alumno' => $alumno,
            'form' => $form,
            'hermanos' => $alumno->getHermanos()
        ]);
    }

    /**
     * @Route("/asistencias", name="app_instituto_asistencias_index", methods={"GET"})
     */
    public function asistencias(Request $request, AsistenciaAlumnosRepository $asistenciaRepository, CursoRepository $cursoRepository, InstitutoTimezoneService $institutoTimezoneService): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $fechaHoy = $institutoTimezoneService->getTodayForInstituto($instituto);

        // Obtener fecha del formulario o usar la fecha actual (zona horaria del instituto)
        $fecha = $request->get('fecha', $fechaHoy);
        $cursoId = $request->get('curso');

        // Obtener todos los cursos del instituto
        $cursos = $cursoRepository->findByInstitutoSoloActivos($instituto);
        
        // Si se seleccionó un curso, obtener las asistencias de ese curso para la fecha seleccionada
        if ($cursoId) {
            $curso = $cursoRepository->find($cursoId);
            
            // Verificar que el curso pertenece al instituto
            if ($curso->getInstituto() !== $instituto) {
                throw $this->createAccessDeniedException('No tiene acceso a este curso.');
            }
            
            $asistencias = $asistenciaRepository->findByCursoAndDate($curso, new \DateTime($fecha));
            
            // Obtener todos los alumnos del curso
            $alumnos = $curso->getAlumnos();
            
            // Crear un array con todos los alumnos y su estado de asistencia
            $asistenciasPorAlumno = [];
            foreach ($alumnos as $alumno) {
                $asistencia = $asistenciaRepository->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'fecha' => new \DateTime($fecha)
                ]);
                
                $asistenciasPorAlumno[] = [
                    'alumno' => $alumno,
                    'presente' => $asistencia ? $asistencia->getPresente() : false,
                    'observaciones' => $asistencia ? $asistencia->getObservaciones() : ''
                ];
            }
            
            return $this->render('asistencia_instituto/index.html.twig', [
                'asistenciasPorAlumno' => $asistenciasPorAlumno,
                'fecha' => $fecha,
                'fechaHoy' => $fechaHoy,
                'cursos' => $cursos,
                'cursoSeleccionado' => $curso
            ]);
        }

        // Si no se seleccionó un curso, mostrar la lista de cursos
        return $this->render('asistencia_instituto/index.html.twig', [
            'fecha' => $fecha,
            'fechaHoy' => $fechaHoy,
            'cursos' => $cursos,
            'cursoSeleccionado' => null
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumno_show", methods={"GET"})
     */
    public function show(Alumno $alumno, AlumnoRepository $alumnoRepository, EmailLogRepository $emailLogRepository): Response
    {
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        
        // Verificar que el alumno pertenece al instituto del usuario
        if ($alumno->getInstituto() !== $user->getInstituto()) {
            $this->addFlash('danger', 'No tienes acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        $hermanos = [];
        foreach ($alumno->getHermanos() as $hermanoId) {
            $hermano = $alumnoRepository->find($hermanoId);
            if ( $hermano !== null ) {
                $hermanos[] = $hermano;
            }
        }
        
        // Buscar el último recordatorio enviado a este alumno
        $ultimoRecordatorio = $emailLogRepository->createQueryBuilder('e')
            ->andWhere('e.alumno = :alumno')
            ->andWhere('e.tipo = :tipo')
            ->setParameter('alumno', $alumno)
            ->setParameter('tipo', 'recordatorio')
            ->orderBy('e.fechaEnvio', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        return $this->render('alumno/show.html.twig', [
            'alumno' => $alumno,
            'hermanos' => $hermanos,
            'ultimoRecordatorio' => $ultimoRecordatorio
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_alumno_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Alumno $alumno, AlumnoRepository $alumnoRepository, DeudaService $deudaService): Response
    {
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        $instituto = $user->getInstituto();
        
        // Verificar que el alumno pertenece al instituto del usuario
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        $cursosActuales = $alumno->getCurso()->toArray();
        $estadoActivoPrevio = $alumno->getActivo();
        
        $form = $this->createForm(AlumnoType::class, $alumno, ['is_edit' => true, 'instituto' => $instituto]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {

                try {
                    $modoGeneracionDeuda = $form->get('modoGeneracionDeuda')->getData() ?? 'inscripcion';
                    $comenzarDeudaProximoMes = ($modoGeneracionDeuda === 'proximo_mes');
                    // Sincronizar email con el usuario asociado
                    if ($alumno->getUser()) {
                        $alumno->getUser()->setEmail($alumno->getEmail());
                    }
                    
                    // Verificar si el alumno pasó de activo a inactivo
                    if ($estadoActivoPrevio && !$alumno->getActivo()) {
                        // Verificar si tiene deudas pendientes
                        $infoDeudasPendientes = $deudaService->verificarDeudasPendientesAlumnoInactivo($alumno);
                        
                        if ($infoDeudasPendientes['totalDeudas'] > 0) {
                            // Mostrar advertencia pero permitir la operación
                            $this->addFlash('warning', sprintf(
                                'El alumno ha sido desactivado pero tiene %d deudas pendientes por un total de $%.2f. ' .
                                'Estas deudas se mantendrán en el sistema hasta que sean pagadas o canceladas manualmente.',
                                $infoDeudasPendientes['totalDeudas'],
                                $infoDeudasPendientes['montoPendiente']
                            ));
                        }
                    }
                    
                    // Obtener los cursos seleccionados
                    $cursosNuevos = $alumno->getCurso()->toArray();
                    
                    // Cursos a remover
                    foreach ($cursosActuales as $curso) {
                        if (!in_array($curso, $cursosNuevos)) {
                            // Marcar el historial como inactivo
                            $fechaActualInstituto = $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
                            $historico = $this->historialCursosService->buscarHistorial($alumno, $curso, $fechaActualInstituto);
                            if ($historico) {
                                $historico->setActivo(false);
                                $historico->setFechaBaja(clone $fechaActualInstituto);
                            }
                            
                            // Cancelar deudas pendientes (mes actual y futuras)
                            $deudasCanceladas = $deudaService->cancelarDeudasPendientesAlumnoCurso($alumno, $curso, true);
                            if ($deudasCanceladas > 0) {
                                $this->addFlash('info', sprintf('Se han cancelado %d deudas pendientes para el curso %s', $deudasCanceladas, $curso->getNombre()));
                            }
                            
                            $alumno->removeCurso($curso);
                        }
                    }
                    
                    // Cursos a agregar
                    foreach ($cursosNuevos as $curso) {
                        if (!in_array($curso, $cursosActuales)) {
                            $alumno->addCurso($curso);
                            // Crear nuevo historial y generar deudas
                            $historico = $this->historialCursosService->crearHistorialConDeudasHastaFinDeAno(
                                $alumno,
                                $curso,
                                $comenzarDeudaProximoMes,
                                $modoGeneracionDeuda
                            );
                            // Generar deudas hasta fin de año con DeudaService
                            $finDeAno = $this->getFinDeAnoInstituto($instituto);
                            $fechaInicioDeuda = $this->resolverFechaInicioDeuda($curso, $comenzarDeudaProximoMes);
                            
                            // Si el curso tiene fecha de finalización, usarla como límite
                            if (method_exists($curso, 'getFechaFin') && $curso->getFechaFin() !== null) {
                                $fechaFinCurso = $curso->getFechaFin();
                                if ($fechaFinCurso < $finDeAno) {
                                    $finDeAno = $fechaFinCurso;
                                }
                            }
                            
                            $deudaService->generarDeudasParaPeriodo(
                                $alumno,
                                $curso,
                                $historico,
                                $fechaInicioDeuda,
                                $finDeAno,
                                false
                            );
                        }
                    }
                    
                    $alumnoRepository->add($alumno);
                    // setearHermandad ahora se maneja automáticamente por el transformer del formulario
                    // Solo necesitamos asegurarnos de que los hermanos se guarden correctamente
                    // El transformer ya convirtió las entidades a IDs en el campo hermanos
                    // Pero necesitamos establecer la relación bidireccional
                    $this->setearHermandadBidireccional($alumno, $alumnoRepository);

                    $this->addFlash('success', 'Alumno actualizado correctamente.');
                    return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'UNIQ_') !== false && strpos($e->getMessage(), 'dni') !== false) {
                        $this->addFlash('danger', 'Ya existe un alumno con ese DNI.');
                    } else {
                        $this->addFlash('danger', 'Ocurrió un error al actualizar el alumno: ' . $e->getMessage());
                    }
                }
            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        // Obtener información de advertencia de eliminación si existe
        $deleteWarning = null;
        if ($request->getSession()->has('delete_alumno_warning')) {
            $warningData = $request->getSession()->get('delete_alumno_warning');
            // Verificar que la advertencia es para este alumno
            if ($warningData['alumno_id'] == $alumno->getId()) {
                $deleteWarning = $warningData;
            } else {
                // Limpiar si es para otro alumno
                $request->getSession()->remove('delete_alumno_warning');
            }
        }

        return $this->renderForm('alumno/edit.html.twig', [
            'alumno' => $alumno,
            'form' => $form,
            'hermanos' => $alumno->getHermanos(),
            'deleteWarning' => $deleteWarning
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumno_delete", methods={"POST"})
     */
    public function delete(Request $request, Alumno $alumno, AlumnoRepository $alumnoRepository, DeudaService $deudaService): Response
    {
        $user = $this->getUser();
        $instituto = $user->getInstituto();

        if (!$this->isCsrfTokenValid('delete'.$alumno->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('app_alumno_edit', ['id' => $alumno->getId()]);
        }

        // Verificar si es una eliminación forzada (después de mostrar advertencia)
        $forceDelete = $request->request->get('force_delete', false);

        // Verificar relaciones antes de eliminar (solo si no es forzado)
        if (!$forceDelete) {
            $cursosActivos = $alumno->getCurso()->toArray();
            $asistencias = $alumno->getAsistencias()->toArray();
            // Obtener todas las deudas y filtrar las que tienen monto pendiente
            $todasDeudas = $this->entityManager->getRepository(\App\Entity\DeudaAlumno::class)
                ->findBy(['alumno' => $alumno]);
            
            $deudasPendientes = array_filter($todasDeudas, function($deuda) {
                return $deuda->getMontoPendiente() > 0;
            });

            // Si hay relaciones, guardar información y redirigir para mostrar advertencia
            if (!empty($cursosActivos) || !empty($asistencias) || !empty($deudasPendientes)) {
                $request->getSession()->set('delete_alumno_warning', [
                    'alumno_id' => $alumno->getId(),
                    'cursos' => array_map(function($c) { return $c->getNombre(); }, $cursosActivos),
                    'asistencias_count' => count($asistencias),
                    'deudas' => array_map(function($d) {
                        return [
                            'curso' => $d->getCurso()->getNombre(),
                            'periodo' => $d->getMes() . '/' . $d->getAno(),
                            'monto' => $d->getMonto() + $d->getInteres()
                        ];
                    }, $deudasPendientes)
                ]);
                
                return $this->redirectToRoute('app_alumno_edit', ['id' => $alumno->getId()]);
            }
        }

        // Proceder con la eliminación
        // Remover relaciones con cursos
        foreach ($alumno->getCurso() as $curso) {
            // Cancelar todas las deudas pendientes
            $deudaService->cancelarDeudasPendientesAlumnoCurso($alumno, $curso, false);
            $alumno->removeCurso($curso);
        }

        // Desarmar relaciones de hermanos
        $hermanosActuales = $alumno->getHermanos();
        foreach ($hermanosActuales as $hermanoId) {
            $hermano = $alumnoRepository->find($hermanoId);
            if ($hermano) {
                $hermanosDelHermano = $hermano->getHermanos();
                if (($key = array_search($alumno->getId(), $hermanosDelHermano)) !== false) {
                    unset($hermanosDelHermano[$key]);
                    $hermano->setHermanos(array_values($hermanosDelHermano));
                    $alumnoRepository->add($hermano);
                }
            }
        }
        $alumno->setHermanos([]);
        
        // Eliminar asistencias asociadas
        foreach ($alumno->getAsistencias() as $asistencia) {
            $this->entityManager->remove($asistencia);
        }
        
        $alumnoRepository->remove($alumno);
        $this->entityManager->flush();

        // Limpiar la sesión si había advertencia
        $request->getSession()->remove('delete_alumno_warning');

        $this->addFlash('success', 'Alumno eliminado exitosamente.');
        return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Establece la relación bidireccional de hermanos
     * El transformer del formulario ya guardó los IDs en el campo hermanos del alumno actual
     * Este método se encarga de establecer la relación inversa (agregar este alumno como hermano de los otros)
     */
    private function setearHermandadBidireccional(Alumno $alumno, $alumnoRepository) {
        // Obtener los IDs de hermanos que ya están guardados en el alumno (después del transformer)
        $hermanosIdsNuevos = $alumno->getHermanos();
        
        // Obtener todos los alumnos del mismo instituto para buscar relaciones anteriores
        $todosAlumnos = $alumnoRepository->findBy(['instituto' => $alumno->getInstituto()]);
        
        // Limpiar todas las relaciones anteriores (remover este alumno de los hermanos anteriores)
        foreach ($todosAlumnos as $otroAlumno) {
            if ($otroAlumno->getId() === $alumno->getId()) {
                continue; // Saltar el alumno actual
            }
            
            $hermanosDelOtro = $otroAlumno->getHermanos();
            if (in_array($alumno->getId(), $hermanosDelOtro)) {
                // Este alumno estaba como hermano del otro, pero ahora puede que no lo sea
                // Si no está en la nueva lista, removerlo
                if (!in_array($otroAlumno->getId(), $hermanosIdsNuevos)) {
                    $key = array_search($alumno->getId(), $hermanosDelOtro);
                    if ($key !== false) {
                        unset($hermanosDelOtro[$key]);
                        $otroAlumno->setHermanos(array_values($hermanosDelOtro));
                        $alumnoRepository->add($otroAlumno);
                    }
                }
            }
        }
        
        // Establecer las nuevas relaciones bidireccionales
        foreach ($hermanosIdsNuevos as $hermanoId) {
            $hermano = $alumnoRepository->find($hermanoId);
            if ($hermano && $hermano->getId() !== $alumno->getId()) {
                // Agregar el alumno actual como hermano del otro
                $hermanosDelHermano = $hermano->getHermanos();
                if (!in_array($alumno->getId(), $hermanosDelHermano)) {
                    $hermanosDelHermano[] = $alumno->getId();
                    $hermano->setHermanos(array_values($hermanosDelHermano));
                    $alumnoRepository->add($hermano);
                }
            }
        }
    }
    
    /**
     * @deprecated Este método ya no se usa, se reemplazó por setearHermandadBidireccional
     * El transformer del formulario ahora maneja la conversión automáticamente
     */
    private function setearHermandad($request, Alumno $alumno, $alumnoRepository) {
        // Este método se mantiene por compatibilidad pero ya no se usa
        // El transformer del formulario maneja la conversión automáticamente
        $this->setearHermandadBidireccional($alumno, $alumnoRepository);
    }

    /**
     * @Route("/{id}/update-cursos", name="app_alumno_update_cursos", methods={"POST"})
     */
    public function updateCursos(Request $request, Alumno $alumno, CursoRepository $cursoRepository, HistorialCursosService $historialCursosService, DeudaService $deudaService, InstitutoTimezoneService $institutoTimezoneService): JsonResponse
    {
        $instituto = $this->getUser()->getInstituto();
        $dateFormat = $institutoTimezoneService->getDateFormatForInstituto($instituto);
        $cursoIds = $request->request->get('cursos', []);
        $modoGeneracionDeuda = $request->request->get('modo_generacion_deuda', 'inscripcion');
        $comenzarDeudaProximoMes = ($modoGeneracionDeuda === 'proximo_mes');
        
        // Obtener los cursos seleccionados
        $cursosSeleccionados = $cursoRepository->findBy(['id' => $cursoIds, 'instituto' => $instituto]);
        
        // Obtener los cursos actuales del alumno
        $cursosActuales = $alumno->getCurso();
        
        $mensajes = [];
        
        // Cursos a remover
        foreach ($cursosActuales as $curso) {
            if (!in_array($curso, $cursosSeleccionados)) {
                // Marcar el historial como inactivo
                $fechaActualInstituto = $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
                $historico = $historialCursosService->buscarHistorial($alumno, $curso, $fechaActualInstituto);
                if ($historico) {
                    $historico->setActivo(false);
                    $historico->setFechaBaja(clone $fechaActualInstituto);
                }
                
                // Cancelar deudas pendientes (mes actual y futuras)
                $deudasCanceladas = $deudaService->cancelarDeudasPendientesAlumnoCurso($alumno, $curso, true);
                if ($deudasCanceladas > 0) {
                    $mensajes[] = sprintf('Se han cancelado %d deudas pendientes para el curso %s', $deudasCanceladas, $curso->getNombre());
                }
                
                $alumno->removeCurso($curso);
            }
        }
        
        // Cursos a agregar
        foreach ($cursosSeleccionados as $curso) {
            if (!$cursosActuales->contains($curso)) {
                $alumno->addCurso($curso);
                // Crear nuevo historial y generar deudas
                $historico = $historialCursosService->crearHistorialConDeudasHastaFinDeAno(
                    $alumno,
                    $curso,
                    $comenzarDeudaProximoMes,
                    $modoGeneracionDeuda
                );
                
                // IMPORTANTE: Generar deudas solo hasta el mes actual, no hasta fin de año
                // Las deudas futuras se generarán automáticamente mediante el comando cron mensual
                $fechaActual = $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
                $fechaInicioDeuda = $this->resolverFechaInicioDeuda($curso, $comenzarDeudaProximoMes);
                
                // Determinar fecha límite: mes actual o fin del curso (lo que sea menor)
                $fechaLimite = clone $fechaActual;
                $fechaLimite->modify('last day of this month');
                
                // Si el curso tiene fecha de finalización y es anterior al mes actual, usar esa fecha
                if (method_exists($curso, 'getFechaFin') && $curso->getFechaFin() !== null) {
                    $fechaFinCurso = $curso->getFechaFin();
                    if ($fechaFinCurso < $fechaLimite) {
                        $fechaLimite = clone $fechaFinCurso;
                        $fechaLimite->modify('last day of this month');
                    }
                }
                
                // Generar deudas hasta la fecha límite (mes actual o fin del curso)
                $resultado = $deudaService->generarDeudasParaPeriodo(
                    $alumno,
                    $curso,
                    $historico,
                    $fechaInicioDeuda,
                    $fechaLimite,
                    false
                );
                
                $detallesMensaje = sprintf(
                    'Se ha inscrito al alumno en el curso %s y generado %d deudas hasta el mes actual',
                    $curso->getNombre(),
                    $resultado['creadas']
                );
                
                if ($comenzarDeudaProximoMes) {
                    $detallesMensaje .= ' (comenzando desde el próximo mes)';
                }
                
                $mensajes[] = $detallesMensaje;
            }
                    }
                    
                    $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Cursos actualizados correctamente',
            'detalles' => $mensajes
        ]);
    }

    /**
     * @Route("/{id}/deudas", name="app_alumno_deudas", methods={"GET"})
     */
    public function verDeudas(Alumno $alumno, CursoRepository $cursoRepository, \App\Service\DeudaCalculatorService $deudaCalculator): Response
    {
        // Verificar que el alumno pertenece al instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        // Calcular deudas on-demand desde el historial (cursos activos)
        $deudasCalculadas = $deudaCalculator->calcularDeudasAlumno($alumno);
        
        // Agrupar las deudas por curso
        $deudasPorCurso = [];
        foreach ($deudasCalculadas as $deuda) {
            $cursoId = $deuda['curso']->getId();
            if (!isset($deudasPorCurso[$cursoId])) {
                $deudasPorCurso[$cursoId] = [
                    'curso' => $deuda['curso'],
                    'historico' => $deuda['cursoHistorico'],
                    'deudas' => [],
                    'cursoActivo' => true
                ];
            }
            
            $deudasPorCurso[$cursoId]['deudas'][] = $deuda;
        }
        
        $fechaActualInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $diaActualInstituto = (int) $fechaActualInstituto->format('j');
        $mesActualInstituto = (int) $fechaActualInstituto->format('n');
        $anoActualInstituto = (int) $fechaActualInstituto->format('Y');

        // Agregar cursos activos que no tengan deudas pendientes
        foreach ($alumno->getCursosHistoricos() as $historico) {
            if (!$historico->isActivo()) {
                continue;
            }
            $cursoId = $historico->getCurso()->getId();
            if (!isset($deudasPorCurso[$cursoId])) {
                $deudasPorCurso[$cursoId] = [
                    'curso' => $historico->getCurso(),
                    'historico' => $historico,
                    'deudas' => [],
                    'cursoActivo' => true
                ];
            }
        }

        $primerDiaVencimiento = 5;
        $vencimientos = $instituto->getVencimientos();
        if (count($vencimientos) > 0) {
            $vencimientosArray = $vencimientos->toArray();
            usort($vencimientosArray, function($a, $b) {
                return $a->getDiaVencimiento() <=> $b->getDiaVencimiento();
            });
            $primerDiaVencimiento = (int) $vencimientosArray[0]->getDiaVencimiento();
        }

        // Filtrar deudas futuras: solo mostrar deudas vencidas y del mes actual
        foreach ($deudasPorCurso as $cursoId => &$datos) {
            $datos['deudas'] = array_values(array_filter($datos['deudas'], function($deuda) use ($mesActualInstituto, $anoActualInstituto) {
                $mes = (int) $deuda['mes'];
                $ano = (int) $deuda['ano'];
                // Mantener solo deudas del mes actual o anteriores
                return $ano < $anoActualInstituto || ($ano === $anoActualInstituto && $mes <= $mesActualInstituto);
            }));
        }
        unset($datos);

        return $this->render('alumno/deudas.html.twig', [
            'alumno' => $alumno,
            'deudasPorCurso' => $deudasPorCurso,
            'cursos' => $cursoRepository->findBy(['instituto' => $instituto]),
            'diaActualInstituto' => $diaActualInstituto,
            'mesActualInstituto' => $mesActualInstituto,
            'anoActualInstituto' => $anoActualInstituto,
            'primerDiaVencimiento' => $primerDiaVencimiento,
        ]);
    }

    /**
     * @Route("/{id}/cancelar-deuda/{cursoId}/{mes}/{ano}", name="app_alumno_cancelar_deuda", methods={"POST"})
     */
    public function cancelarDeuda(Alumno $alumno, int $cursoId, int $mes, int $ano, Request $request): Response
    {
        // Capturar return_url si existe
        $returnUrl = $request->query->get('return_url');
        
        // Verificar token CSRF
        $tokenId = 'cancelar-deuda' . $cursoId . '-' . $mes . '-' . $ano;
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_alumno_deudas', [
                'id' => $alumno->getId(),
                'return_url' => $returnUrl
            ]);
        }
        
        // Verificar que el alumno pertenece al instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        // Obtener el curso
        $curso = $this->entityManager->getRepository('App\Entity\Curso')->find($cursoId);
        if (!$curso || $curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no existe o no pertenece a este instituto.');
            return $this->redirectToRoute('app_alumno_deudas', [
                'id' => $alumno->getId(),
                'return_url' => $returnUrl
            ]);
        }
        
        try {
            // Obtener el precio mensual del historial activo para este curso
            $precioMensual = 0;
            foreach ($alumno->getCursosHistoricos() as $historico) {
                if ($historico->getCurso()->getId() === $cursoId && $historico->isActivo()) {
                    $precioMensual = (float) ($historico->getPrecioMensual() ?? $curso->getPrecio());
                    break;
                }
            }
            
            if ($precioMensual <= 0) {
                $precioMensual = (float) $curso->getPrecio();
            }
            
            // Calcular cuánto ya se pagó para este mes
            $pagosExistentes = $this->entityManager->createQueryBuilder()
                ->select('COALESCE(SUM(p.monto), 0)')
                ->from(\App\Entity\AlumnosPagos::class, 'p')
                ->where('p.alumno = :alumno')
                ->andWhere('p.curso = :curso')
                ->andWhere('p.mes = :mes')
                ->andWhere('p.ano = :ano')
                ->setParameter('alumno', $alumno)
                ->setParameter('curso', $curso)
                ->setParameter('mes', $mes)
                ->setParameter('ano', $ano)
                ->getQuery()
                ->getSingleScalarResult();
            
            $saldoPendiente = $precioMensual - (float) $pagosExistentes;
            
            if ($saldoPendiente <= 0.01) {
                $this->addFlash('info', 'Esta deuda ya está pagada.');
                return $this->redirectToRoute('app_alumno_deudas', [
                    'id' => $alumno->getId(),
                    'return_url' => $returnUrl
                ]);
            }
            
            // Registrar un pago de condonación por el saldo pendiente
            $pago = new \App\Entity\AlumnosPagos();
            $pago->setAlumno($alumno);
            $pago->setCurso($curso);
            $pago->setMes($mes);
            $pago->setAno($ano);
            $pago->setMonto($saldoPendiente);
            $pago->setMetodoPago('condonacion');
            $pago->setFecha($this->institutoTimezoneService->getCurrentDateForInstituto($instituto));
            $pago->setObservacion('Deuda condonada/cancelada manualmente');
            
            // Asociar historial del curso
            foreach ($alumno->getCursosHistoricos() as $historico) {
                if ($historico->getCurso()->getId() === $cursoId) {
                    $pago->setCursoHistorico($historico);
                    break;
                }
            }
            
            $this->entityManager->persist($pago);
            $this->entityManager->flush();
            
            $this->addFlash('success', sprintf(
                'Se ha condonado la deuda de %s para el curso %s, periodo %s %s.', 
                $alumno->getNombreApellido(),
                $curso->getNombre(),
                $this->getNombreMes($mes),
                $ano
            ));
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Ocurrió un error al cancelar la deuda: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_alumno_deudas', [
            'id' => $alumno->getId(),
            'return_url' => $returnUrl
        ]);
    }

    /**
     * @Route("/{id}/cancelar-deudas-curso/{cursoId}", name="app_alumno_cancelar_deudas_curso", methods={"POST"})
     */
    public function cancelarDeudasCurso(Alumno $alumno, int $cursoId, Request $request): Response
    {
        // Capturar return_url si existe
        $returnUrl = $request->query->get('return_url');
        
        // Verificar token CSRF
        if (!$this->isCsrfTokenValid('cancelar-deudas-curso'.$cursoId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_alumno_deudas', [
                'id' => $alumno->getId(),
                'return_url' => $returnUrl
            ]);
        }
        
        // Verificar que el alumno pertenece al instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        // Obtener el curso
        $curso = $this->entityManager->getRepository('App\Entity\Curso')->find($cursoId);
        
        // Verificar que el curso existe y pertenece al instituto
        if (!$curso || $curso->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El curso no existe o no pertenece a este instituto.');
            return $this->redirectToRoute('app_alumno_deudas', [
                'id' => $alumno->getId(),
                'return_url' => $returnUrl
            ]);
        }
        
        try {
            // Cancelar todas las deudas pendientes del alumno para este curso
            $soloFuturas = $request->request->get('solo_futuras', false);
            $deudasCanceladas = $this->deudaService->cancelarDeudasPendientesAlumnoCurso($alumno, $curso, $soloFuturas);
            
            if ($deudasCanceladas > 0) {
                $this->addFlash('success', sprintf(
                    'Se han cancelado %d deudas %s para el curso %s.', 
                    $deudasCanceladas,
                    $soloFuturas ? 'futuras' : 'pendientes',
                    $curso->getNombre()
                ));
            } else {
                $this->addFlash('info', sprintf(
                    'No se encontraron deudas %s para cancelar en el curso %s.', 
                    $soloFuturas ? 'futuras' : 'pendientes',
                    $curso->getNombre()
                ));
            }
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Ocurrió un error al cancelar las deudas: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_alumno_deudas', [
            'id' => $alumno->getId(),
            'return_url' => $returnUrl
        ]);
    }
    
    /**
     * Obtiene el nombre del mes según su número
     */
    private function resolverFechaInicioDeuda(\App\Entity\Curso $curso, bool $comenzarDeudaProximoMes): \DateTime
    {
        $inicio = $this->institutoTimezoneService->getCurrentDateForInstituto($curso->getInstituto());
        $inicio->modify('first day of this month');

        if ($comenzarDeudaProximoMes) {
            $inicio->modify('first day of next month');
        }

        $fechaInicioCurso = $curso->getFechaInicio();
        if ($fechaInicioCurso) {
            $fechaInicioCursoMes = clone $fechaInicioCurso;
            $fechaInicioCursoMes->modify('first day of this month');
            if ($fechaInicioCursoMes > $inicio) {
                $inicio = $fechaInicioCursoMes;
            }
        }

        return $inicio;
    }

    private function getFinDeAnoInstituto(\App\Entity\Instituto $instituto): \DateTime
    {
        $ahoraInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);

        return $this->institutoTimezoneService->normalizeDateOnly(
            $ahoraInstituto->setDate((int) $ahoraInstituto->format('Y'), 12, 31)
        );
    }

    /**
     * Obtiene el nombre del mes según su número
     */
    private function getNombreMes(int $mes): string
    {
        $meses = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre'
        ];
        
        return $meses[$mes] ?? 'Desconocido';
    }
}
