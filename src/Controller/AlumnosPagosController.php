<?php

namespace App\Controller;

use App\Entity\AlumnosPagos;
use App\Entity\Alumno;
use App\Entity\Curso;
use App\Form\AlumnosPagosType;
use App\Repository\AlumnosPagosRepository;
use App\Repository\VencimientoRepository;
use App\Repository\AlumnoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Repository\CursoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\HistorialCursosService;
use Knp\Component\Pager\PaginatorInterface;
/**
 * @Route("/alumnos/pagos")
 */
class AlumnosPagosController extends AbstractController
{
    private $entityManager;
    private $historialCursosService;

    public function __construct(EntityManagerInterface $entityManager, HistorialCursosService $historialCursosService)
    {
        $this->entityManager = $entityManager;
        $this->historialCursosService = $historialCursosService;
    }

    /**
     * @Route("/", name="app_alumnos_pagos_index", methods={"GET"})
     */
    public function index(
        Request $request,
        AlumnosPagosRepository $alumnosPagosRepository,
        CursoRepository $cursoRepository,
        AlumnoRepository $alumnoRepository,
        PaginatorInterface $paginator
    ): Response {
        $busqueda = $request->get('busqueda', '');
        $cursoSelected = $request->get('curso', '');
        $metodoSelected = $request->get('metodoPago', '');
        $fechaDesde = $request->get('fechaDesde', '');
        $fechaHasta = $request->get('fechaHasta', '');
        $sort = $request->get('sort', 'fecha');
        $order = $request->get('order', 'desc');

        $alumnoId = $request->query->get('alumno');
        $alumno = null;
        $mesesAdeudados = [];
        $nombreAlumno = '';

        // Obtener el instituto del usuario actual
        $instituto = $this->getUser()->getInstituto();
        
        if ($alumnoId) {
            //$pagos = $alumnosPagosRepository->findByAlumno($alumnoId);
            $alumno = $alumnoRepository->find($alumnoId);
            $nombreAlumno = $alumno->getNombre() . ' ' . $alumno->getApellido();
            $mesesAdeudados = $this->historialCursosService->verificarMesesAdeudados($alumno);
        } else {
            //$pagos = $alumnosPagosRepository->findByInstituto($instituto);
            $nombreAlumno = $instituto->getNombre();
        }

        // Crear QueryBuilder
        $qb = $alumnosPagosRepository->createQueryBuilder('p')
            ->leftJoin('p.alumno', 'a')
            ->leftJoin('p.curso', 'c')
            ->andWhere('a.instituto = :instituto')
            ->setParameter('instituto', $instituto);

        // Aplicar filtros
        if ($busqueda) {
            $qb->andWhere('a.nombre LIKE :busqueda OR a.apellido LIKE :busqueda')
               ->setParameter('busqueda', '%' . $busqueda . '%');
        }

        if ($cursoSelected) {
            $qb->andWhere('c.id = :curso')
               ->setParameter('curso', $cursoSelected);
        }

        if ($metodoSelected) {
            $qb->andWhere('p.metodoPago = :metodo')
               ->setParameter('metodo', $metodoSelected);
        }

        if ($fechaDesde) {
            $qb->andWhere('p.fecha >= :fechaDesde')
               ->setParameter('fechaDesde', new \DateTime($fechaDesde));
        }

        if ($fechaHasta) {
            $qb->andWhere('p.fecha <= :fechaHasta')
               ->setParameter('fechaHasta', new \DateTime($fechaHasta));
        }

        if($alumnoId){
            $qb->andWhere('p.alumno = :alumno')
               ->setParameter('alumno', $alumno);
        }

        // Aplicar ordenamiento
        switch ($sort) {
            case 'fecha':
                $qb->orderBy('p.fecha', $order);
                break;
            case 'alumno':
                $qb->orderBy('a.apellido', $order)
                   ->addOrderBy('a.nombre', $order);
                break;
            case 'curso':
                $qb->orderBy('c.nombre', $order);
                break;
            case 'monto':
                $qb->orderBy('p.monto', $order);
                break;
            case 'metodoPago':
                $qb->orderBy('p.metodoPago', $order);
                break;
            case 'mes':
                $qb->orderBy('p.mes', $order);
                break;
            case 'ano':
                $qb->orderBy('p.ano', $order);
                break;
            default:
                $qb->orderBy('p.fecha', 'desc');
        }

        // Obtener todos los cursos para el filtro
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);

        // Obtener métodos de pago únicos
        $metodosPago = $alumnosPagosRepository->createQueryBuilder('p')
            ->select('DISTINCT p.metodoPago')
            ->andWhere('p.alumno IN (SELECT a2 FROM App\Entity\Alumno a2 WHERE a2.instituto = :instituto)')
            ->setParameter('instituto', $instituto)
            ->getQuery()
            ->getSingleColumnResult();

        // Paginar resultados
        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            20
        );

        return $this->render('alumnos_pagos/index.html.twig', [
            'pagos' => $pagination,
            'alumno' => $alumno,
            'nombreAlumno' => $nombreAlumno,
            'cursos' => $cursos,
            'cursoSelected' => $cursoSelected,
            'metodosPago' => $metodosPago,
            'metodoSelected' => $metodoSelected,
            'fechaDesde' => $fechaDesde,
            'fechaHasta' => $fechaHasta,
            'busqueda' => $busqueda,
            'sort' => $sort,
            'order' => $order,
            'total' => $pagination->getTotalItemCount()
        ]);
    }

    /**
     * @Route("/{id}", name="app_alumnos_pagos_show", methods={"GET"})
     */
    public function show(AlumnosPagos $pago): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        return $this->render('alumnos_pagos/show.html.twig', [
            'alumnos_pago' => $pago,
            'instituto' => $instituto
        ]);
    }

    
    /**
     * Verifica los meses adeudados para un alumno y curso específico
     */
    private function verificarMesesAdeudadosPorCurso(Alumno $alumno, Curso $curso, int $ano): array
    {
        return $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $curso);
    }

    /**
     * Calcula el monto sugerido para un pago
     */
    private function calcularMonto(Alumno $alumno, Curso $curso, $vencimientos, array $mesesAdeudados): array
    {
        // Obtener fecha actual
        $fechaActual = new \DateTime();
        $mesActual = (int)$fechaActual->format('n');
        $anoActual = (int)$fechaActual->format('Y');

        $montoBase = $curso->getPrecio();
        $montoFinal = $montoBase;
        $porcentajeInteres = 0;

        // Si hay meses adeudados, aplicar el máximo interés
        if (!empty($mesesAdeudados)) {
            // Buscar el máximo interés en los vencimientos
            foreach ($vencimientos as $vencimiento) {
                if ($vencimiento->getPorcentajeInteres() > $porcentajeInteres) {
                    $porcentajeInteres = $vencimiento->getPorcentajeInteres();
                }
            }
        } else {
            // Si no hay meses adeudados, calcular el interés según la fecha actual
            $porcentajeInteres = $alumno->getPorcentajeInteresAplicable();
        }

        // Calcular el monto final con el interés correspondiente
        if ($porcentajeInteres > 0) {
            $montoFinal = $montoBase * (1 + ($porcentajeInteres / 100));
        }
        
        return [
            'monto' => $montoFinal,
            'montoBase' => $montoBase,
            'porcentajeInteres' => $porcentajeInteres,
            'mesesAdeudados' => array_values($mesesAdeudados)
        ];
    }

    /**
     * @Route("/new/{id}", name="app_alumnos_pagos_new", methods={"GET", "POST"})
     */
    public function new(Request $request, Alumno $alumno): Response
    {
        $alumnosPago = new AlumnosPagos();
        $alumnosPago->setAlumno($alumno);
        $alumnosPago->setFecha(new \DateTime());
        $alumnosPago->setMetodoPago('Efectivo');

        // Obtener los meses adeudados para el componente
        $mesesAdeudados = $this->historialCursosService->verificarMesesAdeudados($alumno);
        
        // Obtener el curso seleccionado si existe
        $cursoSeleccionado = null;
        if ($request->query->has('curso')) {
            $cursoId = $request->query->get('curso');
            $cursoSeleccionado = $this->entityManager->getRepository(Curso::class)->find($cursoId);
        }

        // Obtener los vencimientos
        $vencimientos = $alumno->getInstituto()->getVencimientos();

        // Si hay meses adeudados y no hay curso seleccionado, establecer valores por defecto
        if (!empty($mesesAdeudados) && !$cursoSeleccionado) {
            $primerMesAdeudado = $mesesAdeudados[0];
            $alumnosPago->setMes($primerMesAdeudado['mes']);
            $alumnosPago->setAno($primerMesAdeudado['ano']);
            
            $alumnosPago->setCurso($primerMesAdeudado['curso_obj']);
            $cursoSeleccionado = $primerMesAdeudado['curso_obj'];
        } elseif ($cursoSeleccionado) {
            // Si hay un curso seleccionado, establecerlo
            $alumnosPago->setCurso($cursoSeleccionado);
        }   

        // Calcular el monto sugerido si hay un curso seleccionado
        if ($cursoSeleccionado) {
            $mesesAdeudadosCurso = $this->historialCursosService->verificarMesesAdeudadosPorCurso($alumno, $cursoSeleccionado);
            $calculoMonto = $this->calcularMonto($alumno, $cursoSeleccionado, $vencimientos, $mesesAdeudadosCurso);
            $alumnosPago->setMonto($calculoMonto['monto']);
        }

        $cursosHistoricos = $alumno->getCursosHistoricos();
        $cursos = [];
        foreach ($cursosHistoricos as $cursoHistorico) {
            $cursos[] = $cursoHistorico->getCurso();
        }

        // Crear el formulario
        $form = $this->createForm(AlumnosPagosType::class, $alumnosPago, [
            'alumnos' => [$alumno],
            'cursos' => array_values($cursos),
            'vencimientos' => $vencimientos
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                try {
                    // Verificar si ya existe un pago para este alumno, curso, mes y año
                    $pagoExistente = $this->entityManager->getRepository(AlumnosPagos::class)->findOneBy([
                        'alumno' => $alumnosPago->getAlumno(),
                        'curso' => $alumnosPago->getCurso(),
                        'mes' => $alumnosPago->getMes(),
                        'ano' => $alumnosPago->getAno()
                    ]);

                    if ($pagoExistente) {
                        $this->addFlash('danger', 'Ya existe un pago registrado para este alumno en este curso para el mes y año seleccionados.');
                        return $this->redirectToRoute('app_alumnos_pagos_new', [
                            'id' => $alumno->getId(),
                            'curso' => $alumnosPago->getCurso()->getId()
                        ]);
                    }

                    // Registrar el pago en el historial
                    $this->historialCursosService->registrarPago($alumnosPago);
                    
                    $this->addFlash('success', 'Pago creado correctamente.');
                    return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumno->getId()]);
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Ocurrió un error al crear el pago.');
                }
            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('alumnos_pagos/new.html.twig', [
            'form' => $form->createView(),
            'alumno' => $alumno,
            'cursos' => array_values($cursos),
            'curso' => $cursoSeleccionado,
            'vencimientos' => $vencimientos,
            'is_general' => false,
            'mesesAdeudados' => $mesesAdeudados
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_alumnos_pagos_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, AlumnosPagos $pago, VencimientoRepository $vencimientoRepository, CursoRepository $cursoRepository): Response
    {
        $alumno = $pago->getAlumno();
        $instituto = $this->getUser()->getInstituto();
        
        // Obtener los cursos históricos del alumno
        $cursosHistoricos = $alumno->getCursosHistoricos();
        $cursos = [];
        foreach ($cursosHistoricos as $cursoHistorico) {
            $cursos[] = $cursoHistorico->getCurso();
        }

        // Obtener los vencimientos del instituto
        $vencimientos = $instituto->getVencimientos();

        // Crear el formulario
        $form = $this->createForm(AlumnosPagosType::class, $pago, [
            'alumnos' => [$alumno],
            'cursos' => array_values($cursos),
            'vencimientos' => $vencimientos
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                try {
                    // Verificar si ya existe un pago para este alumno, curso, mes y año
                    $pagoExistente = $this->entityManager->getRepository(AlumnosPagos::class)->findOneBy([
                        'alumno' => $pago->getAlumno(),
                        'curso' => $pago->getCurso(),
                        'mes' => $pago->getMes(),
                        'ano' => $pago->getAno()
                    ]);

                    if ($pagoExistente && $pagoExistente->getId() !== $pago->getId()) {
                        $this->addFlash('danger', 'Ya existe un pago registrado para este alumno en este curso para el mes y año seleccionados.');
                        return $this->redirectToRoute('app_alumnos_pagos_edit', ['id' => $pago->getId()]);
                    }

                    // Actualizar el pago en el historial
                    $this->historialCursosService->actualizarPago($pago);
                    
                    $this->addFlash('success', 'Pago actualizado correctamente.');
                    return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumno->getId()]);
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Ocurrió un error al actualizar el pago.');
                }
            } else {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('alumnos_pagos/edit.html.twig', [
            'form' => $form->createView(),
            'alumno' => $alumno,
            'cursos' => array_values($cursos),
            'curso' => $pago->getCurso(),
            'vencimientos' => $vencimientos,
            'is_general' => false
        ]);
    }

    /**
     * @Route("/{id}/delete", name="app_alumnos_pagos_delete", methods={"POST"})
     */
    public function delete(Request $request, AlumnosPagos $pago): Response
    {
        if ($this->isCsrfTokenValid('delete'.$pago->getId(), $request->request->get('_token'))) {
            $alumnoId = $pago->getAlumno()->getId();
            $this->entityManager->remove($pago);
            $this->entityManager->flush();
            $this->addFlash('success', 'Pago eliminado correctamente.');
        }

        return $this->redirectToRoute('app_alumnos_pagos_index', ['alumno' => $alumnoId]);
    }

    /**
     * @Route("/verificar-meses-adeudados/{id}", name="app_alumnos_pagos_verificar_meses_adeudados", methods={"GET"})
     */
    public function verificarMesesAdeudados(Alumno $alumno, PagosService $pagosService): JsonResponse
    {
        $mesesAdeudados = $pagosService->verificarMesesAdeudados($alumno);
        return $this->json(['mesesAdeudados' => $mesesAdeudados]);
    }
}
