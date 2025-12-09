<?php

namespace App\Controller;

use App\Entity\Instituto;
use App\Service\TokenService;
use App\Repository\InstitutoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;

#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminTokenController extends AbstractController
{
    private TokenService $tokenService;
    private InstitutoRepository $institutoRepository;
    private EntityManagerInterface $entityManager;

    public function __construct(
        TokenService $tokenService,
        InstitutoRepository $institutoRepository,
        EntityManagerInterface $entityManager
    ) {
        $this->tokenService = $tokenService;
        $this->institutoRepository = $institutoRepository;
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/admin/tokens", name="admin_tokens_index", methods={"GET"})
     */
    public function index(): Response
    {
        $institutos = $this->institutoRepository->findAll();
        
        $institutosWithBalance = [];
        foreach ($institutos as $instituto) {
            $balance = $this->tokenService->getBalance($instituto);
            $institutosWithBalance[] = [
                'instituto' => $instituto,
                'balance' => $balance,
            ];
        }

        return $this->render('admin/tokens/index.html.twig', [
            'institutos' => $institutosWithBalance,
        ]);
    }

    /**
     * @Route("/admin/tokens/{id}/add", name="admin_tokens_add", methods={"GET", "POST"})
     */
    public function addTokens(Request $request, Instituto $instituto): Response
    {
        if ($request->isMethod('POST')) {
            $amount = (int) $request->request->get('amount');
            $description = $request->request->get('description', 'Tokens agregados manualmente por administrador');

            if ($amount <= 0) {
                $this->addFlash('error', 'La cantidad debe ser mayor a 0');
            } else {
                try {
                    $this->tokenService->addTokens($instituto, $amount, $this->getUser(), $description);
                    $this->addFlash('success', sprintf('Se agregaron %d tokens al instituto %s', $amount, $instituto->getNombre()));
                    return $this->redirectToRoute('admin_tokens_index');
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Error al agregar tokens: ' . $e->getMessage());
                }
            }
        }

        $balance = $this->tokenService->getBalance($instituto);
        $recentTransactions = $this->tokenService->getRecentTransactions($instituto, 10);

        return $this->render('admin/tokens/add.html.twig', [
            'instituto' => $instituto,
            'balance' => $balance,
            'recentTransactions' => $recentTransactions,
        ]);
    }
}

