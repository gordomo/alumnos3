<?php

namespace App\Controller;

use App\Entity\Instituto;
use App\Service\TokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;

#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class TokenController extends AbstractController
{
    private TokenService $tokenService;
    private EntityManagerInterface $entityManager;

    public function __construct(TokenService $tokenService, EntityManagerInterface $entityManager)
    {
        $this->tokenService = $tokenService;
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/instituto/tokens", name="app_tokens_index", methods={"GET"})
     */
    public function index(): Response
    {
        $user = $this->getUser();
        $instituto = $user->getInstituto();

        if (!$instituto) {
            throw $this->createAccessDeniedException('No tienes un instituto asociado');
        }

        $balance = $this->tokenService->getBalance($instituto);
        
        // Estadísticas del mes actual
        $now = new \DateTime();
        $startOfMonth = new \DateTime($now->format('Y-m-01'));
        $endOfMonth = clone $now;
        $endOfMonth->modify('last day of this month')->setTime(23, 59, 59);

        // Estadísticas de la semana actual
        $startOfWeek = clone $now;
        $startOfWeek->modify('monday this week')->setTime(0, 0, 0);
        $endOfWeek = clone $now;
        $endOfWeek->modify('sunday this week')->setTime(23, 59, 59);

        $monthlyConsumption = $this->tokenService->getTotalConsumptionByPeriod($instituto, $startOfMonth, $endOfMonth);
        $weeklyConsumption = $this->tokenService->getTotalConsumptionByPeriod($instituto, $startOfWeek, $endOfWeek);
        
        $monthlyConsumptionByAction = $this->tokenService->getConsumptionByPeriod($instituto, $startOfMonth, $endOfMonth);
        $weeklyConsumptionByAction = $this->tokenService->getConsumptionByPeriod($instituto, $startOfWeek, $endOfWeek);

        $recentTransactions = $this->tokenService->getRecentTransactions($instituto, 20);
        $allActions = $this->tokenService->getAllActions();

        return $this->render('tokens/index.html.twig', [
            'balance' => $balance,
            'monthlyConsumption' => $monthlyConsumption,
            'weeklyConsumption' => $weeklyConsumption,
            'monthlyConsumptionByAction' => $monthlyConsumptionByAction,
            'weeklyConsumptionByAction' => $weeklyConsumptionByAction,
            'recentTransactions' => $recentTransactions,
            'allActions' => $allActions,
        ]);
    }
}

