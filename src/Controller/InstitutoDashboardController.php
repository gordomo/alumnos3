<?php

namespace App\Controller;

use App\Repository\InstitutoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/instituto")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class InstitutoDashboardController extends AbstractController
{
    /**
     * @Route("/", name="app_instituto_index")
     */
    public function index(InstitutoRepository $institutoRepository): Response
    {
        $user = $this->getUser();
        $institutos = [];

        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            // Si es admin, muestra todos los institutos
            $institutos = $institutoRepository->findAll();
        } else {
            // Si es admin_instituto, muestra solo su instituto
            $institutos = $institutoRepository->findBy(['id' => $user->getInstituto()->getId()]);
        }

        return $this->render('instituto_dashboard/index.html.twig', [
            'institutos' => $institutos,
        ]);
    }
} 