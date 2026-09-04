<?php

namespace App\Controller;

use App\Entity\BillingInvoice;
use App\Repository\BillingInvoiceRepository;
use App\Service\BillingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * @Route("/instituto/facturas")
 */
class BillingInvoiceController extends AbstractController
{
    private BillingService $billingService;
    private SluggerInterface $slugger;

    public function __construct(
        BillingService $billingService,
        SluggerInterface $slugger,
        private \App\Service\SuscripcionService $suscripcionService,
        private \App\Repository\BillingConfigRepository $billingConfigRepository
    ) {
        $this->billingService = $billingService;
        $this->slugger = $slugger;
    }

    /**
     * @Route("/", name="billing_invoice_index", methods={"GET"})
     */
    public function index(): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if (!$instituto) {
            $this->addFlash('danger', 'No tienes un instituto asociado.');
            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render('billing_invoice/index.html.twig', [
            'instituto' => $instituto,
            // El estado manda: de él salen el encabezado, el aviso y qué se puede hacer.
            'suscripcion' => $this->suscripcionService->estado($instituto),
            'historial' => $this->billingService->getBillingHistory($instituto, 24),
            'alumnosActivos' => $this->billingService->getActiveStudentsCount($instituto),
            'precioPorAlumno' => $this->suscripcionService->precioPorAlumno($instituto),
            'proximoMes' => $this->suscripcionService->montoEstimado($instituto),
            'config' => $this->billingConfigRepository->getOrCreatePriceConfig(),
        ]);
    }

    /**
     * @Route("/{id}", name="billing_invoice_show", methods={"GET"})
     */
    public function show(BillingInvoice $billingInvoice): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para ver esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        return $this->render('billing_invoice/show.html.twig', [
            'invoice' => $billingInvoice,
            'instituto' => $instituto,
        ]);
    }

    /**
     * @Route("/{id}/pdf", name="billing_invoice_pdf", methods={"GET"})
     */
    public function pdf(BillingInvoice $billingInvoice): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para ver esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        $html = $this->renderView('billing_invoice/pdf.html.twig', [
            'invoice' => $billingInvoice,
            'instituto' => $instituto,
        ]);

        $response = new Response($html);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="factura-' . $billingInvoice->getId() . '.pdf"');

        return $response;
    }

    /**
     * @Route("/{id}/pay", name="billing_invoice_pay", methods={"POST"})
     */
    public function pay(Request $request, BillingInvoice $billingInvoice, EntityManagerInterface $entityManager): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para pagar esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        // Verificar que la factura está pendiente o rechazada (para permitir reenvío)
        if (!$billingInvoice->isPending() && !$billingInvoice->isRejected()) {
            $this->addFlash('warning', 'Esta factura ya ha sido procesada.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Verificar CSRF token
        if (!$this->isCsrfTokenValid('pay_invoice_' . $billingInvoice->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Con qué dice que pagó. Determina si el comprobante es obligatorio.
        $metodo = (string) $request->request->get('payment_method', 'transferencia');
        if (!in_array($metodo, ['transferencia', 'efectivo'], true)) {
            $metodo = 'transferencia';
        }

        $proofFile = $request->files->get('payment_proof');

        // En transferencia el comprobante es obligatorio; en efectivo no siempre hay uno, así
        // que se acepta la declaración y la confirmamos nosotros.
        if (!$proofFile && $metodo === 'transferencia') {
            $this->addFlash('danger', 'Para una transferencia necesitamos el comprobante.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        // Validar el archivo, si vino uno.
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        if ($proofFile && !in_array($proofFile->getMimeType(), $allowedMimes)) {
            $this->addFlash('danger', 'El archivo debe ser una imagen (JPG, PNG) o PDF.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Validar tamaño (máximo 5MB)
        if ($proofFile && $proofFile->getSize() > 5 * 1024 * 1024) {
            $this->addFlash('danger', 'El archivo es demasiado grande. Máximo 5MB.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        try {
            if ($proofFile) {
                // Nombre generado por el servidor: el original lo controla quien sube el archivo.
                $originalFilename = pathinfo($proofFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $this->slugger->slug($originalFilename);
                $newFilename = 'invoice-' . $billingInvoice->getId() . '-' . $safeFilename . '-' . uniqid() . '.' . $proofFile->guessExtension();

                $proofsDirectory = $this->getParameter('payment_proofs_directory');
                if (!is_dir($proofsDirectory)) {
                    mkdir($proofsDirectory, 0755, true);
                }

                $proofFile->move($proofsDirectory, $newFilename);
                $billingInvoice->setPaymentProofPath($newFilename);
            }

            $billingInvoice->setPaymentMethod($metodo);

            // El comentario del instituto se agrega a las notas sin borrar lo que ya hubiera.
            $comentario = trim((string) $request->request->get('notas'));
            if ($comentario !== '') {
                $billingInvoice->setNotes(trim(($billingInvoice->getNotes() ?? '') . "\n" . sprintf(
                    '[%s] %s: %s',
                    (new \DateTime())->format('d/m/Y'),
                    $metodo,
                    $comentario
                )));
            }
            $billingInvoice->setPaymentRequestedAt(new \DateTime());
            $billingInvoice->setStatus('pending_approval');
            // Limpiar datos de rechazo si existían
            $billingInvoice->setRejectionReason(null);
            $billingInvoice->setRejectedAt(null);
            $billingInvoice->setUpdatedAt(new \DateTime());

            $entityManager->flush();

            $this->addFlash('success', 'Comprobante de pago subido exitosamente. La factura está pendiente de aprobación por el administrador.');
            
        } catch (FileException $e) {
            $this->addFlash('danger', 'Error al subir el comprobante. Por favor, intenta nuevamente.');
        }
        
        return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
    }
}
