<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Agenda del alumn@: sus clases, sus evaluaciones, sus entregas y el vencimiento de las cuotas
 * que todavía debe. El alcance lo resuelve AgendaService.
 *
 * @Route("/alumno/agenda")
 */
#[IsGranted('ROLE_ALUMNO')]
class AgendaAlumnoController extends AbstractAgendaController
{
    protected function rutas(): array
    {
        return ['index' => 'app_agenda_alumno_index', 'eventos' => 'app_agenda_alumno_eventos'];
    }

    /**
     * @Route("", name="app_agenda_alumno_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->pantalla();
    }

    /**
     * @Route("/eventos", name="app_agenda_alumno_eventos", methods={"GET"})
     */
    public function eventos(Request $request): JsonResponse
    {
        return $this->eventosJson($request);
    }
}
