<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Repository\AlumnoCursoHistoricoRepository;
use App\Service\DeudaCalculatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/pagos-historicos")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class PagosHistoricosController extends AbstractController
{
    public function __construct(
        private DeudaCalculatorService $deudaCalculator
    ) {
    }

    /**
     * Arma una fila de la tabla a partir del histórico.
     *
     * Antes se llamaba a getPrecio(), getMesesPagados(), getMesesAdeudados() y
     * getEstado(), que no existen en AlumnoCursoHistorico: la pantalla tiraba un
     * "Call to undefined method" en cuanto había un histórico. Se usan los getters
     * reales y los meses se derivan de los pagos registrados y del cálculo de deudas.
     */
    private static function filaHistorico(
        AlumnoCursoHistorico $historico,
        DeudaCalculatorService $deudaCalculator
    ): array {
        $nombresMeses = [
            1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
        ];

        $mesesPagados = [];
        foreach ($historico->getPagos() as $pago) {
            if ($pago->getMes() && $pago->getAno()) {
                $mesesPagados[$pago->getAno() . '-' . $pago->getMes()] =
                    $nombresMeses[$pago->getMes()] . ' ' . $pago->getAno();
            }
        }

        $mesesAdeudados = [];
        foreach ($deudaCalculator->calcularDeudasParaHistorico($historico) as $deuda) {
            $mesesAdeudados[$deuda['ano'] . '-' . $deuda['mes']] =
                $nombresMeses[$deuda['mes']] . ' ' . $deuda['ano'];
        }

        return [
            'curso' => $historico->getCurso(),
            'fecha_inicio' => $historico->getFechaInicio(),
            'fecha_fin' => $historico->getFechaFin(),
            'precio' => $historico->getPrecioMensual() ?? 0.0,
            'meses_pagados' => array_values($mesesPagados),
            'meses_adeudados' => array_values($mesesAdeudados),
            'estado' => $historico->getEstadoLabel(),
        ];
    }

    /**
     * @Route("/", name="app_pagos_historicos_index", methods={"GET"})
     */
    public function index(AlumnoCursoHistoricoRepository $historicoRepository): Response
    {
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No se encontró el instituto asociado a tu usuario.');
            return $this->redirectToRoute('app_login');
        }

        // Solo históricos de alumnos del instituto del usuario.
        $historicos = $historicoRepository->createQueryBuilder('h')
            ->innerJoin('h.alumno', 'a')
            ->addSelect('a')
            ->leftJoin('h.curso', 'c')
            ->addSelect('c')
            ->where('a.instituto = :instituto')
            ->setParameter('instituto', $user->getInstituto())
            ->orderBy('a.apellido', 'ASC')
            ->addOrderBy('a.nombre', 'ASC')
            ->addOrderBy('h.fechaInicio', 'DESC')
            ->getQuery()
            ->getResult();

        // Agrupar por alumno
        $pagosPorAlumno = [];
        foreach ($historicos as $historico) {
            $alumno = $historico->getAlumno();
            $alumnoId = $alumno->getId();

            if (!isset($pagosPorAlumno[$alumnoId])) {
                $pagosPorAlumno[$alumnoId] = [
                    'alumno' => $alumno,
                    'cursos' => []
                ];
            }

            $pagosPorAlumno[$alumnoId]['cursos'][] = self::filaHistorico($historico, $this->deudaCalculator);
        }

        return $this->render('pagos_historicos/index.html.twig', [
            'pagos_por_alumno' => $pagosPorAlumno
        ]);
    }

    /**
     * @Route("/alumno/{id}", name="app_pagos_historicos_alumno", methods={"GET"})
     */
    public function alumno(AlumnoCursoHistoricoRepository $historicoRepository, Alumno $alumno): Response
    {
        $user = $this->getUser();
        if (!$user || !$user->getInstituto()) {
            $this->addFlash('danger', 'No se encontró el instituto asociado a tu usuario.');
            return $this->redirectToRoute('app_login');
        }

        // El alumno debe pertenecer al instituto del usuario.
        if ($alumno->getInstituto() !== $user->getInstituto()) {
            throw $this->createAccessDeniedException('No tiene acceso a este alumno.');
        }

        $historicos = $historicoRepository->createQueryBuilder('h')
            ->leftJoin('h.curso', 'c')
            ->addSelect('c')
            ->where('h.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->orderBy('h.fechaInicio', 'DESC')
            ->getQuery()
            ->getResult();

        // Se pasan filas ya armadas (mismo formato que el índice) porque el template
        // accedía a propiedades inexistentes de la entidad. Y el alumno explícito, para
        // no depender de historicos[0] cuando no hay históricos.
        $filas = [];
        foreach ($historicos as $historico) {
            $filas[] = self::filaHistorico($historico, $this->deudaCalculator);
        }

        return $this->render('pagos_historicos/alumno.html.twig', [
            'alumno' => $alumno,
            'historicos' => $filas,
        ]);
    }
}
