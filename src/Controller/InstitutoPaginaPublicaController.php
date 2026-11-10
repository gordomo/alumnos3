<?php
namespace App\Controller;

use App\Form\InstitutoPaginaPublicaType;
use App\Repository\InstitutoPaginaPublicaRepository;
use App\Service\{InstitutoPaginaPublicaService, InstitutoLandingPresenter};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Annotation\Route;

class InstitutoPaginaPublicaController extends AbstractController
{
    /** @Route("/instituto/config/pagina-publica", name="instituto_pagina_publica_config", methods={"GET", "POST"}) */
    public function configurar(Request $request, InstitutoPaginaPublicaService $paginas, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_INSTITUTO');
        $instituto = $this->getUser()->getInstituto();
        if (!$instituto) { throw $this->createAccessDeniedException(); }
        $pagina = $paginas->obtenerOCrear($instituto);
        $form = $this->createForm(InstitutoPaginaPublicaType::class, $pagina);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Guardamos la configuración de tu página pública.');
            return $this->redirectToRoute('instituto_pagina_publica_config');
        }
        return $this->render('instituto_pagina_publica/config.html.twig', ['form' => $form->createView(), 'pagina' => $pagina, 'urlPublica' => $paginas->url($pagina)]);
    }

    /** @Route("/instituto/config/pagina-publica/vista-previa", name="instituto_pagina_publica_preview", methods={"GET"}) */
    public function preview(InstitutoPaginaPublicaService $paginas, InstitutoLandingPresenter $presenter): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN_INSTITUTO');
        $instituto = $this->getUser()->getInstituto();
        if (!$instituto) { throw $this->createAccessDeniedException(); }
        $pagina = $paginas->obtenerOCrear($instituto);
        $response = $this->render('instituto_pagina_publica/landing.html.twig', ['sitio' => $presenter->datos($pagina), 'preview' => true, 'urlPublica' => $paginas->url($pagina)]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }

    /** @Route("/{slug}", name="app_instituto_landing_publica", requirements={"slug"="[a-z0-9]+(?:-[a-z0-9]+)*"}, methods={"GET"}, priority=-100) */
    public function publica(string $slug, InstitutoPaginaPublicaRepository $repository, InstitutoPaginaPublicaService $paginas, InstitutoLandingPresenter $presenter): Response
    {
        $pagina = $repository->findPublicada($slug);
        if (!$pagina) { throw $this->createNotFoundException('La página no está disponible.'); }
        $response = $this->render('instituto_pagina_publica/landing.html.twig', ['sitio' => $presenter->datos($pagina), 'preview' => false, 'urlPublica' => $paginas->url($pagina)]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
