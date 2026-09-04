<?php

namespace App\Service;

use App\Entity\BillingInvoice;
use App\Entity\Instituto;
use App\Repository\BillingConfigRepository;
use App\Repository\BillingInvoiceRepository;

/**
 * En qué situación está la suscripción de un instituto.
 *
 * Es el único lugar donde se decide si un instituto está al día, si hay que avisarle o si hay que
 * limitarlo, y de acá lo leen el banner, el bloqueo y las dos pantallas. Si el cálculo estuviera
 * repartido, el banner podría decir una cosa y el bloqueo hacer otra.
 *
 * La escalera es: emitida → por vencer → vencida → solo lectura → bloqueada, con los días de cada
 * paso configurables por el super admin.
 */
class SuscripcionService
{
    public const AL_DIA = 'al_dia';
    public const EXENTA = 'exenta';
    public const POR_VENCER = 'por_vencer';
    public const EN_REVISION = 'en_revision';
    public const VENCIDA = 'vencida';
    public const SOLO_LECTURA = 'solo_lectura';
    public const BLOQUEADA = 'bloqueada';

    public function __construct(
        private BillingInvoiceRepository $facturaRepository,
        private BillingConfigRepository $configRepository,
        private BillingService $billingService,
        private InstitutoTimezoneService $institutoTimezoneService
    ) {
    }

    /**
     * El precio por alumno que le corresponde: el propio si tiene, y si no el global.
     */
    public function precioPorAlumno(Instituto $instituto): float
    {
        return $instituto->getPrecioPorAlumno() ?? $this->billingService->getPricePerStudentMonthly();
    }

    /**
     * Cuánto pagaría hoy: los alumnos activos por su precio, con el mínimo mensual si lo tiene.
     */
    public function montoEstimado(Instituto $instituto): float
    {
        $alumnos = $this->billingService->getActiveStudentsCount($instituto);
        $monto = $alumnos * $this->precioPorAlumno($instituto);
        $minimo = $instituto->getMinimoMensual();

        return $minimo !== null ? max($monto, $minimo) : $monto;
    }

    /**
     * La situación completa de la suscripción.
     *
     * @return array{
     *     estado: string,
     *     factura: ?BillingInvoice,
     *     impagas: BillingInvoice[],
     *     total: float,
     *     dias: int,
     *     atraso: int,
     *     escribe: bool,
     *     bloqueado: bool,
     *     avisar: bool
     * }
     */
    public function estado(Instituto $instituto): array
    {
        $config = $this->configRepository->getOrCreatePriceConfig();
        $hoy = $this->institutoTimezoneService->getNowForInstituto($instituto);

        $base = [
            'estado' => self::AL_DIA,
            'factura' => null,
            'impagas' => [],
            'total' => 0.0,
            'dias' => 0,
            'atraso' => 0,
            'escribe' => true,
            'bloqueado' => false,
            'avisar' => false,
            'limiteSoloLectura' => null,
            'limiteBloqueo' => null,
        ];

        if ($instituto->isSuscripcionExenta()) {
            return array_merge($base, ['estado' => self::EXENTA]);
        }

        // Impagas: lo pendiente y lo rechazado. Lo que está esperando nuestra revisión se cuenta
        // aparte, porque el instituto ya hizo su parte.
        $impagas = [];
        $enRevision = [];
        foreach ($this->facturaRepository->findByInstitutoOrderedByPeriod($instituto) as $factura) {
            if ($factura->isPending() || $factura->isRejected()) {
                $impagas[] = $factura;
            } elseif ($factura->isPendingApproval()) {
                $enRevision[] = $factura;
            }
        }

        if (!$impagas) {
            // Si lo único que hay es algo esperando revisión, no se limita nada: la demora es
            // nuestra, no del instituto.
            return array_merge($base, [
                'estado' => $enRevision ? self::EN_REVISION : self::AL_DIA,
                'factura' => $enRevision[0] ?? null,
                'total' => array_sum(array_map(static fn(BillingInvoice $f) => $f->getTotalAmount(), $enRevision)),
            ]);
        }

        // Se ordenan de la más vieja a la más nueva: la que manda es la más atrasada.
        usort($impagas, static function (BillingInvoice $a, BillingInvoice $b) {
            return [$a->getPeriodYear(), $a->getPeriodMonth()] <=> [$b->getPeriodYear(), $b->getPeriodMonth()];
        });

        $laVieja = $impagas[0];
        $total = array_sum(array_map(static fn(BillingInvoice $f) => $f->getTotalAmount(), $impagas));
        $atraso = $laVieja->getDiasDeAtraso($hoy);
        $paraVencer = $laVieja->getDiasParaVencer($hoy);

        $datos = array_merge($base, [
            'factura' => $laVieja,
            'impagas' => $impagas,
            'total' => (float) $total,
            'atraso' => $atraso,
            'dias' => $paraVencer ?? 0,
            // Las dos fechas del próximo paso, para poder decirle "hasta cuándo" con fecha y no
            // con una cantidad de días que tenga que ir a contar.
            'limiteSoloLectura' => $this->fechaDeSoloLectura($laVieja),
            'limiteBloqueo' => $this->fechaDeBloqueo($laVieja),
        ]);

        // Sin fecha de vencimiento no se puede exigir nada: son las facturas viejas, de antes de
        // que existiera el vencimiento. Se muestran como pendientes y nada más.
        if (!$laVieja->getDueDate()) {
            return array_merge($datos, ['estado' => self::AL_DIA]);
        }

        if ($atraso > $config->getDiasHastaBloqueo()) {
            return array_merge($datos, [
                'estado' => self::BLOQUEADA,
                'escribe' => false,
                'bloqueado' => true,
                'avisar' => true,
            ]);
        }

        if ($atraso > $config->getDiasGracia()) {
            return array_merge($datos, [
                'estado' => self::SOLO_LECTURA,
                'escribe' => false,
                'avisar' => true,
            ]);
        }

        if ($atraso > 0) {
            return array_merge($datos, ['estado' => self::VENCIDA, 'avisar' => true]);
        }

        if ($paraVencer !== null && $paraVencer <= $config->getDiasAvisoPrevio()) {
            return array_merge($datos, ['estado' => self::POR_VENCER, 'avisar' => true]);
        }

        return $datos;
    }

    public function puedeEscribir(Instituto $instituto): bool
    {
        return $this->estado($instituto)['escribe'];
    }

    public function estaBloqueado(Instituto $instituto): bool
    {
        return $this->estado($instituto)['bloqueado'];
    }

    /**
     * Cuándo se le bloquearía el panel si no paga, para poder decírselo con fecha.
     */
    public function fechaDeBloqueo(BillingInvoice $factura): ?\DateTimeInterface
    {
        if (!$factura->getDueDate()) {
            return null;
        }

        $config = $this->configRepository->getOrCreatePriceConfig();

        return (new \DateTime($factura->getDueDate()->format('Y-m-d')))
            ->modify('+' . $config->getDiasHastaBloqueo() . ' days');
    }

    /**
     * Cuándo pasaría a solo lectura.
     */
    public function fechaDeSoloLectura(BillingInvoice $factura): ?\DateTimeInterface
    {
        if (!$factura->getDueDate()) {
            return null;
        }

        $config = $this->configRepository->getOrCreatePriceConfig();

        return (new \DateTime($factura->getDueDate()->format('Y-m-d')))
            ->modify('+' . $config->getDiasGracia() . ' days');
    }
}
