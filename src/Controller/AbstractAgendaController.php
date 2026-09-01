<?php

namespace App\Controller;

use App\Service\AgendaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo común de la agenda de los tres roles.
 *
 * La pantalla es la misma; lo que cambia es el alcance, y eso lo resuelve AgendaService a partir
 * del usuario logueado. Cada rol tiene su propio controller para colgarse de un prefijo que ya
 * tiene su regla en access_control, así que security.yaml no se toca.
 */
abstract class AbstractAgendaController extends AbstractController
{
    public function __construct(protected AgendaService $agendaService)
    {
    }

    /**
     * Nombre de las rutas de este rol: index y json.
     *
     * @return array{index: string, eventos: string}
     */
    abstract protected function rutas(): array;

    protected function pantalla(): Response
    {
        $user = $this->getUser();

        return $this->render('agenda/index.html.twig', [
            'fuentes' => $this->agendaService->fuentesVisiblesPara($user),
            'fuentesPorDefecto' => $this->agendaService->fuentesPorDefectoPara($user),
            'catalogoFuentes' => AgendaService::FUENTES,
            'rutas' => $this->rutas(),
            'puedeCargarEventos' => $this->isGranted('ROLE_ADMIN_INSTITUTO'),
        ]);
    }

    /**
     * Los eventos del rango que el calendario tiene a la vista.
     *
     * FullCalendar manda start y end en cada cambio de mes o de vista, así que nunca se expande
     * más de lo que se está mirando.
     */
    protected function eventosJson(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rangoPedido($request);

        $fuentes = $request->query->all('fuentes');
        $fuentes = $fuentes ? array_values(array_map('strval', $fuentes)) : null;

        return new JsonResponse(
            $this->agendaService->eventos($this->getUser(), $desde, $hasta, $fuentes)
        );
    }

    /**
     * El rango pedido, con el mes en curso como respaldo si viene vacío o ilegible.
     *
     * @return array{0: \DateTimeInterface, 1: \DateTimeInterface}
     */
    private function rangoPedido(Request $request): array
    {
        $desde = $this->fecha($request->query->get('start'));
        $hasta = $this->fecha($request->query->get('end'));

        if (!$desde || !$hasta || $hasta < $desde) {
            $desde = new \DateTime('first day of this month 00:00:00');
            $hasta = new \DateTime('last day of this month 23:59:59');
        }

        return [$desde, $hasta];
    }

    private function fecha($valor): ?\DateTime
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new \DateTime($valor);
        } catch (\Exception $e) {
            return null;
        }
    }
}
