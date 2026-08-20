<?php

namespace App\Controller;

use App\Entity\ProfesorPago;
use App\Entity\Profesor;
use App\Entity\ProfesorCursoPago;
use App\Repository\ProfesorRepository;
use App\Repository\ProfesorPagoRepository;
use App\Repository\ProfesorCursoPagoRepository;
use App\Service\ProfesorCursoPagoService;
use App\Service\ProfesorPagoService;
use App\Service\InstitutoTimezoneService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Knp\Component\Pager\PaginatorInterface;

/**
 * @Route("/instituto/profesor-pagos")
 */
class ProfesorPagoController extends AbstractController
{
    private $profesorPagoService;
    private $institutoTimezoneService;

    public function __construct(
        ProfesorPagoService $profesorPagoService,
        InstitutoTimezoneService $institutoTimezoneService
    ) {
        $this->profesorPagoService = $profesorPagoService;
        $this->institutoTimezoneService = $institutoTimezoneService;
    }

    /**
     * @Route("/", name="app_profesor_pago_index", methods={"GET"})
     */
    public function index(
        Request $request,
        ProfesorPagoRepository $profesorPagoRepository,
        PaginatorInterface $paginator
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        
        $qb = $profesorPagoRepository->createQueryBuilder('pp')
            ->innerJoin('pp.profesor', 'prof')
            ->andWhere('prof.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('pp.fechaPago', 'DESC');

        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            20
        );

        return $this->render('profesor_pago/index.html.twig', [
            'pagos' => $pagination,
        ]);
    }

    /**
     * @Route("/liquidacion", name="app_profesor_pago_liquidacion", methods={"GET"})
     */
    public function liquidacion(
        Request $request,
        ProfesorRepository $profesorRepository
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        
        // Obtener mes y año (por defecto, mes actual)
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $mes = $request->query->getInt('mes', (int)$fechaActual->format('n'));
        $ano = $request->query->getInt('ano', (int)$fechaActual->format('Y'));
        
        // Obtener todos los profesores del instituto
        $profesores = $profesorRepository->findBy(['instituto' => $instituto]);
        
        $liquidaciones = [];
        foreach ($profesores as $profesor) {
            $liquidacion = $this->profesorPagoService->obtenerLiquidacion($profesor, $mes, $ano);
            $liquidaciones[] = [
                'profesor' => $profesor,
                'liquidacion' => $liquidacion
            ];
        }

        return $this->render('profesor_pago/liquidacion.html.twig', [
            'liquidaciones' => $liquidaciones,
            'mes' => $mes,
            'ano' => $ano,
        ]);
    }

    /**
     * @Route("/registrar/{profesorId}", name="app_profesor_pago_registrar", methods={"GET", "POST"})
     */
    public function registrar(
        Request $request,
        int $profesorId,
        ProfesorRepository $profesorRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        $profesor = $profesorRepository->find($profesorId);

        if (!$profesor || $profesor->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'Profesor no encontrado.');
            return $this->redirectToRoute('app_profesor_pago_liquidacion');
        }

        // Obtener mes y año
        $fechaActual = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $mes = $request->query->getInt('mes', (int)$fechaActual->format('n'));
        $ano = $request->query->getInt('ano', (int)$fechaActual->format('Y'));

        // Obtener liquidación
        $liquidacion = $this->profesorPagoService->obtenerLiquidacion($profesor, $mes, $ano);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profesor_pago_registrar' . $profesorId, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad invalido. Volve a intentar.');
                return $this->redirectToRoute('app_profesor_pago_index');
            }

            $monto = $request->request->get('monto');
            $metodoPago = $request->request->get('metodo_pago');
            $observacion = $request->request->get('observacion');
            $fechaPago = $request->request->get('fecha_pago');

            if (!$monto || $monto <= 0) {
                $this->addFlash('danger', 'El monto debe ser mayor a 0.');
                return $this->redirectToRoute('app_profesor_pago_registrar', [
                    'profesorId' => $profesorId,
                    'mes' => $mes,
                    'ano' => $ano
                ]);
            }

            if (!$metodoPago) {
                $this->addFlash('danger', 'El método de pago es obligatorio.');
                return $this->redirectToRoute('app_profesor_pago_registrar', [
                    'profesorId' => $profesorId,
                    'mes' => $mes,
                    'ano' => $ano
                ]);
            }

            try {
                $pago = new ProfesorPago();
                $pago->setProfesor($profesor);
                $pago->setMes($mes);
                $pago->setAno($ano);
                $pago->setMonto((float)$monto);
                $pago->setMetodoPago($metodoPago);
                $pago->setObservacion($observacion);
                // Cierra el mes aunque el monto sea menor al calculado: es el arreglo interno.
                $pago->setSaldaTotal($request->request->has('salda_total'));
                $pago->setFechaPago($fechaPago ? new \DateTime($fechaPago) : $this->institutoTimezoneService->getNowForInstituto($instituto));
                $pago->setDetalleCalculo(json_encode($liquidacion['detalle_calculo']));

                $entityManager->persist($pago);
                $entityManager->flush();

                if ($pago->isSaldaTotal()) {
                    $diferencia = $liquidacion['saldo_pendiente'] - (float) $monto;
                    $this->addFlash('success', $diferencia > 0.009
                        ? sprintf(
                            'Pago registrado. La liquidación de este mes queda saldada: se dejaron de pagar $%s respecto del cálculo.',
                            number_format($diferencia, 2, ',', '.')
                        )
                        : 'Pago registrado. La liquidación de este mes queda saldada.');
                } else {
                    $this->addFlash('success', 'Pago registrado exitosamente.');
                }
                return $this->redirectToRoute('app_profesor_pago_liquidacion', [
                    'mes' => $mes,
                    'ano' => $ano
                ]);

            } catch (\Exception $e) {
                $this->addFlash('danger', 'Error al registrar el pago: ' . $e->getMessage());
            }
        }

        $fechaActualInstituto = $this->institutoTimezoneService->getTodayForInstituto($instituto);
        $app_date_format = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        
        // Obtener métodos de pago del instituto
        $metodosPago = $entityManager->getRepository(\App\Entity\MetodoPago::class)
            ->findBy(['instituto' => $instituto, 'activo' => true], ['orden' => 'ASC']);

        return $this->render('profesor_pago/registrar.html.twig', [
            'profesor' => $profesor,
            'liquidacion' => $liquidacion,
            'mes' => $mes,
            'ano' => $ano,
            'fecha_actual_instituto' => $fechaActualInstituto,
            'app_date_format' => $app_date_format,
            'metodos_pago' => $metodosPago
        ]);
    }


    /**
     * Reglas de pago por curso de un profesor.
     *
     * Un profesor puede cobrar de forma distinta en cada curso, y eso es lo que se carga acá.
     * El curso que no tenga regla prendida se liquida con la configuración del profesor, que es
     * lo que pasa por default.
     *
     * @Route("/reglas/{profesorId}", name="app_profesor_pago_reglas", methods={"GET", "POST"})
     */
    public function reglas(
        Request $request,
        int $profesorId,
        ProfesorRepository $profesorRepository,
        ProfesorCursoPagoRepository $reglaRepository,
        ProfesorCursoPagoService $reglaService
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        $profesor = $profesorRepository->find($profesorId);

        if (!$profesor || $profesor->getInstituto() !== $instituto) {
            throw $this->createNotFoundException('Profesor no encontrado');
        }

        $cursos = $profesor->getCursos()->toArray();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reglas_pago_' . $profesor->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'El formulario expiró. Volvé a intentarlo.');

                return $this->redirectToRoute('app_profesor_pago_reglas', ['profesorId' => $profesor->getId()]);
            }

            $resultado = $reglaService->guardarReglas(
                $profesor,
                $cursos,
                $request->request->all('modalidad'),
                $request->request->all('valores'),
                $request->request->all('regla_activa')
            );

            $this->addFlash('success', sprintf(
                'Reglas guardadas: %d activa(s)%s.',
                $resultado['guardadas'],
                $resultado['apagadas'] > 0 ? sprintf(', %d desactivada(s)', $resultado['apagadas']) : ''
            ));

            if ($resultado['incompletas']) {
                $this->addFlash('warning', sprintf(
                    'Estas reglas quedaron sin el valor que necesitan y se van a liquidar con la configuración del profesor: %s.',
                    implode(', ', $resultado['incompletas'])
                ));
            }

            return $this->redirectToRoute('app_profesor_pago_reglas', ['profesorId' => $profesor->getId()]);
        }

        return $this->render('profesor_pago/reglas.html.twig', [
            'profesor' => $profesor,
            'cursos' => $cursos,
            'reglas' => $reglaRepository->findByProfesorIndexadoPorCurso($profesor),
            'modalidades' => ProfesorCursoPago::MODALIDADES,
        ]);
    }

    /**
     * @Route("/{id}", name="app_profesor_pago_show", methods={"GET"})
     */
    public function show(ProfesorPago $profesorPago): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if ($profesorPago->getProfesor()->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'Pago no encontrado.');
            return $this->redirectToRoute('app_profesor_pago_index');
        }

        return $this->render('profesor_pago/show.html.twig', [
            'pago' => $profesorPago,
        ]);
    }

    /**
     * @Route("/{id}/recibo", name="app_profesor_pago_recibo", methods={"GET"})
     */
    public function recibo(ProfesorPago $profesorPago): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if ($profesorPago->getProfesor()->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'Pago no encontrado.');
            return $this->redirectToRoute('app_profesor_pago_index');
        }

        $app_date_format = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);

        return $this->render('profesor_pago/recibo.html.twig', [
            'pago' => $profesorPago,
            'instituto' => $instituto,
            'app_date_format' => $app_date_format
        ]);
    }
}
