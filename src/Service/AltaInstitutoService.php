<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Entity\InstitutoConfiguracion;
use App\Entity\MetodoPago;
use App\Entity\SolicitudInstituto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Da de alta un instituto y deja su usuario listo para que elija contraseña.
 *
 * Esto vivía duplicado en el registro público y en el alta del panel, con diferencias: una
 * versión creaba los métodos de pago y la otra no. Ahora es un solo lugar.
 *
 * El usuario nace con una contraseña aleatoria que nadie conoce, no vacía: así la cuenta existe
 * pero no se puede usar hasta que la persona entre por el enlace de invitación y elija la suya.
 * La contraseña nunca viaja por mail.
 */
class AltaInstitutoService
{
    /** Una semana para aceptar la invitación. Después se puede reenviar. */
    public const DIAS_INVITACION = 7;

    private const METODOS_BASE = ['Efectivo', 'Transferencia', 'Tarjeta de Debito', 'Tarjeta de Credito'];

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    /**
     * Crea el instituto, su usuario administrador y la configuración mínima para empezar.
     *
     * No hace flush: lo decide quien llama, que normalmente tiene más cosas que guardar en la
     * misma transacción.
     */
    public function crear(
        string $nombre,
        string $email,
        ?string $telefono = null,
        ?string $direccion = null,
        ?string $timezone = null
    ): User {
        $instituto = new Instituto();
        $instituto->setNombre($nombre);
        $instituto->setEmail($email);
        $instituto->setTel($telefono);
        $instituto->setDir($direccion);
        $instituto->setFechaAlta(new \DateTime());

        $usuario = new User();
        $usuario->setEmail($email);
        $usuario->setRoles(['ROLE_ADMIN_INSTITUTO']);
        $usuario->setInstituto($instituto);
        $usuario->setPassword($this->passwordHasher->hashPassword($usuario, bin2hex(random_bytes(32))));

        $configuracion = new InstitutoConfiguracion();
        $configuracion->setInstituto($instituto);
        $configuracion->setTimezone($timezone !== null && $timezone !== '' ? $timezone : null);
        $configuracion->setDateFormat('d/m/Y');

        $this->em->persist($instituto);
        $this->em->persist($usuario);
        $this->em->persist($configuracion);

        foreach (self::METODOS_BASE as $orden => $nombreMetodo) {
            $metodo = new MetodoPago();
            $metodo->setInstituto($instituto);
            $metodo->setNombre($nombreMetodo);
            $metodo->setActivo(true);
            $metodo->setOrden($orden + 1);
            $this->em->persist($metodo);
        }

        return $usuario;
    }

    /**
     * Da de alta el instituto que pedía una solicitud y la marca como confirmada.
     */
    public function crearDesdeSolicitud(SolicitudInstituto $solicitud): User
    {
        $usuario = $this->crear(
            $solicitud->getNombreInstituto(),
            $solicitud->getEmail(),
            $solicitud->getTelefono()
        );

        $solicitud->setEstado(SolicitudInstituto::CONFIRMADA);
        $solicitud->setFechaResolucion(new \DateTime());
        $solicitud->setInstituto($usuario->getInstituto());

        return $usuario;
    }

    /**
     * Genera (o renueva) el enlace de invitación del usuario y devuelve el token.
     *
     * Se reutiliza el mecanismo de recuperación de contraseña porque es exactamente el mismo
     * problema: un enlace de un solo uso con vencimiento. Lo único distinto es la pantalla.
     */
    public function generarInvitacion(User $usuario): string
    {
        $token = bin2hex(random_bytes(32));

        $usuario->setResetToken($token);
        $usuario->setResetTokenExpiresAt((new \DateTime())->modify('+' . self::DIAS_INVITACION . ' days'));
        $usuario->setResetTokenSentAt(new \DateTime());

        return $token;
    }
}
