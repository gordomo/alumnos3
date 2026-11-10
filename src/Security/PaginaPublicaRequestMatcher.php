<?php
namespace App\Security;

use Symfony\Component\HttpFoundation\{Request, RequestMatcherInterface};

/** Solo la ruta pública resuelta por el router. Un parámetro de consulta no habilita acceso. */
class PaginaPublicaRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return in_array($request->attributes->get('_route'), ['app_instituto_landing_publica', 'app_instituto_landing_publica_solicitud'], true);
    }
}
