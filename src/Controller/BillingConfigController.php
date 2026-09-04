<?php

namespace App\Controller;

use App\Repository\BillingConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Configuración de la suscripción que pagan los institutos.
 *
 * Antes esto era un CRUD de filas de configuración, pero en la práctica hay una sola: el CRUD
 * dejaba crear duplicados que después nadie sabía cuál se usaba. Ahora es una única pantalla de
 * ajustes sobre la fila que devuelve getOrCreatePriceConfig().
 *
 * @Route("/admin/billing-config")
 */
class BillingConfigController extends AbstractController
{
    /**
     * @Route("/", name="admin_billing_config_index", methods={"GET", "POST"})
     */
    public function index(
        Request $request,
        BillingConfigRepository $billingConfigRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            $this->addFlash('danger', 'No tienes permisos para acceder a esta sección.');

            return $this->redirectToRoute('admin_instituto_index');
        }

        $config = $billingConfigRepository->getOrCreatePriceConfig();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('billing_config', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad inválido. Volvé a intentar.');

                return $this->redirectToRoute('admin_billing_config_index');
            }

            $precio = (float) str_replace(',', '.', (string) $request->request->get('price_per_student_monthly'));
            if ($precio <= 0) {
                $this->addFlash('danger', 'El precio por alumn@ tiene que ser mayor a 0.');

                return $this->redirectToRoute('admin_billing_config_index');
            }

            $config->setPricePerStudentMonthly($precio);
            $config->setDescription(trim((string) $request->request->get('description')) ?: null);

            // Los setters de la entidad ya recortan estos valores a rangos sensatos, así que acá
            // solo hace falta pasarlos como enteros.
            $config->setDiaVencimiento((int) $request->request->get('dia_vencimiento'));
            $config->setDiasAvisoPrevio((int) $request->request->get('dias_aviso_previo'));
            $config->setDiasGracia((int) $request->request->get('dias_gracia'));
            $config->setDiasHastaBloqueo((int) $request->request->get('dias_hasta_bloqueo'));

            $config->setDatosTransferencia(trim((string) $request->request->get('datos_transferencia')) ?: null);
            $config->setMpAccessToken(trim((string) $request->request->get('mp_access_token')) ?: null);
            $config->setMpPublicKey(trim((string) $request->request->get('mp_public_key')) ?: null);

            $entityManager->flush();

            $this->addFlash('success', 'Configuración de la suscripción actualizada.');

            return $this->redirectToRoute('admin_billing_config_index');
        }

        return $this->render('admin/billing_config/index.html.twig', [
            'config' => $config,
        ]);
    }
}
