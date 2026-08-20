<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Agenda del profesor: las clases de sus cursos, sus evaluaciones, las tareas que pidió y los
 * eventos del instituto que le corresponden. El alcance lo resuelve AgendaService.
 *
 * @Route("/profesor/agenda")
 */
#[IsGranted('ROLE_PROFESOR')]
class AgendaProfesorController extends AbstractAgendaController
{
    protected function rutas(): array
    {
        return ['index' => 'app_agenda_profesor_index', 'eventos' => 'app_agenda_profesor_eventos'];
    }

    /**
     * @Route("", name="app_agenda_profesor_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->pantalla();
    }

    /**
     * @Route("/eventos", name="app_agenda_profesor_eventos", methods={"GET"})
     */
    public function eventos(Request $request): JsonResponse
    {
        return $this->eventosJson($request);
    }
}
