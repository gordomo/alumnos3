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
use App\Service\InstitutoTimezoneService;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/instituto/profesor")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class ProfesorController extends AbstractController
{
    private TokenService $tokenService;
    private HorarioConflictService $horarioConflictService;
    private InstitutoTimezoneService $institutoTimezoneService;

    public function __construct(TokenService $tokenService, HorarioConflictService $horarioConflictService, InstitutoTimezoneService $institutoTimezoneService)
    {
        $this->tokenService = $tokenService;
        $this->horarioConflictService = $horarioConflictService;
        $this->institutoTimezoneService = $institutoTimezoneService;
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
        $instituto = $this->getUser()->getInstituto();
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        $nowInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $primerDiaMes = (clone $nowInstituto)->modify('first day of this month')->setTime(0, 0, 0);
        $ultimoDiaMes = (clone $nowInstituto)->modify('last day of this month')->setTime(23, 59, 59);

        $desdeStr = $request->get('desde', $primerDiaMes->format($dateFormat));
        $hastaStr = $request->get('hasta', $ultimoDiaMes->format($dateFormat));

        $desdeDt = $this->institutoTimezoneService->parseDateString($desdeStr, $dateFormat);
        $hastaDt = $this->institutoTimezoneService->parseDateString($hastaStr, $dateFormat);
        if (!$desdeDt) {
            $desdeDt = clone $primerDiaMes;
        }
        if (!$hastaDt) {
            $hastaDt = clone $ultimoDiaMes;
        }
        $desdeDt->setTime(0, 0, 0);
        $hastaDt->setTime(23, 59, 59);
        if ($hastaDt < $desdeDt) {
            $hastaDt = clone $desdeDt;
            $hastaDt->setTime(23, 59, 59);
        }

        // Limitar rango a 92 días (~3 meses) para evitar tablas gigantes por parámetros incorrectos
        $maxDias = 92;
        $diff = $desdeDt->diff($hastaDt, true)->days;
        if ($diff > $maxDias) {
            $hastaDt = (clone $desdeDt)->modify('+' . $maxDias . ' days')->setTime(23, 59, 59);
        }

        $cursos = $cursoRepository->findBy(['disabled' => false, 'instituto' => $instituto]);
        $profesores = $profesorRepository->findByApellido($instituto, null)->getResult();

        $asistencias = $asistenciaProfesoresRepository->findByInstituto($desdeDt, $hastaDt, $instituto);
        $asistenciasArray = [];
        foreach ($asistencias as $asistencia) {
            $cursoId = $asistencia->getCurso();
            $cursoId = \is_int($cursoId) ? $cursoId : $cursoId->getId();
            $asistenciasArray[$cursoId][$asistencia->getFecha()->format($dateFormat)][$asistencia->getProfesor()->getId()] = [
                'presente' => $asistencia->getPresente(),
                'reemplazante' => ($asistencia->getProfesorRemplazante()) ? $profesorRepository->find($asistencia->getProfesorRemplazante())->getApellido() . ', ' . $profesorRepository->find($asistencia->getProfesorRemplazante())->getNombre() : 'sin reemplazo',
            ];
        }

        $rango = [];
        $current = clone $desdeDt;
        $current->setTime(0, 0, 0);
        $hastaSoloFecha = (clone $hastaDt)->setTime(0, 0, 0);
        while ($current <= $hastaSoloFecha) {
            $rango[] = [
                'fecha' => $current->format($dateFormat),
                'dia' => Helpers\Fechas::getDiaDeLaSemana((int) $current->format('w')),
            ];
            $current->modify('+1 day');
        }

        return $this->render('profesor/asistencias.html.twig', [
            'cursos' => $cursos,
            'desde' => $desdeDt->format($dateFormat),
            'hasta' => $hastaDt->format($dateFormat),
            'date_format' => $dateFormat,
            'todosLosProfes' => $profesores,
            'rango' => $rango,
            'asistencias' => $asistenciasArray,
        ]);
    }

    /**
     * @Route("/informes", name="informes", methods={"GET"})
     */
    public function informes(Request $request, CursoRepository $cursoRepository, ProfesorRepository $profesorRepository, AsistenciaProfesoresRepository $asistenciaProfesoresRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        $nowInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $ds = $nowInstituto->modify('first day of this month');
        $ls = $nowInstituto->modify('last day of this month');

        $desde = $request->get('desde', $ds->format($dateFormat));
        $hasta = $request->get('hasta', $ls->format($dateFormat));

        $cursos = $cursoRepository->findBy(['instituto' => $instituto, 'disabled' => false]);
        $profesores = $profesorRepository->findBy(['instituto' => $instituto]);

        $rango = $this->createDateRangeArray($desde, $hasta, $dateFormat, $dateFormat);

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

        foreach ($rango as $fechaStr) {
            $date = $this->institutoTimezoneService->parseDateString($fechaStr, $dateFormat);
            if (!$date) {
                continue;
            }
            $dateOnly = new \DateTime($date->format('Y-m-d'));
            $dateOnly->setTime(0, 0, 0);

            $day_of_week = Helpers\Fechas::getDiaDeLaSemana((int) $date->format('w'));

            $cursosDelDia = $cursoRepository->findByDiaEinstituto($day_of_week, $instituto);

            $faltas = [];
        
            $faltaArr = [];

            foreach ($cursosDelDia as $cursoHoy) {
                $profeCurso = $cursoHoy->getProfesores();
                $faltas = $asistenciaProfesoresRepository->findByFechaEinstituto($dateOnly, $instituto);
                /* if($faltas) dd($faltas); */
                foreach ($profeCurso as $profe) {
                    $profeId = $profe->getId();
                    $asisArray[$profeId][$fechaStr][] = ['falta' => false, 'horas' => $cursoHoy->getDuracion(), 'curso' => $cursoHoy->getNombre()];
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
                if (!$curso) {
                    continue;
                }
                $nombreCurso = $curso->getNombre();
                $faltaArr[] = ["falta" => true, "remplazante" => $nombreReemplazante, "curso" => $nombreCurso, 'horas' => $curso->getDuracion()];

                if ($reemplazante) {
                    $reemplazantes[$reemplazanteId][$fechaStr][]['reemplazo'] = ["reemplazoA" =>"Reemplazó a " . $profeFalta->getApellido() . ", " . $profeFalta->getNombre() . " en " . $curso->getNombre(), 'horas' => $curso->getDuracion()];
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

                if (isset($asisArray[$profeFaltaId][$fechaStr])) {
                    foreach ( $asisArray[$profeFaltaId][$fechaStr] as $clave => $asistencias ) {
                        foreach ( $faltaArr as $faltaIndividual ) {
                            if ($asistencias['curso'] == $faltaIndividual['curso']) {
                                $asisArray[$profeFaltaId][$fechaStr][$clave] = $faltaIndividual;
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
            'date_format' => $dateFormat,
            'rango' => $rango,
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

        if ($form->isSubmitted() && !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $cause = $error->getCause();
                $origin = $error->getOrigin();
                $fieldName = $origin ? $origin->getName() : 'form';
                $this->addFlash('danger', 'Error en "' . $fieldName . '": ' . $error->getMessage());
            }
        }

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
            
            // Verificar si se cambiaron los cursos (solo importa si se quitó o agregó alguno, no el orden)
            $cursosNuevosIds = [];
            foreach ($profesor->getCursos() as $curso) {
                $cursosNuevosIds[] = $curso->getId();
            }
            $cursosCambiados = count(array_diff($cursosOriginalesIds, $cursosNuevosIds)) > 0
                || count(array_diff($cursosNuevosIds, $cursosOriginalesIds)) > 0;

            // Advertencia solo si: (1) se quita al profesor de algún curso, (2) ese curso ya empezó
            // según la fecha del instituto, y (3) ese curso tiene asistencias de este profesor.
            $cursosComenzados = false;
            $asistenciasExistentes = false;
            $hoyInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);
            $hoyStr = $hoyInstituto->format('Y-m-d');

            if ($cursosCambiados) {
                $cursosEliminados = array_diff($cursosOriginalesIds, $cursosNuevosIds);
                foreach ($cursosEliminados as $cursoId) {
                    $curso = $cursoRepository->find($cursoId);
                    if (!$curso) {
                        continue;
                    }
                    $inicioCurso = $curso->getFechaInicio();
                    $inicioCursoStr = $inicioCurso instanceof \DateTimeInterface ? $inicioCurso->format('Y-m-d') : null;
                    if ($inicioCursoStr === null) {
                        continue;
                    }
                    // "Ya comenzó" = fecha inicio del curso <= hoy (en zona horaria del instituto)
                    if ($inicioCursoStr > $hoyStr) {
                        continue;
                    }
                    $cursosComenzados = true;
                    $asistenciaProfesoresRepository = $entityManager->getRepository('App\Entity\AsistenciaProfesores');
                    $asistencias = $asistenciaProfesoresRepository->findBy([
                        'curso' => $curso->getId(),
                        'profesor' => $profesor
                    ]);
                    if (count($asistencias) > 0) {
                        $asistenciasExistentes = true;
                        break;
                    }
                }
            }

            if ($cursosCambiados && $cursosComenzados && $asistenciasExistentes) {
                if (!$request->request->get('confirmar_cambio_cursos')) {
                    $this->addFlash('warning', 'Está quitando a este profesor de al menos un curso que ya comenzó y tiene asistencias cargadas. Los registros de asistencia se conservan, pero el profesor dejará de figurar asignado a ese curso. Si desea continuar, marque la confirmación.');
                    
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
        $instituto = $profesor->getInstituto();
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);

        $profeRemplazante = $profesorRepository->find($remplazo);

        $fechaStr = $request->get('fecha');
        $curso = $request->get('curso');
        $fechaDt = $this->institutoTimezoneService->parseDateString($fechaStr ?? '', $dateFormat);
        if (!$fechaDt) {
            return $this->redirectToRoute('asistencias', [], Response::HTTP_SEE_OTHER);
        }

        $asistenciasGuarda = $asistenciaProfesoresRepository->findBy(['profesor' => $profesor, 'fecha' => $fechaDt, 'curso' => $curso]);

        if ( $remplazo === "-1" ) {
            $asistenciaProfesoresRepository->remove($asistenciasGuarda[0]);
        } else {
            if (!empty($asistenciasGuarda[0])) {
                $asistenciasGuarda[0]->setProfesorRemplazante($profeRemplazante ? $profeRemplazante->getId() : 0);
                $asistenciaProfesoresRepository->add($asistenciasGuarda[0]);
            } else {
                $asistenciaNueva = new AsistenciaProfesores();
                $asistenciaNueva->setFecha($fechaDt);
                $asistenciaNueva->setProfesor($profesor);
                $asistenciaNueva->setPresente(false);
                $asistenciaNueva->setCurso($curso);
                $asistenciaNueva->setProfesorRemplazante($profeRemplazante ? $profeRemplazante->getId() : 0);

                $asistenciaProfesoresRepository->add($asistenciaNueva);
            }
        }

        $params = [];
        if ($request->query->has('desde')) {
            $params['desde'] = $request->query->get('desde');
        }
        if ($request->query->has('hasta')) {
            $params['hasta'] = $request->query->get('hasta');
        }
        return $this->redirectToRoute('asistencias', $params, Response::HTTP_SEE_OTHER);
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

    /**
     * Crea un array inclusivo de fechas entre $strDateFrom y $strDateTo.
     * Si $parseFormat está definido (ej. formato del instituto), se usa para interpretar las fechas.
     */
    private function createDateRangeArray(string $strDateFrom, string $strDateTo, string $outputFormat = 'd/m/Y', ?string $parseFormat = null): array
    {
        if ($parseFormat !== null) {
            $dateFrom = $this->institutoTimezoneService->parseDateString($strDateFrom, $parseFormat);
            $dateTo = $this->institutoTimezoneService->parseDateString($strDateTo, $parseFormat);
        } else {
            $dateFrom = $this->parseDateString($strDateFrom);
            $dateTo = $this->parseDateString($strDateTo);
        }
        if (!$dateFrom || !$dateTo || $dateTo < $dateFrom) {
            return [];
        }
        $aryRange = [];
        $current = clone $dateFrom;
        while ($current <= $dateTo) {
            $aryRange[] = $current->format($outputFormat);
            $current->modify('+1 day');
        }
        return $aryRange;
    }

    /**
     * Parsea una fecha en formato d/m/Y o Y-m-d.
     */
    private function parseDateString(string $str): ?\DateTime
    {
        $d = \DateTime::createFromFormat('d/m/Y', $str);
        if ($d !== false) {
            return $d;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $str);
        return $d !== false ? $d : null;
    }
}
