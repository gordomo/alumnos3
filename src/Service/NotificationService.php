<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\DeudaAlumno;
use App\Entity\EmailLog;
use App\Entity\InstitutoConfiguracion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Service\TokenService;
use App\Service\InstitutoTimezoneService;

class NotificationService
{
    private MailerInterface $mailer;
    private EntityManagerInterface $entityManager;
    private UrlGeneratorInterface $urlGenerator;
    private TokenService $tokenService;
    private InstitutoTimezoneService $institutoTimezoneService;
    private string $baseUrl;
    private $deudaCalculator;

    public function __construct(
        MailerInterface $mailer,
        EntityManagerInterface $entityManager,
        UrlGeneratorInterface $urlGenerator,
        TokenService $tokenService,
        InstitutoTimezoneService $institutoTimezoneService,
        DeudaCalculatorService $deudaCalculator
    ) {
        $this->mailer = $mailer;
        $this->entityManager = $entityManager;
        $this->urlGenerator = $urlGenerator;
        $this->tokenService = $tokenService;
        $this->institutoTimezoneService = $institutoTimezoneService;
        $this->deudaCalculator = $deudaCalculator;
        
        // Obtener URL base
        try {
            $this->baseUrl = rtrim($this->urlGenerator->generate('app_alumnos_pagos_index', [], UrlGeneratorInterface::ABSOLUTE_URL), '/');
            // Remover la ruta específica para obtener solo el dominio
            $this->baseUrl = preg_replace('/\/alumnos\/pagos.*$/', '', $this->baseUrl);
        } catch (\Exception $e) {
            $this->baseUrl = 'http://localhost:8080';
        }
    }

    /**
     * Envía un email con el recibo/factura de un pago
     */
    public function enviarReciboPago(AlumnosPagos $pago, ?string $emailDestino = null, bool $forzarEnvio = false, ?User $solicitadoPor = null): bool
    {
        $instituto = $pago->getAlumno()->getInstituto();
        $configuracion = $instituto->getConfiguracion();
        
        // Verificar si está habilitado el envío de facturas/recibos (a menos que se fuerce)
        if (!$forzarEnvio && (!$configuracion || !$configuracion->getEnviarFacturasRecibos())) {
            $this->registrarEmailLog($instituto, $pago->getAlumno(), 'recibo', $emailDestino ?? $pago->getAlumno()->getEmail(), 
                'Recibo de Pago - ' . $instituto->getNombre(), 'fallido', 
                'Envío de recibos deshabilitado en configuración', $pago, null, $solicitadoPor, false);
            return false;
        }
        
        $alumno = $pago->getAlumno();
        $destinatarios = $this->resolverDestinatarios($alumno, $emailDestino, $configuracion);
        $emailAlumno = implode(', ', $destinatarios);

        if (!$destinatarios) {
            $this->registrarEmailLog($instituto, $alumno, 'recibo', 'Sin email',
                'Recibo de Pago - ' . $instituto->getNombre(), 'fallido',
                'No hay ninguna dirección válida para notificar según la configuración del instituto', $pago, null, $solicitadoPor, false);
            return false;
        }
        
        // Usar email del instituto si está configurado y es del dominio correcto
        // Si no, usar un email por defecto del dominio del servidor
        $institutoEmail = $instituto->getEmail();
        $fromEmail = 'noreply@teambuilder.com.ar'; // Email por defecto del dominio del servidor
        
        // Si el email del instituto está configurado y es válido, usarlo
        if ($institutoEmail && filter_var($institutoEmail, FILTER_VALIDATE_EMAIL)) {
            // Verificar si el email es del dominio del servidor o un dominio válido
            $emailDomain = substr(strrchr($institutoEmail, "@"), 1);
            if (in_array($emailDomain, ['teambuilder.com.ar', 'dattaweb.com'])) {
                $fromEmail = $institutoEmail;
            }
        }
        
        $fromName = $instituto->getNombre() ?? 'Instituto';
        $asunto = 'Recibo de Pago - ' . $instituto->getNombre();
        
        try {
            $logoUrl = $this->getLogoUrl($instituto);
            
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $fromName))
                ->to(...$destinatarios)
                ->subject($asunto)
                ->htmlTemplate('emails/recibo_pago.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'pago' => $pago,
                    'alumno' => $alumno,
                    'logoUrl' => $logoUrl,
                    'textoPersonalizado' => $configuracion ? $configuracion->getTextoPersonalizadoEmail() : null,
                ]);
            
            $this->mailer->send($email);
            
            // Registrar email enviado exitosamente
            $this->registrarEmailLog($instituto, $alumno, 'recibo', $emailAlumno, $asunto, 
                'enviado', null, $pago, null, $solicitadoPor, $solicitadoPor === null);
            
            // Consumir tokens por enviar notificación
            $this->tokenService->consumeTokens(
                $instituto,
                'notificacion.send',
                null,
                'Enviar recibo de pago a ' . $alumno->getNombreApellido(),
                'AlumnosPagos',
                $pago->getId()
            );
            
            return true;
        } catch (\Exception $e) {
            // Registrar email fallido
            $this->registrarEmailLog($instituto, $alumno, 'recibo', $emailAlumno, $asunto, 
                'fallido', $e->getMessage(), $pago, null, $solicitadoPor, $solicitadoPor === null);
            
            // Log error si es necesario
            throw new \RuntimeException(
                sprintf(
                    'Error al enviar email de recibo: %s (From: %s, To: %s)',
                    $e->getMessage(),
                    $fromEmail,
                    $emailAlumno
                ),
                0,
                $e
            );
        }
    }

    /**
     * Envía el boletín de notas de un alumno en un curso.
     *
     * Va por defecto al email del tutor, con fallback al del alumno. Sigue el mismo patrón
     * que enviarReciboPago(): registra el envío en EmailLog con tipo 'boletin', tanto si
     * sale bien como si falla, y no interrumpe el flujo si el registro del log falla.
     *
     * @param array $resumen resultado de PromedioCalificacionService
     * @param array $calificaciones las notas a listar
     */
    public function enviarBoletinNotas(
        AlumnoCursoHistorico $historico,
        array $calificaciones,
        array $resumen,
        ?string $emailDestino = null,
        ?User $solicitadoPor = null
    ): bool {
        $alumno = $historico->getAlumno();
        $curso = $historico->getCurso();
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();

        $asunto = sprintf('Boletín de notas - %s - %s', $curso->getNombre(), $instituto->getNombre());

        // Igual que el recibo y el recordatorio: quién recibe lo decide la configuración del
        // instituto. Antes acá el tutor tapaba al alumno y nunca llegaba a los dos.
        $destinatarios = $this->resolverDestinatarios($alumno, $emailDestino, $configuracion);
        $destino = implode(', ', $destinatarios);

        if (!$destinatarios) {
            $this->registrarEmailLog(
                $instituto, $alumno, 'boletin', 'Sin email', $asunto,
                'fallido', 'No hay ninguna dirección válida para notificar según la configuración del instituto',
                null, null, $solicitadoPor, false
            );

            return false;
        }

        $institutoEmail = $instituto->getEmail();
        $fromEmail = 'noreply@teambuilder.com.ar';
        if ($institutoEmail && filter_var($institutoEmail, FILTER_VALIDATE_EMAIL)) {
            $emailDomain = substr(strrchr($institutoEmail, '@'), 1);
            if (in_array($emailDomain, ['teambuilder.com.ar', 'dattaweb.com'])) {
                $fromEmail = $institutoEmail;
            }
        }

        try {
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $instituto->getNombre() ?? 'Instituto'))
                ->to(...$destinatarios)
                ->subject($asunto)
                ->htmlTemplate('emails/boletin_notas.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'historico' => $historico,
                    'calificaciones' => $calificaciones,
                    'resumen' => $resumen,
                    'logoUrl' => $this->getLogoUrl($instituto),
                    'dateFormat' => $this->institutoTimezoneService->getDateFormatForInstituto($instituto),
                    'textoPersonalizado' => $configuracion ? $configuracion->getTextoPersonalizadoEmail() : null,
                ]);

            $this->mailer->send($email);

            $this->registrarEmailLog(
                $instituto, $alumno, 'boletin', $destino, $asunto,
                'enviado', null, null, null, $solicitadoPor, false
            );

            return true;
        } catch (\Exception $e) {
            $this->registrarEmailLog(
                $instituto, $alumno, 'boletin', $destino, $asunto,
                'fallido', $e->getMessage(), null, null, $solicitadoPor, false
            );

            return false;
        }
    }

    /**
     * Envía un recordatorio de deuda pendiente
     */
    /**
     * @param array|null $resumenVencidas ['cantidad' => int, 'total' => float] cuando el alumno
     *                                    debe varias cuotas y se le manda un solo recordatorio.
     */
    public function enviarRecordatorioDeuda(Alumno $alumno, DeudaAlumno $deuda, ?string $emailDestino = null, bool $forzarEnvio = false, ?User $solicitadoPor = null, ?array $resumenVencidas = null): bool
    {
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();
        
        // Verificar si está habilitado el envío de recordatorios (a menos que se fuerce)
        if (!$forzarEnvio && (!$configuracion || !$configuracion->getEnviarRecordatoriosDeudas())) {
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', $emailDestino ?? $alumno->getEmail(), 
                'Recordatorio de Pago Pendiente - ' . $instituto->getNombre(), 'fallido', 
                'Envío de recordatorios deshabilitado en configuración', null, $deuda, $solicitadoPor, false);
            return false;
        }
        
        $destinatarios = $this->resolverDestinatarios($alumno, $emailDestino, $configuracion);
        $emailAlumno = implode(', ', $destinatarios);

        if (!$destinatarios) {
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', 'Sin email',
                'Recordatorio de Pago Pendiente - ' . $instituto->getNombre(), 'fallido',
                'No hay ninguna dirección válida para notificar según la configuración del instituto', null, $deuda, $solicitadoPor, false);
            return false;
        }
        
        // Usar email del instituto si está configurado y es del dominio correcto
        // Si no, usar un email por defecto del dominio del servidor
        $institutoEmail = $instituto->getEmail();
        $fromEmail = 'noreply@teambuilder.com.ar'; // Email por defecto del dominio del servidor
        
        // Si el email del instituto está configurado y es válido, usarlo
        if ($institutoEmail && filter_var($institutoEmail, FILTER_VALIDATE_EMAIL)) {
            // Verificar si el email es del dominio del servidor o un dominio válido
            $emailDomain = substr(strrchr($institutoEmail, "@"), 1);
            if (in_array($emailDomain, ['teambuilder.com.ar', 'dattaweb.com'])) {
                $fromEmail = $institutoEmail;
            }
        }
        
        $fromName = $instituto->getNombre() ?? 'Instituto';
        $asunto = 'Recordatorio de Pago Pendiente - ' . $instituto->getNombre();
        
        try {
            $logoUrl = $this->getLogoUrl($instituto);
            
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $fromName))
                ->to(...$destinatarios)
                ->subject($asunto)
                ->htmlTemplate('emails/recordatorio_deuda.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'alumno' => $alumno,
                    'deuda' => $deuda,
                    'logoUrl' => $logoUrl,
                    // Cuando el recordatorio sale del proceso automático, el alumno recibe uno
                    // solo aunque deba varias cuotas, así que el mail tiene que decir cuántas.
                    'resumenVencidas' => $resumenVencidas,
                    'textoPersonalizado' => $configuracion ? $configuracion->getTextoPersonalizadoEmail() : null,
                ]);
            
            $this->mailer->send($email);
            
            // Registrar email enviado exitosamente
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', $emailAlumno, $asunto, 
                'enviado', null, null, $deuda, $solicitadoPor, $solicitadoPor === null);
            
            // Consumir tokens por enviar notificación
            $this->tokenService->consumeTokens(
                $instituto,
                'notificacion.send',
                null,
                'Enviar recordatorio de deuda a ' . $alumno->getNombreApellido(),
                'DeudaAlumno',
                $deuda->getId()
            );
            
            return true;
        } catch (\Exception $e) {
            // Registrar email fallido
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', $emailAlumno, $asunto, 
                'fallido', $e->getMessage(), null, $deuda, $solicitadoPor, $solicitadoPor === null);
            
            // Log error si es necesario
            throw $e; // Re-lanzar para que el comando pueda mostrar el error
        }
    }

    /**
     * Procesa y envía los recordatorios de deuda automáticos. Lo corre a diario el comando
     * app:send-notifications.
     *
     * Tres reglas, todas configurables por instituto, y basta que se cumpla una:
     *  - Días del mes fijos (recordatorioDiasMes), para el clásico "avisales después del 20".
     *  - El día del primer vencimiento (enviarRecordatorioEnDiaVencimiento).
     *  - Cada N días desde el último recordatorio de esa cuota (recordatorioCadaDias).
     *
     * Además el alumno tiene que llegar al mínimo de cuotas vencidas configurado, y recibe
     * un solo mail por corrida aunque deba varias: se manda por la cuota más vieja e incluye
     * cuántas debe en total. Antes se mandaba uno por cuota, o sea tres mails a un padre con
     * tres meses atrasados.
     */
    public function procesarRecordatoriosAutomaticos(): int
    {
        $enviados = 0;

        foreach ($this->entityManager->getRepository(Instituto::class)->findAll() as $instituto) {
            $configuracion = $instituto->getConfiguracion();

            if (!$configuracion || !$configuracion->getEnviarRecordatoriosDeudas()) {
                continue;
            }

            // El día se evalúa en la zona horaria del instituto: con institutos en husos
            // distintos, "hoy es 20" no es lo mismo para todos.
            $diaActual = (int) $this->institutoTimezoneService->getNowForInstituto($instituto)->format('d');

            $esDiaDeAviso = in_array($diaActual, $configuracion->getRecordatorioDiasMesArray(), true);

            if (!$esDiaDeAviso && $configuracion->getEnviarRecordatorioEnDiaVencimiento()) {
                $esDiaDeAviso = $diaActual === $this->primerDiaVencimiento($instituto);
            }

            $alumnos = $this->entityManager->getRepository(Alumno::class)->findBy([
                'instituto' => $instituto,
                'activo' => true,
            ]);

            foreach ($this->vencidasPorAlumno($instituto, $alumnos) as $datos) {
                if ($datos['cantidad'] < $configuracion->getRecordatorioMinCuotasVencidas()) {
                    continue;
                }

                $alumnoDeuda = $datos['alumno'];

                // La cuota más vieja, materializada como fila recién ahora: es la que se
                // muestra en el mail y la que ancla la regla de "repetir cada N días".
                $deuda = $this->materializarDeuda($datos['masVieja']);
                if ($deuda === null) {
                    continue;
                }

                $debeEnviar = $esDiaDeAviso;

                if (!$debeEnviar && $configuracion->getRecordatorioCadaDias()) {
                    $debeEnviar = $this->debeEnviarRecordatorio($deuda, $configuracion->getRecordatorioCadaDias());
                }

                if (!$debeEnviar) {
                    continue;
                }

                // Con la regla de dias fijos, dos corridas el mismo dia mandaban dos mails.
                // El "cada N dias" ya se protegia solo, esta regla no.
                if ($this->yaSeAvisoHoy($alumnoDeuda, $instituto)) {
                    continue;
                }

                $resumen = [
                    'cantidad' => $datos['cantidad'],
                    'total' => $datos['total'],
                ];

                // forzarEnvio va en true porque el switch del instituto ya se chequeó arriba.
                // Antes este true se pasaba en la posición de $emailDestino, así que sin
                // strict_types PHP lo convertía en la cadena "1" y todos los recordatorios
                // automáticos se intentaban mandar a esa dirección y fallaban.
                if ($this->enviarRecordatorioDeuda($datos['alumno'], $deuda, null, true, null, $resumen)) {
                    $enviados++;
                }
            }
        }

        return $enviados;
    }

    /**
     * Día del mes del primer vencimiento del instituto, o null si no tiene ninguno cargado.
     */
    private function primerDiaVencimiento(Instituto $instituto): ?int
    {
        $vencimientos = $instituto->getVencimientos()->toArray();
        if (!$vencimientos) {
            return null;
        }

        usort($vencimientos, static function ($a, $b) {
            return $a->getOrden() <=> $b->getOrden();
        });

        return (int) reset($vencimientos)->getDiaVencimiento();
    }

    /**
     * Cuotas vencidas por alumno, tomadas del cálculo on-demand.
     *
     * Antes esto se consultaba sobre la tabla deuda_alumno, y ahí estaba el problema: las
     * deudas mensuales se calculan al vuelo y sólo se materializan como fila cuando se
     * registra un pago. O sea que el alumno que nunca pagó nada no tenía ni una fila y no
     * recibía recordatorio, justo el que más lo necesita. Verificado con un alumno que la
     * pantalla de deudas mostraba con 5 cuotas vencidas y 0 filas en la tabla.
     *
     * Ahora la fuente es la misma que ve la pantalla de deudas, así que el mail no puede
     * decir algo distinto de la app. Se usa el cálculo masivo para no hacer una tanda de
     * queries por alumno.
     *
     * Vencida = de un mes anterior al actual, o del mes actual con el día del primer
     * vencimiento ya cumplido. Los meses ya pagados no vienen en el cálculo.
     *
     * @param Alumno[] $alumnos
     * @return array<int, array{alumno: Alumno, cantidad: int, total: float, masVieja: array}>
     */
    private function vencidasPorAlumno(Instituto $instituto, array $alumnos): array
    {
        if (!$alumnos) {
            return [];
        }

        $hoy = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $mesActual = (int) $hoy->format('n');
        $anoActual = (int) $hoy->format('Y');
        $diaActual = (int) $hoy->format('d');
        $diaVencimiento = $this->primerDiaVencimiento($instituto) ?? 5;

        $deudasPorAlumno = $this->deudaCalculator->calcularDeudasParaAlumnos($alumnos);

        $resultado = [];
        foreach ($alumnos as $alumno) {
            $vencidas = [];

            foreach ($deudasPorAlumno[$alumno->getId()] ?? [] as $deuda) {
                $ano = (int) $deuda['ano'];
                $mes = (int) $deuda['mes'];

                $esMesAnterior = $ano < $anoActual || ($ano === $anoActual && $mes < $mesActual);
                $esMesActualVencido = $ano === $anoActual && $mes === $mesActual && $diaActual >= $diaVencimiento;

                if ($esMesAnterior || $esMesActualVencido) {
                    $vencidas[] = $deuda;
                }
            }

            if (!$vencidas) {
                continue;
            }

            // calcularDeudasAlumno() ya devuelve ordenado por año y mes, pero el orden es
            // parte del contrato de esta función, así que se asegura acá.
            usort($vencidas, static function (array $a, array $b) {
                return [$a['ano'], $a['mes']] <=> [$b['ano'], $b['mes']];
            });

            $total = 0.0;
            foreach ($vencidas as $deuda) {
                $total += (float) $deuda['monto'] + (float) ($deuda['interes'] ?? 0);
            }

            $resultado[$alumno->getId()] = [
                'alumno' => $alumno,
                'cantidad' => count($vencidas),
                'total' => $total,
                'masVieja' => $vencidas[0],
            ];
        }

        return $resultado;
    }

    /**
     * Si ya se le mandó un recordatorio a este alumno hoy, en la fecha civil del instituto.
     */
    private function yaSeAvisoHoy(Alumno $alumno, Instituto $instituto): bool
    {
        $hoy = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $desde = new \DateTime($hoy->format('Y-m-d') . ' 00:00:00');
        $hasta = new \DateTime($hoy->format('Y-m-d') . ' 23:59:59');

        $cantidad = (int) $this->entityManager->getRepository(EmailLog::class)
            ->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.alumno = :alumno')
            ->andWhere('e.tipo = :tipo')
            ->andWhere('e.estado = :estado')
            ->andWhere('e.fechaEnvio BETWEEN :desde AND :hasta')
            ->setParameter('alumno', $alumno)
            ->setParameter('tipo', 'recordatorio')
            ->setParameter('estado', 'enviado')
            ->setParameter('desde', $desde)
            ->setParameter('hasta', $hasta)
            ->getQuery()
            ->getSingleScalarResult();

        return $cantidad > 0;
    }

    /**
     * Devuelve la fila de deuda_alumno de esta cuota calculada, creándola si no existe.
     *
     * Hace falta porque enviarRecordatorioDeuda() y EmailLog trabajan con la entidad, no con
     * el array del cálculo. Es el mismo patrón que usa PagoService cuando se cobra una cuota
     * que todavía no tenía fila.
     */
    private function materializarDeuda(array $calculada): ?DeudaAlumno
    {
        $alumno = $calculada['alumno'] ?? null;
        $curso = $calculada['curso'] ?? null;

        if (!$alumno || !$curso) {
            return null;
        }

        $existente = $this->entityManager->getRepository(DeudaAlumno::class)->findOneBy([
            'alumno' => $alumno,
            'curso' => $curso,
            'mes' => (int) $calculada['mes'],
            'ano' => (int) $calculada['ano'],
            'esCuotaInscripcionAnual' => false,
        ]);

        if ($existente) {
            return $existente;
        }

        $deuda = new DeudaAlumno();
        $deuda->setAlumno($alumno);
        $deuda->setCurso($curso);
        $deuda->setCursoHistorico($calculada['cursoHistorico'] ?? null);
        $deuda->setMes((int) $calculada['mes']);
        $deuda->setAno((int) $calculada['ano']);
        $deuda->setMonto((float) $calculada['monto']);
        $deuda->setInteres((float) ($calculada['interes'] ?? 0));
        $deuda->setInstituto($alumno->getInstituto());

        $this->entityManager->persist($deuda);
        $this->entityManager->flush();

        return $deuda;
    }

    /**
     * Si toca repetir el recordatorio de esta cuota: cuentan los días desde el último
     * recordatorio enviado, o desde que se creó la deuda si nunca se mandó ninguno.
     *
     * $cadaDias antes estaba fijo en 3 acá adentro; ahora lo define el instituto.
     */
    private function debeEnviarRecordatorio(DeudaAlumno $deuda, int $cadaDias): bool
    {
        $fechaActual = new \DateTime();
        
        // Buscar el último recordatorio enviado para esta deuda
        $ultimoRecordatorio = $this->entityManager->getRepository(EmailLog::class)
            ->createQueryBuilder('e')
            ->where('e.deuda = :deuda')
            ->andWhere('e.tipo = :tipo')
            ->andWhere('e.estado = :estado')
            ->setParameter('deuda', $deuda)
            ->setParameter('tipo', 'recordatorio')
            ->setParameter('estado', 'enviado')
            ->orderBy('e.fechaEnvio', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        // Si nunca se envió recordatorio, verificar desde la fecha de creación de la deuda
        if (!$ultimoRecordatorio) {
            $fechaReferencia = $deuda->getFechaCreacion();
        } else {
            $fechaReferencia = $ultimoRecordatorio->getFechaEnvio();
        }
        
        // Calcular días transcurridos
        $diasTranscurridos = $fechaActual->diff($fechaReferencia)->days;
        
        return $diasTranscurridos >= $cadaDias;
    }

    /**
     * Manda una comunicación libre a un alumno: un aviso de aumento, un cambio de horario, lo
     * que el instituto necesite comunicar.
     *
     * Vive acá y no en EmailService porque este servicio es el que sabe a quién hay que
     * escribirle según la configuración del instituto (alumno, tutor o los dos), quién es el
     * remitente y cómo dejar registro en el historial de emails. EmailService tenía un
     * sendGeneralCommunication() que no hacía nada de eso y que nunca se enchufó a ninguna
     * pantalla.
     *
     * $forzarEnvio no aplica: una comunicación la escribe una persona a propósito, así que no
     * depende de ningún switch de configuración.
     */
    public function enviarComunicacion(
        Alumno $alumno,
        string $asunto,
        string $mensaje,
        ?User $solicitadoPor = null
    ): bool {
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();

        $destinatarios = $this->resolverDestinatarios($alumno, null, $configuracion);
        $destino = implode(', ', $destinatarios);

        if (!$destinatarios) {
            $this->registrarEmailLog(
                $instituto, $alumno, 'comunicacion', 'Sin email', $asunto, 'fallido',
                'No hay ninguna dirección válida para notificar según la configuración del instituto',
                null, null, $solicitadoPor, false
            );

            return false;
        }

        $institutoEmail = $instituto->getEmail();
        $fromEmail = 'noreply@teambuilder.com.ar';
        if ($institutoEmail && filter_var($institutoEmail, FILTER_VALIDATE_EMAIL)) {
            $emailDomain = substr(strrchr($institutoEmail, '@'), 1);
            if (in_array($emailDomain, ['teambuilder.com.ar', 'dattaweb.com'], true)) {
                $fromEmail = $institutoEmail;
            }
        }

        try {
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $instituto->getNombre() ?? 'Instituto'))
                ->to(...$destinatarios)
                ->subject($asunto)
                ->htmlTemplate('emails/comunicacion.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'alumno' => $alumno,
                    'asunto' => $asunto,
                    'mensaje' => $mensaje,
                    'logoUrl' => $this->getLogoUrl($instituto),
                ]);

            $this->mailer->send($email);

            $this->registrarEmailLog(
                $instituto, $alumno, 'comunicacion', $destino, $asunto, 'enviado',
                null, null, null, $solicitadoPor, false
            );

            $this->tokenService->consumeTokens(
                $instituto,
                'notificacion.send',
                null,
                'Comunicación a ' . $alumno->getNombreApellido(),
                'Alumno',
                $alumno->getId()
            );

            return true;
        } catch (\Throwable $e) {
            // Se registra y se devuelve false en lugar de cortar: en un envío masivo, que falle
            // una dirección no puede impedir que salgan las demás.
            $this->registrarEmailLog(
                $instituto, $alumno, 'comunicacion', $destino, $asunto, 'fallido',
                $e->getMessage(), null, null, $solicitadoPor, false
            );

            return false;
        }
    }

    /**
     * Direcciones a las que va una notificación de este alumno.
     *
     * $override gana sobre todo: lo usa el reenvío manual desde el historial de emails,
     * que apunta a una dirección concreta elegida por el operador.
     *
     * Sin override manda la configuración del instituto: 'alumno', 'tutor' o 'ambos'.
     * Se descartan las direcciones inválidas y las repetidas, así que un alumno cuyo email
     * es el mismo que el del tutor recibe una sola copia y no dos.
     *
     * Devuelve lista vacía si no hay ninguna dirección usable; el llamador lo registra
     * como fallido en el log en vez de intentar un envío que va a explotar.
     *
     * @return string[]
     */
    private function resolverDestinatarios(
        Alumno $alumno,
        ?string $override,
        ?InstitutoConfiguracion $configuracion
    ): array {
        if ($override !== null && trim($override) !== '') {
            $override = trim($override);

            return filter_var($override, FILTER_VALIDATE_EMAIL) ? [$override] : [];
        }

        $modo = $configuracion ? $configuracion->getNotificarA() : 'alumno';

        $candidatos = [];
        if ($modo === 'alumno' || $modo === 'ambos') {
            $candidatos[] = $alumno->getEmail();
        }
        if ($modo === 'tutor' || $modo === 'ambos') {
            $candidatos[] = $alumno->getCorreTutor();
        }
        // Con 'tutor' y sin email de tutor cargado, se cae al del alumno: es mejor que la
        // notificación llegue a alguien que a nadie.
        if ($modo === 'tutor' && !$alumno->getCorreTutor()) {
            $candidatos[] = $alumno->getEmail();
        }

        $destinatarios = [];
        foreach ($candidatos as $candidato) {
            $email = trim((string) $candidato);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!in_array($email, $destinatarios, true)) {
                $destinatarios[] = $email;
            }
        }

        return $destinatarios;
    }

    /**
     * Obtiene la URL del logo del instituto
     */
    private function getLogoUrl(Instituto $instituto): ?string
    {
        if (!$instituto->getLogo()) {
            return null;
        }
        
        // Construir la URL completa del logo
        return $this->baseUrl . '/uploads/logos/' . $instituto->getLogo();
    }

    /**
     * Registra un email en el log
     */
    private function registrarEmailLog(
        Instituto $instituto,
        ?Alumno $alumno,
        string $tipo,
        string $destinatario,
        string $asunto,
        string $estado,
        ?string $errorMensaje = null,
        ?AlumnosPagos $pago = null,
        ?DeudaAlumno $deuda = null,
        ?User $solicitadoPor = null,
        bool $esAutomatico = false
    ): void {
        try {
            $emailLog = new EmailLog();
            $emailLog->setInstituto($instituto);
            $emailLog->setAlumno($alumno);
            $emailLog->setTipo($tipo);
            $emailLog->setDestinatario($destinatario);
            $emailLog->setAsunto($asunto);
            $emailLog->setFechaEnvio($this->institutoTimezoneService->getNowForInstituto($instituto));
            $emailLog->setEstado($estado);
            $emailLog->setErrorMensaje($errorMensaje);
            $emailLog->setPago($pago);
            $emailLog->setDeuda($deuda);
            $emailLog->setSolicitadoPor($solicitadoPor);
            $emailLog->setEsAutomatico($esAutomatico);
            
            $this->entityManager->persist($emailLog);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // No interrumpir el flujo si falla el registro del log
            // Podría loggear este error en un archivo si es necesario
        }
    }
}

