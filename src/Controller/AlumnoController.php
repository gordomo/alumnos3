<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Form\AlumnoType;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
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
use App\Service\TokenService;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @Route("/instituto/alumno")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class AlumnoController extends AbstractController
{
    private $entityManager;
    private $historialCursosService;
    private $deudaService;
    private $passwordHasher;
    private $tokenService;

    public function __construct(
        EntityManagerInterface $entityManager, 
        HistorialCursosService $historialCursosService,
        DeudaService $deudaService,
        UserPasswordHasherInterface $passwordHasher,
        TokenService $tokenService
    ) {
        $this->entityManager = $entityManager;
        $this->historialCursosService = $historialCursosService;
        $this->deudaService = $deudaService;
        $this->passwordHasher = $passwordHasher;
        $this->tokenService = $tokenService;
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
            ->leftJoin('a.curso', 'c')
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
                // Verificar tokens antes de crear
                if (!$this->tokenService->hasEnoughTokens($instituto, 'alumno.create')) {
                    $this->addFlash('danger', 'No tienes suficientes tokens para crear un alumno. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                    return $this->renderForm('alumno/new.html.twig', [
                        'alumno' => $alumno,
                        'form' => $form,
                        'hermanos' => $alumno->getHermanos()
                    ]);
                }

                try {
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
                        $historico = $this->historialCursosService->crearHistorialConDeudasHastaFinDeAno($alumno, $curso);
                        
                        // Asegurarnos de generar las deudas hasta fin de año
                        $fechaActual = new \DateTime();
                        $finDeAno = new \DateTime($fechaActual->format('Y') . '-12-31');
                        $deudaService->generarDeudasParaPeriodo(
                            $alumno,
                            $curso,
                            $historico,
                            $fechaActual,
                            $finDeAno,
                            false // No sobrescribir deudas existentes
                        );
                    }
                    
                    $this->setearHermandad($request, $alumno, $alumnoRepository);
                    
                    // Guardar todo
                    $this->entityManager->flush();
                    
                    // Consumir tokens después de guardar exitosamente
                    $this->tokenService->consumeTokens(
                        $instituto,
                        'alumno.create',
                        $this->getUser(),
                        'Crear alumno: ' . $alumno->getNombreApellido(),
                        'Alumno',
                        $alumno->getId()
                    );
                    
                    $this->addFlash('success', 'Alumno creado correctamente. La contraseña temporal para acceso es: ' . $plainPassword);
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
    public function asistencias(Request $request, AsistenciaAlumnosRepository $asistenciaRepository, CursoRepository $cursoRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Obtener fecha del formulario o usar la fecha actual
        $fecha = $request->get('fecha', date('Y-m-d'));
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
                'cursos' => $cursos,
                'cursoSeleccionado' => $curso
            ]);
        }
        
        // Si no se seleccionó un curso, mostrar la lista de cursos
        return $this->render('asistencia_instituto/index.html.twig', [
            'fecha' => $fecha,
            'cursos' => $cursos,
            'cursoSeleccionado' => null
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumno_show", methods={"GET"})
     */
    public function show(Alumno $alumno, AlumnoRepository $alumnoRepository): Response
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
        return $this->render('alumno/show.html.twig', [
            'alumno' => $alumno,
            'hermanos' => $hermanos
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
                // Verificar tokens antes de editar
                if (!$this->tokenService->hasEnoughTokens($instituto, 'alumno.edit')) {
                    $this->addFlash('danger', 'No tienes suficientes tokens para editar un alumno. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                    return $this->renderForm('alumno/edit.html.twig', [
                        'alumno' => $alumno,
                        'form' => $form,
                    ]);
                }

                try {
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
                            
                            // Si hay muchas deudas, mostrar detalle por curso
                            if (count($infoDeudasPendientes['deudasPorCurso']) > 0) {
                                foreach ($infoDeudasPendientes['deudasPorCurso'] as $deudaCurso) {
                                    $this->addFlash('info', sprintf(
                                        'Curso %s: %d cuotas pendientes por $%.2f',
                                        $deudaCurso['nombre'],
                                        $deudaCurso['cantidad'],
                                        $deudaCurso['monto']
                                    ));
                                }
                            }
                        }
                    }
                    
                    // Obtener los cursos seleccionados
                    $cursosNuevos = $alumno->getCurso()->toArray();
                    
                    // Cursos a remover
                    foreach ($cursosActuales as $curso) {
                        if (!in_array($curso, $cursosNuevos)) {
                            // Marcar el historial como inactivo
                            $historico = $this->historialCursosService->buscarHistorial($alumno, $curso, new \DateTime());
                            if ($historico) {
                                $historico->setActivo(false);
                                $historico->setFechaBaja(new \DateTime());
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
                            $historico = $this->historialCursosService->crearHistorialConDeudasHastaFinDeAno($alumno, $curso);
                            // Generar deudas hasta fin de año con DeudaService
                            $fechaActual = new \DateTime();
                            $finDeAno = new \DateTime($fechaActual->format('Y') . '-12-31');
                            
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
                                $curso->getFechaInicio() ?: $fechaActual, // Usar fecha de inicio del curso o actual si no tiene
                                $finDeAno,
                                false
                            );
                        }
                    }
                    
                    $alumnoRepository->add($alumno);
                    $this->setearHermandad($request, $alumno, $alumnoRepository);
                    
                    // Consumir tokens después de guardar exitosamente
                    $this->tokenService->consumeTokens(
                        $instituto,
                        'alumno.edit',
                        $this->getUser(),
                        'Editar alumno: ' . $alumno->getNombreApellido(),
                        'Alumno',
                        $alumno->getId()
                    );
                    
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

        return $this->renderForm('alumno/edit.html.twig', [
            'alumno' => $alumno,
            'form' => $form,
            'hermanos' => $alumno->getHermanos()
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumno_delete", methods={"POST"})
     */
    public function delete(Request $request, Alumno $alumno, AlumnoRepository $alumnoRepository, DeudaService $deudaService): Response
    {
        $user = $this->getUser();
        $instituto = $user->getInstituto();
        
        // Verificar tokens antes de eliminar
        if (!$this->tokenService->hasEnoughTokens($instituto, 'alumno.delete')) {
            $this->addFlash('danger', 'No tienes suficientes tokens para eliminar un alumno. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
            return $this->redirectToRoute('app_alumno_index');
        }
        
        if ($this->isCsrfTokenValid('delete'.$alumno->getId(), $request->request->get('_token'))) {
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
            $alumnoRepository->remove($alumno);
            
            // Consumir tokens después de eliminar exitosamente
            $this->tokenService->consumeTokens(
                $instituto,
                'alumno.delete',
                $this->getUser(),
                'Eliminar alumno: ' . $alumno->getNombreApellido(),
                'Alumno',
                $alumno->getId()
            );
        }

        return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
    }

    private function setearHermandad($request, Alumno $alumno, $alumnoRepository) {
        // Obtener los hermanos del formulario
        $hermanosForm = $request->request->get('alumno')['hermanos'] ?? [];
        
        // Eliminar duplicados
        $hermanosForm = array_unique($hermanosForm);
        
        // Limpiar todas las relaciones anteriores
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
        
        // Limpiar los hermanos del alumno actual
        $alumno->setHermanos([]);
        $alumnoRepository->add($alumno);
        
        // Establecer las nuevas relaciones
        foreach ($hermanosForm as $hermanoId) {
            $hermano = $alumnoRepository->find($hermanoId);
            if ($hermano && $hermano->getId() !== $alumno->getId()) {
                // Agregar el hermano al alumno actual
                $hermanosActuales = $alumno->getHermanos();
                if (!in_array($hermanoId, $hermanosActuales)) {
                    $hermanosActuales[] = $hermanoId;
                    $alumno->setHermanos($hermanosActuales);
                }
                
                // Agregar el alumno actual como hermano
                $hermanosDelHermano = $hermano->getHermanos();
                if (!in_array($alumno->getId(), $hermanosDelHermano)) {
                    $hermanosDelHermano[] = $alumno->getId();
                    $hermano->setHermanos($hermanosDelHermano);
                }
                
                $alumnoRepository->add($alumno);
                $alumnoRepository->add($hermano);
            }
        }
    }

    /**
     * @Route("/{id}/update-cursos", name="app_alumno_update_cursos", methods={"POST"})
     */
    public function updateCursos(Request $request, Alumno $alumno, CursoRepository $cursoRepository, HistorialCursosService $historialCursosService, DeudaService $deudaService): JsonResponse
    {
        $instituto = $this->getUser()->getInstituto();
        $cursoIds = $request->request->get('cursos', []);
        
        // Obtener los cursos seleccionados
        $cursosSeleccionados = $cursoRepository->findBy(['id' => $cursoIds, 'instituto' => $instituto]);
        
        // Obtener los cursos actuales del alumno
        $cursosActuales = $alumno->getCurso();
        
        $mensajes = [];
        
        // Cursos a remover
        foreach ($cursosActuales as $curso) {
            if (!in_array($curso, $cursosSeleccionados)) {
                // Marcar el historial como inactivo
                $historico = $historialCursosService->buscarHistorial($alumno, $curso, new \DateTime());
                if ($historico) {
                    $historico->setActivo(false);
                    $historico->setFechaBaja(new \DateTime());
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
                $historico = $historialCursosService->crearHistorialConDeudasHastaFinDeAno($alumno, $curso);
                
                // Determinar la fecha límite para generar deudas
                $fechaActual = new \DateTime();
                $finDeAno = new \DateTime($fechaActual->format('Y') . '-12-31');
                
                // Si el curso tiene fecha de finalización, usar la más cercana
                if (method_exists($curso, 'getFechaFin') && $curso->getFechaFin() !== null) {
                    $fechaFinCurso = $curso->getFechaFin();
                    if ($fechaFinCurso < $finDeAno) {
                        $finDeAno = $fechaFinCurso;
                    }
                }
                
                // Generar deudas hasta la fecha límite
                $resultado = $deudaService->generarDeudasParaPeriodo(
                    $alumno,
                    $curso,
                    $historico,
                    $curso->getFechaInicio() ?: $fechaActual, // Usar fecha de inicio del curso o actual si no tiene
                    $finDeAno,
                    false
                );
                
                $detallesMensaje = sprintf(
                    'Se ha inscrito al alumno en el curso %s y generado %d deudas mensuales',
                    $curso->getNombre(),
                    $resultado['creadas']
                );
                
                if (method_exists($curso, 'getFechaFin') && $curso->getFechaFin() !== null) {
                    $detallesMensaje .= sprintf(' hasta %s (fecha fin del curso)', $curso->getFechaFin()->format('d/m/Y'));
                } else {
                    $detallesMensaje .= sprintf(' hasta %s (fin del año)', $finDeAno->format('d/m/Y'));
                }
                
                $mensajes[] = $detallesMensaje;
            }
                    }
                    
                    $this->entityManager->flush();
                    
                    // Consumir tokens después de guardar exitosamente
                    $this->tokenService->consumeTokens(
                        $instituto,
                        'alumno.edit',
                        $this->getUser(),
                        'Editar alumno: ' . $alumno->getNombreApellido(),
                        'Alumno',
                        $alumno->getId()
                    );
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Cursos actualizados correctamente',
            'detalles' => $mensajes
        ]);
    }

    /**
     * @Route("/{id}/deudas", name="app_alumno_deudas", methods={"GET"})
     */
    public function verDeudas(Alumno $alumno, CursoRepository $cursoRepository): Response
    {
        // Verificar que el alumno pertenece al instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        // Obtener todas las deudas pendientes del alumno
        $deudasPendientes = $this->deudaService->getDeudasPendientesPorAlumno($alumno);
        
        // Agrupar las deudas por curso
        $deudasPorCurso = [];
        foreach ($deudasPendientes as $deuda) {
            $cursoId = $deuda->getCurso()->getId();
            if (!isset($deudasPorCurso[$cursoId])) {
                $deudasPorCurso[$cursoId] = [
                    'curso' => $deuda->getCurso(),
                    'deudas' => []
                ];
            }
            $deudasPorCurso[$cursoId]['deudas'][] = $deuda;
        }
        
        return $this->render('alumno/deudas.html.twig', [
            'alumno' => $alumno,
            'deudasPorCurso' => $deudasPorCurso,
            'cursos' => $cursoRepository->findBy(['instituto' => $instituto])
        ]);
    }

    /**
     * @Route("/{id}/cancelar-deuda/{deudaId}", name="app_alumno_cancelar_deuda", methods={"POST"})
     */
    public function cancelarDeuda(Alumno $alumno, int $deudaId, Request $request): Response
    {
        // Verificar token CSRF
        if (!$this->isCsrfTokenValid('cancelar-deuda'.$deudaId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_alumno_deudas', ['id' => $alumno->getId()]);
        }
        
        // Verificar que el alumno pertenece al instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        // Obtener la deuda
        $deuda = $this->entityManager->getRepository('App\Entity\DeudaAlumno')->find($deudaId);
        
        // Verificar que la deuda existe y pertenece al alumno
        if (!$deuda || $deuda->getAlumno() !== $alumno) {
            $this->addFlash('danger', 'La deuda no existe o no pertenece a este alumno.');
            return $this->redirectToRoute('app_alumno_deudas', ['id' => $alumno->getId()]);
        }
        
        // Verificar que la deuda no está pagada
        if ($deuda->isPagado()) {
            $this->addFlash('danger', 'Esta deuda ya está pagada.');
            return $this->redirectToRoute('app_alumno_deudas', ['id' => $alumno->getId()]);
        }
        
        try {
            // Eliminar la deuda
            $this->entityManager->remove($deuda);
            $this->entityManager->flush();
            
            $this->addFlash('success', sprintf(
                'Se ha cancelado la deuda de %s para el curso %s, periodo %s %s.', 
                $alumno->getNombreApellido(),
                $deuda->getCurso()->getNombre(),
                $this->getNombreMes($deuda->getMes()),
                $deuda->getAno()
            ));
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Ocurrió un error al cancelar la deuda: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_alumno_deudas', ['id' => $alumno->getId()]);
    }

    /**
     * @Route("/{id}/cancelar-deudas-curso/{cursoId}", name="app_alumno_cancelar_deudas_curso", methods={"POST"})
     */
    public function cancelarDeudasCurso(Alumno $alumno, int $cursoId, Request $request): Response
    {
        // Verificar token CSRF
        if (!$this->isCsrfTokenValid('cancelar-deudas-curso'.$cursoId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_alumno_deudas', ['id' => $alumno->getId()]);
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
            return $this->redirectToRoute('app_alumno_deudas', ['id' => $alumno->getId()]);
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
        
        return $this->redirectToRoute('app_alumno_deudas', ['id' => $alumno->getId()]);
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
