<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Entity\DeudaAlumno;
use App\Entity\SaldoFavor;
use App\Entity\SaldoFavorAplicacion;
use App\Repository\DeudaAlumnoRepository;
use App\Repository\SaldoFavorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/instituto/saldo-favor")
 */
class SaldoFavorController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Lista los saldos a favor de un alumno
     *
     * @Route("/alumno/{id}", name="app_saldo_favor_index", methods={"GET"})
     */
    public function index(Alumno $alumno, SaldoFavorRepository $saldoFavorRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }

        $saldos = $saldoFavorRepository->findByAlumno($alumno);
        $saldoTotal = $saldoFavorRepository->getSaldoDisponibleTotal($alumno);

        return $this->render('saldo_favor/index.html.twig', [
            'alumno' => $alumno,
            'saldos' => $saldos,
            'saldoTotal' => $saldoTotal,
        ]);
    }

    /**
     * Crear una nota de crédito / saldo a favor manual
     *
     * @Route("/alumno/{id}/new", name="app_saldo_favor_new", methods={"GET", "POST"})
     */
    public function new(Request $request, Alumno $alumno): Response
    {
        $instituto = $this->getUser()->getInstituto();
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('saldo-favor-new', $token)) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_saldo_favor_new', ['id' => $alumno->getId()]);
            }

            $monto = (float) $request->request->get('monto');
            $descripcion = $request->request->get('descripcion');
            $tipo = $request->request->get('tipo', SaldoFavor::TIPO_NOTA_CREDITO);
            $cursoId = $request->request->get('curso_id');

            if ($monto <= 0) {
                $this->addFlash('danger', 'El monto debe ser mayor a 0.');
                return $this->redirectToRoute('app_saldo_favor_new', ['id' => $alumno->getId()]);
            }

            $validTipos = [SaldoFavor::TIPO_NOTA_CREDITO, SaldoFavor::TIPO_SOBREPAGO, SaldoFavor::TIPO_CANCELACION_DEUDA];
            if (!in_array($tipo, $validTipos, true)) {
                $tipo = SaldoFavor::TIPO_NOTA_CREDITO;
            }

            $saldo = new SaldoFavor();
            $saldo->setAlumno($alumno);
            $saldo->setInstituto($instituto);
            $saldo->setMonto(number_format($monto, 2, '.', ''));
            $saldo->setMontoDisponible(number_format($monto, 2, '.', ''));
            $saldo->setTipo($tipo);
            $saldo->setDescripcion($descripcion);

            if ($cursoId) {
                $curso = $this->entityManager->getRepository(\App\Entity\Curso::class)->find($cursoId);
                if ($curso && $curso->getInstituto() === $instituto) {
                    $saldo->setCurso($curso);
                }
            }

            $this->entityManager->persist($saldo);
            $this->entityManager->flush();

            $this->addFlash('success', 'Saldo a favor creado exitosamente por $' . number_format($monto, 2, ',', '.'));
            return $this->redirectToRoute('app_saldo_favor_index', ['id' => $alumno->getId()]);
        }

        // Obtener cursos activos del alumno para el selector
        $cursosActivos = [];
        foreach ($alumno->getCursosHistoricos() as $historico) {
            if ($historico->isActivo()) {
                $cursosActivos[] = $historico->getCurso();
            }
        }

        return $this->render('saldo_favor/new.html.twig', [
            'alumno' => $alumno,
            'cursosActivos' => $cursosActivos,
        ]);
    }

    /**
     * Aplicar un saldo a favor a una deuda específica
     *
     * @Route("/{id}/aplicar", name="app_saldo_favor_aplicar", methods={"GET", "POST"})
     */
    public function aplicar(
        Request $request,
        SaldoFavor $saldoFavor,
        DeudaAlumnoRepository $deudaAlumnoRepository
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        if ($saldoFavor->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene acceso a este saldo.');
            return $this->redirectToRoute('app_alumno_index');
        }

        if (!$saldoFavor->tieneSaldoDisponible()) {
            $this->addFlash('warning', 'Este saldo a favor ya fue utilizado en su totalidad.');
            return $this->redirectToRoute('app_saldo_favor_index', ['id' => $saldoFavor->getAlumno()->getId()]);
        }

        $alumno = $saldoFavor->getAlumno();

        // Obtener deudas pendientes del alumno
        $deudasPendientes = $deudaAlumnoRepository->createQueryBuilder('d')
            ->andWhere('d.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->getQuery()
            ->getResult();

        // Filtrar solo las que tienen monto pendiente
        $deudasPendientes = array_filter($deudasPendientes, function (DeudaAlumno $deuda) {
            return $deuda->getMontoPendiente() > 0;
        });

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('saldo-favor-aplicar' . $saldoFavor->getId(), $token)) {
                $this->addFlash('danger', 'Token CSRF inválido.');
                return $this->redirectToRoute('app_saldo_favor_aplicar', ['id' => $saldoFavor->getId()]);
            }

            $deudaId = (int) $request->request->get('deuda_id');
            $montoAplicar = (float) $request->request->get('monto_aplicar');

            if ($montoAplicar <= 0) {
                $this->addFlash('danger', 'El monto a aplicar debe ser mayor a 0.');
                return $this->redirectToRoute('app_saldo_favor_aplicar', ['id' => $saldoFavor->getId()]);
            }

            $deuda = $deudaAlumnoRepository->find($deudaId);
            if (!$deuda || $deuda->getAlumno() !== $alumno) {
                $this->addFlash('danger', 'La deuda seleccionada no es válida.');
                return $this->redirectToRoute('app_saldo_favor_aplicar', ['id' => $saldoFavor->getId()]);
            }

            $saldoDisponible = (float) $saldoFavor->getMontoDisponible();
            $montoPendienteDeuda = $deuda->getMontoPendiente();

            // El monto aplicado no puede superar ni el saldo disponible ni la deuda pendiente
            $montoAplicar = min($montoAplicar, $saldoDisponible, $montoPendienteDeuda);

            if ($montoAplicar <= 0) {
                $this->addFlash('danger', 'No se puede aplicar este monto.');
                return $this->redirectToRoute('app_saldo_favor_aplicar', ['id' => $saldoFavor->getId()]);
            }

            // Crear la aplicación
            $aplicacion = new SaldoFavorAplicacion();
            $aplicacion->setSaldoFavor($saldoFavor);
            $aplicacion->setDeuda($deuda);
            $aplicacion->setMontoAplicado(number_format($montoAplicar, 2, '.', ''));

            // Reducir saldo disponible
            $nuevoSaldo = $saldoDisponible - $montoAplicar;
            $saldoFavor->setMontoDisponible(number_format(max(0, $nuevoSaldo), 2, '.', ''));

            $this->entityManager->persist($aplicacion);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                'Se aplicaron $%s del saldo a favor a la deuda de %s. Saldo restante: $%s',
                number_format($montoAplicar, 2, ',', '.'),
                $deuda->getPeriodo(),
                number_format(max(0, $nuevoSaldo), 2, ',', '.')
            ));

            // Si todavía tiene saldo, volver a la pantalla de aplicar
            if ($nuevoSaldo > 0) {
                return $this->redirectToRoute('app_saldo_favor_aplicar', ['id' => $saldoFavor->getId()]);
            }

            return $this->redirectToRoute('app_saldo_favor_index', ['id' => $alumno->getId()]);
        }

        return $this->render('saldo_favor/aplicar.html.twig', [
            'saldoFavor' => $saldoFavor,
            'alumno' => $alumno,
            'deudasPendientes' => $deudasPendientes,
        ]);
    }
}
