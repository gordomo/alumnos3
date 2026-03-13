<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Entity\DeudaAlumno;
use App\Repository\AlumnoRepository;
use App\Repository\DeudaAlumnoRepository;
use App\Repository\CursoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Knp\Component\Pager\PaginatorInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @Route("/instituto/deuda")
 */
class DeudaAlumnoController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/alumno/{id}", name="app_deuda_alumno_index", methods={"GET"})
     */
    public function index(
        Request $request, 
        Alumno $alumno, 
        DeudaAlumnoRepository $deudaRepository,
        CursoRepository $cursoRepository,
        PaginatorInterface $paginator,
        \App\Service\DeudaCalculatorService $deudaCalculator
    ): Response {
        // Verificar acceso
        $instituto = $this->getUser()->getInstituto();
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        // Sincronizar deudas calculadas on-demand con la tabla
        $deudaCalculator->sincronizarDeudasConTabla($alumno);
        $this->entityManager->refresh($alumno);
        
        // Obtener parámetros de filtrado
        $cursoId = $request->query->get('curso');
        $estadoDeuda = $request->query->get('estado', 'pendientes'); // pendientes, pagadas, todas
        $sort = $request->query->get('sort', 'fecha');
        $order = $request->query->get('order', 'asc');
        
        // Crear query builder
        $qb = $deudaRepository->createQueryBuilder('d')
            ->where('d.alumno = :alumno')
            ->setParameter('alumno', $alumno);
            
        // Filtrar por curso si se especifica
        if ($cursoId) {
            $curso = $cursoRepository->find($cursoId);
            if ($curso && $curso->getInstituto() === $instituto) {
                $qb->andWhere('d.curso = :curso')
                   ->setParameter('curso', $curso);
            }
        }
        
        // Filtrar por estado de deuda
        if ($estadoDeuda === 'pendientes') {
            $qb->leftJoin('d.aplicaciones', 'pa')
               ->groupBy('d.id')
               ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)');
        } elseif ($estadoDeuda === 'pagadas') {
            $qb->leftJoin('d.aplicaciones', 'pa')
               ->groupBy('d.id')
               ->having('COALESCE(SUM(pa.montoAplicado), 0) >= d.monto + COALESCE(d.interes, 0)');
        }
        
        // Ordenar resultados
        switch ($sort) {
            case 'fecha':
                $qb->orderBy('d.ano', $order)
                   ->addOrderBy('d.mes', $order);
                break;
            case 'curso':
                $qb->leftJoin('d.curso', 'c')
                   ->orderBy('c.nombre', $order);
                break;
            case 'monto':
                $qb->orderBy('d.monto', $order);
                break;
            default:
                $qb->orderBy('d.ano', $order)
                   ->addOrderBy('d.mes', $order);
        }
        
        // Obtener cursos del alumno para el filtro
        $cursos = $alumno->getCurso();
        
        // Paginar resultados
        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            10
        );
        
        return $this->render('deuda_alumno/index.html.twig', [
            'alumno' => $alumno,
            'deudas' => $pagination,
            'cursos' => $cursos,
            'cursoId' => $cursoId,
            'estadoDeuda' => $estadoDeuda,
            'sort' => $sort,
            'order' => $order
        ]);
    }
    
    /**
     * @Route("/cancelar/{id}", name="app_deuda_alumno_cancelar", methods={"GET", "POST"})
     */
    public function cancelarDeuda(Request $request, DeudaAlumno $deuda): Response
    {
        // Verificar acceso
        $instituto = $this->getUser()->getInstituto();
        if ($deuda->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a esta deuda.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        // Verificar que la deuda no esté pagada
        if ($deuda->isPagado()) {
            $this->addFlash('warning', 'Esta deuda ya ha sido pagada y no puede ser cancelada.');
            return $this->redirectToRoute('app_deuda_alumno_index', ['id' => $deuda->getAlumno()->getId()]);
        }
        
        // Procesar la cancelación si es una solicitud POST
        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('cancel'.$deuda->getId(), $request->request->get('_token'))) {
                // Eliminar la deuda
                $alumno = $deuda->getAlumno();
                $this->entityManager->remove($deuda);
                $this->entityManager->flush();
                
                $this->addFlash('success', 'La deuda ha sido cancelada correctamente.');
                return $this->redirectToRoute('app_deuda_alumno_index', ['id' => $alumno->getId()]);
            }
        }
        
        return $this->render('deuda_alumno/cancelar.html.twig', [
            'deuda' => $deuda
        ]);
    }
} 