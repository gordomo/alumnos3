<?php

namespace App\Controller;

use App\Repository\CursoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/profesor")
 */
#[IsGranted('ROLE_PROFESOR')]
class ProfesorDashboardController extends AbstractController
{
    /**
     * @Route("/dashboard", name="app_profesor_dashboard")
     */
    public function index(CursoRepository $cursoRepository): Response
    {
        $profesor = $this->getUser()->getProfesor();
        $cursos = $cursoRepository->findByProfesor($profesor);

        return $this->render('profesor_dashboard/index.html.twig', [
            'cursos' => $cursos,
            'profesor' => $profesor,
        ]);
    }
} 