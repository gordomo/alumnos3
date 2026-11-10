<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AyudaIa;
use App\Service\AyudaIaAcceso;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AyudaChatController extends AbstractController
{
    /** @Route("/ayuda/chat", name="app_ayuda_chat", methods={"POST"}) */
    public function responder(Request $request, AyudaIaAcceso $acceso, AyudaIa $ia): Response
    {
        $usuario = $this->getUser();
        $instituto = $usuario instanceof User ? $usuario->getInstituto() : null;
        if (!$acceso->estado($instituto)['disponible']) {
            return $this->respuesta(['estado' => 'sin_acceso', 'respuesta' => 'La ayuda con IA no está habilitada para tu cuenta.', 'fuentes' => []], 403);
        }
        if (!$this->isCsrfTokenValid('ayuda_chat', $request->headers->get('X-CSRF-Token'))) {
            return $this->respuesta(['error' => 'La sesión venció. Actualizá la página.'], 403);
        }
        if (strlen($request->getContent()) > 4096) {
            return $this->respuesta(['error' => 'La consulta es demasiado larga.'], 413);
        }
        try { $datos = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { return $this->respuesta(['error' => 'Consulta inválida.'], 400); }
        $pregunta = is_array($datos) ? ($datos['pregunta'] ?? null) : null;
        if (!is_string($pregunta) || trim($pregunta) === '' || mb_strlen($pregunta) > 300 || preg_match('//u', $pregunta) !== 1) {
            return $this->respuesta(['error' => 'Escribí una pregunta de hasta 300 caracteres.'], 400);
        }
        if (($datos['aceptaPrueba'] ?? false) !== true) {
            return $this->respuesta(['error' => 'Aceptá las condiciones de la prueba antes de consultar.'], 400);
        }
        // Liberar el bloqueo de sesión antes de esperar a la API. No se guarda historial.
        if ($request->hasSession()) { $request->getSession()->save(); }
        $resultado = $ia->responder(trim($pregunta), $instituto);
        $estadoHttp = ['limite' => 429, 'no_disponible' => 503][$resultado['estado']] ?? 200;
        $respuesta = $this->respuesta($resultado, $estadoHttp);
        if (isset($resultado['reintentarEn'])) { $respuesta->headers->set('Retry-After', (string) $resultado['reintentarEn']); }
        return $respuesta;
    }

    private function respuesta(array $datos, int $estado): Response
    {
        $respuesta = $this->json($datos, $estado);
        $respuesta->headers->set('Cache-Control', 'private, no-store');
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        return $respuesta;
    }
}
