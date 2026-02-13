<?php

namespace App\Controller;

use App\Entity\AsistenciaProfesores;
use App\Entity\Profesor;
use App\Form\ProfesorType;
use App\Repository\AsistenciaProfesoresRepository;
use App\Repository\CursoRepository;
use App\Repository\ProfesorRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Helpers;
use Knp\Component\Pager\PaginatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\User;
use App\Service\TokenService;
use App\Service\HorarioConflictService;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/instituto/profesor")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class ProfesorController extends AbstractController
{
    private TokenService $tokenService;
    private HorarioConflictService $horarioConflictService;

    public function __construct(TokenService $tokenService, HorarioConflictService $horarioConflictService)
    {
        $this->tokenService = $tokenService;
        $this->horarioConflictService = $horarioConflictService;
    }

    /**
     * @Route("/", name="app_profesor_index", methods={"GET"})
     */
    public function index(Request $request, ProfesorRepository $profesorRepository, PaginatorInterface $paginator): Response
    {
        $limit = $request->get('limit', 10);
        $currentPage = $request->get('page', 1);
        $busqueda = $request->get('busqueda', 0);
        $order = $request->get('order', 'asc');
        $sort = $request->get('sort', 'apellido');
        
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        $instituto = $user->getInstituto();
        
        $profesorsQuery = $this->createQuery($profesorRepository, $instituto, $sort, $order, $busqueda);

        $profesors = $paginator->paginate(
            $profesorsQuery, 
            $currentPage, 
            $limit
        );

        return $this->render('profesor/index.html.twig', [
            'profesors' => $profesors,
            'busqueda' => $busqueda,
            'total' => $profesors->getTotalItemCount(),
            'order' => $order,
            'sort' => $sort
        ]);
    }

    /**
     * Crea una consulta para obtener profesores con los filtros especificados
     */
    private function createQuery(
        ProfesorRepository $profesorRepository,
        $instituto,
        string $sort,
        string $order,
        ?string $busqueda = null
    ) {
        $qb = $profesorRepository->createQueryBuilder('p')
            ->where('p.instituto = :instituto')
            ->setParameter('instituto', $instituto);

        if ($busqueda) {
            $qb->andWhere('p.apellido LIKE :busqueda OR p.nombre LIKE :busqueda')
               ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        // Ordenamiento por nombre o apellido
        if ($sort === 'nombre') {
            $qb->orderBy('p.nombre', $order)
               ->addOrderBy('p.apellido', $order);
        } else {
            $qb->orderBy('p.apellido', $order)
               ->addOrderBy('p.nombre', $order);
        }

        return $qb;
    }

    /**
     * @Route("/asistencias", name="asistencias", methods={"GET"})
     */
    public function asistencias(Request $request, CursoRepository $cursoRepository, ProfesorRepository $profesorRepository, AsistenciaProfesoresRepository $asistenciaProfesoresRepository): Response
    {
        $desde = $request->get('desde', date("Y/m/d"));

        $day = date('N', strtotime($desde));
        $firstday = date('Y/m/d', strtotime('-'.($day-1).' days', strtotime($desde)));
        $lastday = date('Y/m/d', strtotime('+'.(7-$day).' days', strtotime($desde)));
        
        $instituto = $this->getUser()->getInstituto();

        $cursos = $cursoRepository->findBy(['disabled' => false, 'instituto' => $instituto]);

        $profesores = $profesorRepository->findByApellido($instituto, null)->getResult();

        $asistencias = $asistenciaProfesoresRepository->findAll($instituto);
        
        $asistenciasArray = [];

        foreach ($asistencias as $asistencia) {
            $asistenciasArray[$asistencia->getCurso()][$asistencia->getFecha()->format('Y/m/d')][$asistencia->getProfesor()->getId()] = array('presente' => $asistencia->getPresente(), 'reemplazante' => ($asistencia->getProfesorRemplazante()) ? $profesorRepository->find($asistencia->getProfesorRemplazante())->getApellido() . ', ' . $profesorRepository->find($asistencia->getProfesorRemplazante())->getNombre() : 'sin reemplazo');
        }

        return $this->render('profesor/asistencias.html.twig',[
            'cursos' => $cursos,
            'firstday' => $firstday,
            'todosLosProfes' => $profesores,
            'rango' => $this->createDateRangeArray($firstday, $lastday),
            'asistencias' => $asistenciasArray,
        ]);

    }

    /**
     * @Route("/informes", name="informes", methods={"GET"})
     */
    public function informes(Request $request, CursoRepository $cursoRepository, ProfesorRepository $profesorRepository, AsistenciaProfesoresRepository $asistenciaProfesoresRepository): Response
    {
        $ds = new \DateTime('first day of this month');
        $ls = new \DateTime('last day of this month');

        $desde = $request->get('desde', $ds->format("Y/m/d"));
        $hasta = $request->get('hasta', $ls->format("Y/m/d"));

        $instituto = $this->getUser()->getInstituto();

        $cursos = $cursoRepository->findBy(['instituto' => $instituto, 'disabled' => false]);
        $profesores = $profesorRepository->findBy(['instituto' => $instituto]);

        $rango = $this->createDateRangeArray($desde, $hasta);

        $asisArray = [];
        $reemplazantes = [];
        $profesoresInfo = []; // Array para almacenar información de profesores (ID => nombre completo)

        // Inicializar información de profesores
        foreach ($profesores as $profe) {
            $profesoresInfo[$profe->getId()] = [
                'nombreCompleto' => $profe->getApellido() . ', ' . $profe->getNombre(),
                'apellido' => $profe->getApellido(),
                'nombre' => $profe->getNombre()
            ];
        }

        foreach ($rango as $fecha) {
            $date = new \DateTime($fecha);
            $day_of_week = Helpers\Fechas::getDiaDeLaSemana(intval($date->format('w')));

            $cursosDelDia = $cursoRepository->findByDiaEinstituto($day_of_week, $instituto);

            $faltas = [];
        
            $faltaArr = [];

            foreach ($cursosDelDia as $cursoHoy) {
                $profeCurso = $cursoHoy->getProfesores();
                $faltas = $asistenciaProfesoresRepository->findByFechaEinstituto(new \DateTime($fecha), $instituto);
                /* if($faltas) dd($faltas); */
                foreach ($profeCurso as $profe) {
                    $profeId = $profe->getId();
                    $asisArray[$profeId][$fecha][] = ['falta' => false, 'horas' => $cursoHoy->getDuracion(), 'curso' => $cursoHoy->getNombre()];
                    $asisArray[$profeId]['precioHora'] = $profe->getPrecioHora();
                    
                    // Asegurar que el profesor esté en el array de información
                    if (!isset($profesoresInfo[$profeId])) {
                        $profesoresInfo[$profeId] = [
                            'nombreCompleto' => $profe->getApellido() . ', ' . $profe->getNombre(),
                            'apellido' => $profe->getApellido(),
                            'nombre' => $profe->getNombre()
                        ];
                    }
                }
            }


            foreach ($faltas as $falta) {
                $profeFalta = $falta->getProfesor();
                $profeFaltaId = $profeFalta->getId();
                $reemplazanteIdRaw = $falta->getProfesorRemplazante();
                $reemplazante = ($reemplazanteIdRaw && $reemplazanteIdRaw > 0) ? $profesorRepository->find($reemplazanteIdRaw) : null;
                $reemplazanteId = $reemplazante ? $reemplazante->getId() : null;
                $nombreReemplazante = $reemplazante ? ($reemplazante->getApellido() . ', ' . $reemplazante->getNombre()) : 'Sin Reemplazo';
                $curso = $cursoRepository->find($falta->getCurso());
                $nombreCurso = (!empty($curso)) ? $curso->getNombre() : 'El curso fue eliminado';
                $faltaArr[] = ["falta" => true, "remplazante" => $nombreReemplazante, "curso" => $nombreCurso, 'horas' => $curso->getDuracion()];

                if ($reemplazante) {
                    $reemplazantes[$reemplazanteId][$fecha][]['reemplazo'] = ["reemplazoA" =>"Reemplazó a " . $profeFalta->getApellido() . ", " . $profeFalta->getNombre() . " en "  . $cursoRepository->find($falta->getCurso())->getNombre(), 'horas' => $curso->getDuracion()];
                    $reemplazantes[$reemplazanteId]['precioHora'] = $reemplazante->getPrecioHora();
                    
                    // Asegurar que el reemplazante esté en el array de información
                    if (!isset($profesoresInfo[$reemplazanteId])) {
                        $profesoresInfo[$reemplazanteId] = [
                            'nombreCompleto' => $reemplazante->getApellido() . ', ' . $reemplazante->getNombre(),
                            'apellido' => $reemplazante->getApellido(),
                            'nombre' => $reemplazante->getNombre()
                        ];
                    }
                }

                if (isset($asisArray[$profeFaltaId][$fecha])) {
                    foreach ( $asisArray[$profeFaltaId][$fecha] as $clave => $asistencias ) {
                        foreach ( $faltaArr as $faltaIndividual ) {
                            if ($asistencias['curso'] == $faltaIndividual['curso']) {
                                $asisArray[$profeFaltaId][$fecha][$clave] = $faltaIndividual;
                            }
                        }
                    }
                }
            }
        }

        foreach ($reemplazantes as $key => $reemp) {
            if ( isset($asisArray[$key]) ) {
                foreach ($reemp as $otherKey => $otherReemp) {
                    if (isset($asisArray[$key][$otherKey])) {
                        if (is_array($asisArray[$key][$otherKey])) {
                            foreach ($otherReemp as $otherReempInner) {
                                array_push($asisArray[$key][$otherKey], $otherReempInner);
                            }
                        }
                    } else {
                        $asisArray[$key][$otherKey] = $otherReemp;
                    }
                }
            }
        }
        
        // Ordenar profesores por apellido y luego por nombre
        uasort($profesoresInfo, function($a, $b) {
            $cmp = strcmp($a['apellido'], $b['apellido']);
            if ($cmp === 0) {
                return strcmp($a['nombre'], $b['nombre']);
            }
            return $cmp;
        });
        
        // Reordenar $asisArray según el orden de $profesoresInfo
        $asisArrayOrdenado = [];
        foreach ($profesoresInfo as $profeId => $info) {
            if (isset($asisArray[$profeId])) {
                $asisArrayOrdenado[$profeId] = $asisArray[$profeId];
            }
        }
        
        // Agregar reemplazantes que no están en profesores principales
        foreach ($reemplazantes as $reempId => $reempData) {
            if (!isset($asisArrayOrdenado[$reempId])) {
                $asisArrayOrdenado[$reempId] = $reempData;
            }
        }

        return $this->render('profesor/informes.html.twig',[
            'cursos' => $cursos,
            'todosLosProfes' => $profesores,
            'asistencias' => $asisArrayOrdenado,
            'profesoresInfo' => $profesoresInfo,
            'desde' => $desde,
            'hasta' => $hasta,
            'rango' => $this->createDateRangeArray($desde, $hasta),
            'reemplazantes' => $reemplazantes
        ]);

    }

    /**
     * @Route("/new", name="app_profesor_new", methods={"GET", "POST"})
     */
    public function new(
        Request $request, 
        ProfesorRepository $profesorRepository,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response
    {
        $profesor = new Profesor();
        $instituto = $this->getUser()->getInstituto();
        $profesor->setInstituto($instituto);

        $form = $this->createForm(ProfesorType::class, $profesor, ['is_edit' => false, 'instituto' => $instituto]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                // Verificar tokens antes de crear
                if (!$this->tokenService->hasEnoughTokens($instituto, 'profesor.create')) {
                    $this->addFlash('danger', 'No tienes suficientes tokens para crear un profesor. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                    return $this->renderForm('profesor/new.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                    ]);
                }

                $email = $profesor->getEmail();
                
                // Verificar si el email ya existe en usuarios
                $usuarioExistente = $userRepository->findOneBy(['email' => $email]);
                if ($usuarioExistente) {
                    $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                    return $this->renderForm('profesor/new.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                    ]);
                }
                
                // Verificar si el email ya existe en profesores
                $profesorExistente = $profesorRepository->findOneBy(['email' => $email]);
                if ($profesorExistente) {
                    $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado para otro profesor.');
                    return $this->renderForm('profesor/new.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                    ]);
                }

                // Verificar conflictos de horarios
                // Solo verificar si el profesor ya tiene ID (ya existe en BD)
                if ($profesor->getId()) {
                    $todosConflictos = [];
                    foreach ($profesor->getCursos() as $curso) {
                        if (!$curso->getId()) {
                            continue; // Curso nuevo sin ID, se verificará cuando se guarde
                        }
                        $conflictos = $this->horarioConflictService->detectarConflictos($curso, $profesor, $curso->getId());
                        if (!empty($conflictos)) {
                            $todosConflictos = array_merge($todosConflictos, $conflictos);
                        }
                    }
                    
                    if (!empty($todosConflictos)) {
                        $mensaje = 'Advertencia: Se detectaron conflictos de horarios. ' . $this->horarioConflictService->generarMensajeConflicto($todosConflictos);
                        $this->addFlash('warning', $mensaje);
                        // Continuar con el guardado pero mostrar advertencia
                    }
                }

                try {
                    // Crear el usuario para el profesor
                    $user = new User();
                    $user->setEmail($email);
                    $user->setRoles(['ROLE_PROFESOR']);
                    $user->setInstituto($instituto);
                    
                    // Generar una contraseña temporal
                    $plainPassword = bin2hex(random_bytes(4)); // Genera una contraseña aleatoria de 8 caracteres
                    $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                    $user->setPassword($hashedPassword);

                    // Establecer la relación bidireccional
                    $user->setProfesor($profesor);
                    $profesor->setUser($user);

                    // Guardar el usuario y el profesor
                    $entityManager->persist($user);
                    $entityManager->persist($profesor);
                    $entityManager->flush();

                    // Asegurar que la relación bidireccional se establezca con los cursos
                    foreach($profesor->getCursos() as $curso) {
                        $curso->addProfesor($profesor);
                    }
                    $entityManager->flush();

                    // Consumir tokens después de guardar exitosamente
                    $this->tokenService->consumeTokens(
                        $instituto,
                        'profesor.create',
                        $this->getUser(),
                        'Crear profesor: ' . $profesor->getNombre() . ' ' . $profesor->getApellido(),
                        'Profesor',
                        $profesor->getId()
                    );

                    // Mostrar mensaje con la contraseña temporal
                    $this->addFlash('success', 'Profesor creado exitosamente.');
                    // Usuario creado - la información está en el formulario

                    return $this->redirectToRoute('app_profesor_index', [], Response::HTTP_SEE_OTHER);
                    
                } catch (UniqueConstraintViolationException $e) {
                    // Capturar errores de restricción única de base de datos
                    $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                    return $this->renderForm('profesor/new.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                    ]);
                } catch (DriverException $e) {
                    // Capturar errores de driver de base de datos (incluye violaciones de restricción única)
                    if ($e->getErrorCode() === 1062 || strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'E7927C74') !== false) {
                        $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                    } else {
                        $this->addFlash('danger', 'Error al crear el profesor. Por favor, verifique los datos e intente nuevamente.');
                    }
                    return $this->renderForm('profesor/new.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                    ]);
                } catch (\Exception $e) {
                    // Capturar cualquier otro error
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'E7927C74') !== false || strpos($e->getMessage(), 'UNIQ_') !== false) {
                        $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                    } else {
                        $this->addFlash('danger', 'Error inesperado al crear el profesor. Por favor, intente nuevamente.');
                    }
                    return $this->renderForm('profesor/new.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                    ]);
                }
            } else {
                $errors = $form->getErrors(true);
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->renderForm('profesor/new.html.twig', [
            'profesor' => $profesor,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_profesor_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Profesor $profesor, ProfesorRepository $profesorRepository, UserRepository $userRepository, CursoRepository $cursoRepository, EntityManagerInterface $entityManager): Response
    {
        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        if ($profesor->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'El profesor no pertenece al instituto del usuario.');
            return $this->redirectToRoute('app_profesor_index');
        }
        
        // Guardar cursos originales para comparar cambios
        $cursosOriginalesIds = [];
        $cursosOriginales = clone $profesor->getCursos();
        foreach ($profesor->getCursos() as $curso) {
            $cursosOriginalesIds[] = $curso->getId();
        }
        
        // Guardar el email original antes de que el formulario lo modifique
        $emailOriginal = $profesor->getEmail();
        
        $form = $this->createForm(ProfesorType::class, $profesor, [
            'is_edit' => true, 
            'instituto' => $instituto, 
            'cursos' => $cursosOriginales
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Verificar tokens antes de editar
            if (!$this->tokenService->hasEnoughTokens($instituto, 'profesor.edit')) {
                $this->addFlash('danger', 'No tienes suficientes tokens para editar un profesor. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                return $this->renderForm('profesor/edit.html.twig', [
                    'profesor' => $profesor,
                    'form' => $form,
                ]);
            }

            $email = $profesor->getEmail();
            
            // Verificar si el email cambió y si el nuevo email ya existe
            if ($email !== $emailOriginal) {
                // Verificar si el nuevo email ya existe en usuarios
                $usuarioExistente = $userRepository->findOneBy(['email' => $email]);
                if ($usuarioExistente && (!$profesor->getUser() || $usuarioExistente->getId() !== $profesor->getUser()->getId())) {
                    $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                    return $this->renderForm('profesor/edit.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                    ]);
                }
                
                // Verificar si el nuevo email ya existe en otros profesores
                $profesorExistente = $profesorRepository->findOneBy(['email' => $email]);
                if ($profesorExistente && $profesorExistente->getId() !== $profesor->getId()) {
                    $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado para otro profesor.');
                    return $this->renderForm('profesor/edit.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                    ]);
                }
            }
            
            // Verificar si se cambiaron los cursos
            $cursosNuevosIds = [];
            foreach ($profesor->getCursos() as $curso) {
                $cursosNuevosIds[] = $curso->getId();
            }
            
            // Verificar si hay cambios en los cursos
            $cursosCambiados = count(array_diff($cursosOriginalesIds, $cursosNuevosIds)) > 0 || 
                              count(array_diff($cursosNuevosIds, $cursosOriginalesIds)) > 0;
            
            // Verificar si hay cursos que ya comenzaron y tienen asistencias
            $cursosComenzados = false;
            $asistenciasExistentes = false;
            
            if ($cursosCambiados) {
                // Verificar cursos eliminados que ya han comenzado
                $cursosEliminados = array_diff($cursosOriginalesIds, $cursosNuevosIds);
                if (count($cursosEliminados) > 0) {
                    foreach ($cursosEliminados as $cursoId) {
                        $curso = $cursoRepository->find($cursoId);
                        if ($curso && $curso->getFechaInicio() <= new \DateTime()) {
                            $cursosComenzados = true;
                            
                            // Verificar si hay asistencias registradas para este profesor en este curso
                            $asistenciaProfesoresRepository = $entityManager->getRepository('App\Entity\AsistenciaProfesores');
                            $asistencias = $asistenciaProfesoresRepository->findBy([
                                'curso' => $curso,
                                'profesor' => $profesor
                            ]);
                            
                            if (count($asistencias) > 0) {
                                $asistenciasExistentes = true;
                                break;
                            }
                        }
                    }
                }
            }
            
            if ($cursosCambiados && $cursosComenzados && $asistenciasExistentes) {
                // Si hay asistencias y no se confirmó la acción, mostrar advertencia
                if (!$request->request->get('confirmar_cambio_cursos')) {
                    $this->addFlash('warning', 'Algunos cursos ya han comenzado y tienen registros de asistencia para este profesor. 
                    Si cambia los cursos asignados, se modificarán los registros de asistencia.
                    Si desea continuar, confirme la acción.');
                    
                    return $this->renderForm('profesor/edit.html.twig', [
                        'profesor' => $profesor,
                        'form' => $form,
                        'mostrar_confirmacion' => true,
                    ]);
                } else {
                    // El usuario confirmó la acción, proceder a guardar los cambios
                    $this->addFlash('success', 'Los cambios en los cursos han sido aplicados. Los registros de asistencia se han mantenido en el sistema.');
                }
            }
            
            // Verificar conflictos de horarios
            $todosConflictos = [];
            foreach ($profesor->getCursos() as $curso) {
                $conflictos = $this->horarioConflictService->detectarConflictos($curso, $profesor, $curso->getId());
                if (!empty($conflictos)) {
                    $todosConflictos = array_merge($todosConflictos, $conflictos);
                }
            }
            
            if (!empty($todosConflictos)) {
                $mensaje = 'Advertencia: Se detectaron conflictos de horarios. ' . $this->horarioConflictService->generarMensajeConflicto($todosConflictos);
                $this->addFlash('warning', $mensaje);
                // Continuar con el guardado pero mostrar advertencia
            }
            
            try {
                // Actualizar el email del usuario si cambió
                if ($email !== $emailOriginal && $profesor->getUser()) {
                    $profesor->getUser()->setEmail($email);
                }
                
                // Primero, remover al profesor de los cursos que ya no están asignados
                foreach($cursosOriginales as $curso) {
                    if (!$profesor->getCursos()->contains($curso)) {
                        $curso->removeProfesor($profesor);
                        $cursoRepository->add($curso, true);
                    }
                }

                // Luego, agregar al profesor a los nuevos cursos
                foreach($profesor->getCursos() as $curso) {
                    if (!$cursosOriginales->contains($curso)) {
                        $curso->addProfesor($profesor);
                        $cursoRepository->add($curso, true);
                    }
                }
                
                // Finalmente, guardar el profesor
                $profesorRepository->add($profesor, true);

                // Consumir tokens después de guardar exitosamente
                $this->tokenService->consumeTokens(
                    $instituto,
                    'profesor.edit',
                    $this->getUser(),
                    'Editar profesor: ' . $profesor->getNombre() . ' ' . $profesor->getApellido(),
                    'Profesor',
                    $profesor->getId()
                );

                $this->addFlash('success', 'Profesor actualizado exitosamente.');
                return $this->redirectToRoute('app_profesor_index', [], Response::HTTP_SEE_OTHER);
                
            } catch (UniqueConstraintViolationException $e) {
                $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                return $this->renderForm('profesor/edit.html.twig', [
                    'profesor' => $profesor,
                    'form' => $form,
                ]);
            } catch (DriverException $e) {
                if ($e->getErrorCode() === 1062 || strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'E7927C74') !== false) {
                    $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                } else {
                    $this->addFlash('danger', 'Error al actualizar el profesor. Por favor, verifique los datos e intente nuevamente.');
                }
                return $this->renderForm('profesor/edit.html.twig', [
                    'profesor' => $profesor,
                    'form' => $form,
                ]);
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'E7927C74') !== false || strpos($e->getMessage(), 'UNIQ_') !== false) {
                    $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                } else {
                    $this->addFlash('danger', 'Error inesperado al actualizar el profesor. Por favor, intente nuevamente.');
                }
                return $this->renderForm('profesor/edit.html.twig', [
                    'profesor' => $profesor,
                    'form' => $form,
                ]);
            }
        }

        return $this->renderForm('profesor/edit.html.twig', [
            'profesor' => $profesor,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="app_profesor_show", methods={"GET"})
     */
    public function show(Profesor $profesor): Response
    {
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        
        // Verificar que el profesor pertenece al instituto del usuario
        if ($profesor->getInstituto() !== $user->getInstituto()) {
            $this->addFlash('danger', 'No tienes acceso a este profesor.');
            return $this->redirectToRoute('app_profesor_index');
        }
        
        return $this->render('profesor/show.html.twig', [
            'profesor' => $profesor,
        ]);
    }

    /**
     * @Route("/{id}/falto/{remplazo}", name="falta_profe", methods={"GET", "POST"})
     */
    public function faltaprofe(Request $request, Profesor $profesor, ProfesorRepository $profesorRepository, $remplazo, AsistenciaProfesoresRepository $asistenciaProfesoresRepository): Response
    {

        $profeRemplazante = $profesorRepository->find($remplazo);

        $fecha = $request->get('fecha');
        $curso = $request->get('curso');

        $asistenciasGuarda = $asistenciaProfesoresRepository->findBy(['profesor' => $profesor, 'fecha' => new \DateTime($fecha), 'curso' => $curso]);

        if ( $remplazo === "-1" ) {
            $asistenciaProfesoresRepository->remove($asistenciasGuarda[0]);
        } else {
            if (!empty($asistenciasGuarda[0])) {
                $asistenciasGuarda[0]->setProfesorRemplazante($profeRemplazante ? $profeRemplazante->getId() : 0);
                $asistenciaProfesoresRepository->add($asistenciasGuarda[0]);
            } else {
                $asistenciaNueva = new AsistenciaProfesores();
                $asistenciaNueva->setFecha(new \DateTime($fecha));
                $asistenciaNueva->setProfesor($profesor);
                $asistenciaNueva->setPresente(false);
                $asistenciaNueva->setCurso($curso);
                $asistenciaNueva->setProfesorRemplazante($profeRemplazante ? $profeRemplazante->getId() : 0);

                $asistenciaProfesoresRepository->add($asistenciaNueva);
            }
        }

        return $this->redirectToRoute('asistencias', ['desde' => $fecha], Response::HTTP_SEE_OTHER);
    }

    /**
     * @Route("/{id}", name="app_profesor_delete", methods={"POST"})
     */
    public function delete(Request $request, Profesor $profesor, ProfesorRepository $profesorRepository): Response
    {
        $user = $this->getUser();
        $instituto = $user->getInstituto();
        
        // Verificar tokens antes de eliminar
        if (!$this->tokenService->hasEnoughTokens($instituto, 'profesor.delete')) {
            $this->addFlash('danger', 'No tienes suficientes tokens para eliminar un profesor. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
            return $this->redirectToRoute('app_profesor_index');
        }
        
        if ($this->isCsrfTokenValid('delete'.$profesor->getId(), $request->request->get('_token'))) {
            $profesorRepository->remove($profesor);
            
            // Consumir tokens después de eliminar exitosamente
            $this->tokenService->consumeTokens(
                $instituto,
                'profesor.delete',
                $this->getUser(),
                'Eliminar profesor: ' . $profesor->getNombre() . ' ' . $profesor->getApellido(),
                'Profesor',
                $profesor->getId()
            );
        }

        return $this->redirectToRoute('app_profesor_index', [], Response::HTTP_SEE_OTHER);
    }

    function createDateRangeArray($strDateFrom,$strDateTo)
    {
        // takes two dates formatted as YYYY-MM-DD and creates an
        // inclusive array of the dates between the from and to dates.

        // could test validity of dates here but I'm already doing
        // that in the main script

        $aryRange = [];

        $iDateFrom = mktime(1, 0, 0, substr($strDateFrom, 5, 2), substr($strDateFrom, 8, 2), substr($strDateFrom, 0, 4));
        $iDateTo = mktime(1, 0, 0, substr($strDateTo, 5, 2), substr($strDateTo, 8, 2), substr($strDateTo, 0, 4));

        if ($iDateTo >= $iDateFrom) {
            array_push($aryRange, date('Y/m/d', $iDateFrom)); // first entry
            while ($iDateFrom < $iDateTo) {
                $iDateFrom += 86400; // add 24 hours
                array_push($aryRange, date('Y/m/d', $iDateFrom));
            }
        }
        return $aryRange;
    }
}
