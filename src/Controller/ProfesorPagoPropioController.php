<?php

namespace App\Controller;

use App\Entity\ProfesorPago;
use App\Service\InstitutoTimezoneService;
use App\Service\ProfesorPagoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lo que el profesor cobra, visto por él mismo.
 *
 * Existía toda la liquidación del lado del administrador —el cálculo, las reglas por curso, los
 * recibos— y el profesor no tenía ninguna pantalla para ver cuánto le corresponde ni qué le
 * pagaron. Esto es de solo lectura: usa el mismo servicio que la pantalla del administrador, así
 * que los dos ven el mismo número.
 *
 * El prefijo /profesor ya exige ROLE_PROFESOR en access_control.
 *
 * @Route("/profesor/pagos")
 */
#[IsGranted('ROLE_PROFESOR')]
class ProfesorPagoPropioController extends AbstractController
{
    public function __construct(
        private ProfesorPagoService $profesorPagoService,
        private InstitutoTimezoneService $institutoTimezoneService
    ) {
    }

    /**
     * @Route("", name="app_profesor_pagos_propios", methods={"GET"})
     */
    public function index(Request $request): Response
    {
        $profesor = $this->getUser()->getProfesor();
        if (!$profesor) {
            $this->addFlash('danger', 'Tu usuario no tiene una ficha de profesor asociada.');

            return $this->redirectToRoute('app_profesor_dashboard');
        }

        $instituto = $profesor->getInstituto();
        $hoy = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $mes = $request->query->getInt('mes', (int) $hoy->format('n'));
        $ano = $request->query->getInt('ano', (int) $hoy->format('Y'));

        if ($mes < 1 || $mes > 12) {
            $mes = (int) $hoy->format('n');
        }

        return $this->render('profesor_pago/propios.html.twig', [
            'profesor' => $profesor,
            'liquidacion' => $this->profesorPagoService->obtenerLiquidacion($profesor, $mes, $ano),
            'mes' => $mes,
            'ano' => $ano,
            'anoActual' => (int) $hoy->format('Y'),
            'app_date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($instituto),
        ]);
    }

    /**
     * El recibo de un pago propio.
     *
     * Reusa el mismo template que el del administrador. Se valida que el pago sea de este
     * profesor: sin eso, cambiando el id se veían los recibos de sus compañeros.
     *
     * @Route("/{id}/recibo", name="app_profesor_pagos_propios_recibo", methods={"GET"})
     */
    public function recibo(ProfesorPago $profesorPago): Response
    {
        $profesor = $this->getUser()->getProfesor();

        if (!$profesor || $profesorPago->getProfesor() !== $profesor) {
            throw $this->createNotFoundException('Pago no encontrado');
        }

        return $this->render('profesor_pago/recibo.html.twig', [
            'pago' => $profesorPago,
            'instituto' => $profesor->getInstituto(),
            'app_date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($profesor->getInstituto()),
        ]);
    }
}
