<?php

namespace App\Controller;

use App\Service\AyudaBusqueda;
use App\Service\AyudaCatalogo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/ayuda")
 * Todas las rutas requieren sesión por access_control. El catálogo compartido
 * decide qué contenido puede leer el usuario; no se aceptan roles desde el cliente.
 */
class AyudaController extends AbstractController
{
    public function __construct(private AyudaCatalogo $catalogo, private AyudaBusqueda $busqueda, private \App\Service\AyudaIaAcceso $iaAcceso)
    {
    }

    /** @Route("", name="app_ayuda_index", methods={"GET"}) */
    public function index(Request $request): Response
    {
        $consulta = $this->consulta($request);

        return $this->sinCache($this->render('ayuda/index.html.twig', [
            'temas' => $this->catalogo->temasVisibles(),
            'ia' => $this->iaAcceso->estado($this->getUser()->getInstituto()),
            'consulta' => $consulta,
            'resultados' => $consulta !== '' ? $this->busqueda->buscar($consulta) : [],
        ]));
    }

    /** @Route("/buscar", name="app_ayuda_buscar", methods={"GET"}) */
    public function buscar(Request $request): Response
    {
        $consulta = $this->consulta($request);

        return $this->sinCache($this->json([
            'consulta' => $consulta,
            'resultados' => $this->busqueda->buscar($consulta),
        ]));
    }

    /** @Route("/resultados", name="app_ayuda_resultados", methods={"GET"}) */
    public function resultados(Request $request): Response
    {
        $consulta = $this->consulta($request);
        $response = $this->sinCache($this->render('ayuda/_resultados.html.twig', [
            'consulta' => $consulta,
            'resultados' => $this->busqueda->buscar($consulta),
        ]));
        $response->headers->set('X-Ayuda-Fragment', '1');

        return $response;
    }

    /**
     * @Route("/{tema}", name="app_ayuda_tema", methods={"GET"}, requirements={"tema"="[a-z0-9\-]+"})
     */
    public function tema(string $tema): Response
    {
        $visibles = $this->catalogo->temasVisibles();
        if (!isset($visibles[$tema])) {
            throw $this->createNotFoundException('El tema de ayuda no existe.');
        }

        return $this->sinCache($this->render('ayuda/temas/' . $tema . '.html.twig', [
            'tema' => $visibles[$tema] + ['slug' => $tema],
            'temas' => $visibles,
        ]));
    }

    private function consulta(Request $request): string
    {
        $valor = $request->query->all()['q'] ?? '';
        if (!is_string($valor)) {
            throw new BadRequestHttpException('La búsqueda debe ser un texto.');
        }
        $consulta = trim($valor);
        if (mb_strlen($consulta) > 300) {
            throw new BadRequestHttpException('La búsqueda admite hasta 300 caracteres.');
        }

        return $consulta;
    }

    private function sinCache(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
