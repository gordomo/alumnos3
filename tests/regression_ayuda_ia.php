<?php
/** Sin proveedor externo. Reutiliza las pruebas del manual y sus permisos reales. */
require __DIR__ . '/regression_ayuda.php';

use App\Entity\Instituto;
use App\Service\AyudaIa;
use App\Service\AyudaIaAcceso;
use App\Service\AyudaIaCuota;
use App\Service\GeminiAyudaCliente;

$cliente = new class('', 'gemini-3.5-flash-lite') extends GeminiAyudaCliente {
    public int $llamadas = 0;
    public array $documentos = [];
    public bool $fallar = false;
    public function configurado(): bool { return true; }
    public function responder(string $pregunta, array $documentos): array {
        $this->llamadas++;
        $this->documentos = $documentos;
        if ($this->fallar) throw new RuntimeException('Error simulado que no debe salir al usuario');
        return ['respuesta' => '<script>alert(1)</script> Respuesta de prueba', 'fuentes' => [$documentos[0]['id']]];
    }
};
$cuota = new class extends AyudaIaCuota {
    public int $llamadas = 0;
    public int $espera = 0;
    public function __construct() {}
    public function consumir(int $institutoId, ?int $ahora = null): int { $this->llamadas++; return $this->espera; }
};
$instituto = (new Instituto())->setNombre('Dato privado NO enviar');
(new ReflectionProperty(Instituto::class, 'id'))->setValue($instituto, 42);
$acceso = new AyudaIaAcceso(true, true, $cliente);
verificar(!$acceso->estado($instituto)['disponible'], 'IA: básico sin acceso');
$instituto->setPlan('premium');
verificar(!$acceso->estado($instituto)['disponible'], 'IA: modo prueba no habilita premium automáticamente');
$instituto->setAyudaIaPrueba(true);
verificar($acceso->estado($instituto)['disponible'], 'IA: institución de prueba habilitada');
verificar(!(new AyudaIaAcceso(false, true, $cliente))->estado($instituto)['disponible'], 'IA: interruptor global');
verificar(!(new AyudaIaAcceso(true, false, new GeminiAyudaCliente('', 'gemini-3.5-flash-lite')))->estado($instituto)['disponible'], 'IA: sin clave no hay acceso');
$instituto->setAyudaIaPrueba(false);
verificar((new AyudaIaAcceso(true, false, $cliente))->estado($instituto)['disponible'], 'IA: premium habilitable fuera de prueba');
$instituto->darDeBaja();
verificar(!(new AyudaIaAcceso(true, false, $cliente))->estado($instituto)['disponible'], 'IA: institución inactiva sin acceso');
$instituto->reactivar();
verificar(!$acceso->estado(null)['disponible'], 'IA: sin institución sin acceso');

$auth->roles = ['ROLE_ADMIN_INSTITUTO'];
[, , $busquedaIa] = preparar('instituto', $auth, $router, $cacheCompartida);
$ia = new AyudaIa($busquedaIa, $cliente, $cuota);
verificar($ia->responder('capital de Francia', $instituto)['estado'] === 'sin_fuentes' && $cliente->llamadas === 0 && $cuota->llamadas === 0, 'IA: sin fuentes no consume cuota ni llama al proveedor');
$resultado = $ia->responder('cómo registrar un pago', $instituto);
verificar($resultado['estado'] === 'ok' && count($resultado['fuentes']) === 1, 'IA: respuesta con referencias locales');
verificar(count($cliente->documentos) <= 3 && !str_contains(json_encode($cliente->documentos), 'Dato privado'), 'IA: contexto acotado sin identidad de la institución');
$auth->roles = ['ROLE_PROFESOR'];
$antes = $cliente->llamadas;
verificar($ia->responder('saldo a favor', $instituto)['estado'] === 'sin_fuentes' && $cliente->llamadas === $antes, 'IA: profesor no envía documentación administrativa');
$ia->responder('tomar asistencia', $instituto);
verificar(!str_contains(json_encode($cliente->documentos), 'pagos#registrar-un-pago'), 'IA: cambio de rol no reutiliza fuentes restringidas');
$auth->roles = ['ROLE_ADMIN_INSTITUTO'];
$cuota->espera = 60;
$antes = $cliente->llamadas;
verificar($ia->responder('cómo registrar un pago', $instituto)['estado'] === 'limite' && $cliente->llamadas === $antes, 'IA: cuota agotada no llama al proveedor');
$cuota->espera = 0; $cliente->fallar = true;
$resultado = $ia->responder('cómo registrar un pago', $instituto);
verificar($resultado['estado'] === 'no_disponible' && $resultado['fuentes'] && !str_contains(json_encode($resultado), 'simulado'), 'IA: error del proveedor conserva manual y oculta detalles');

$real = new GeminiAyudaCliente('clave-no-real', 'gemini-3.5-flash-lite');
$docs = [['id' => 'pagos#registrar', 'texto' => 'Manual']];
$payload = $real->solicitud('Pregunta', $docs);
verificar(!isset($payload['tools']) && !str_contains(json_encode($payload), 'clave-no-real'), 'IA: solicitud sin herramientas ni clave en el cuerpo');
function candidato(array $respuesta, string $fin = 'STOP'): array {
    return ['candidates' => [['finishReason' => $fin, 'content' => ['parts' => [['text' => json_encode($respuesta)]]]]]];
}
verificar($real->interpretar(candidato(['respuesta' => 'Pasos', 'fuentes' => ['pagos#registrar']]), $docs)['respuesta'] === 'Pasos', 'IA: interpreta respuesta estructurada');
foreach ([
    candidato(['respuesta' => 'Datos ajenos', 'fuentes' => ['usuarios#secreto']]),
    candidato(['respuesta' => 'Truncada', 'fuentes' => ['pagos#registrar']], 'MAX_TOKENS'),
    candidato(['respuesta' => ['mal'], 'fuentes' => []]),
    [],
] as $mala) {
    $rechazada = false;
    try { $real->interpretar($mala, $docs); } catch (Throwable $e) { $rechazada = true; }
    verificar($rechazada, 'IA: rechaza respuesta inválida, incompleta o referencias inventadas');
}

// Los campos comerciales solo se agregan para el superadministrador.
$containerSecurity = new Symfony\Component\DependencyInjection\Container();
$containerSecurity->set('security.authorization_checker', $auth);
$security = new Symfony\Component\Security\Core\Security($containerSecurity);
$factory = Symfony\Component\Form\Forms::createFormFactoryBuilder()
    ->addExtension(new Symfony\Component\Form\Extension\Validator\ValidatorExtension(Symfony\Component\Validator\Validation::createValidator()))
    ->addType(new App\Form\InstitutoType($security))->getFormFactory();
$auth->roles = ['ROLE_ADMIN'];
$basico = (new Instituto())->setNombre('Institución');
$form = $factory->create(App\Form\InstitutoType::class, $basico, ['is_edit' => true]);
verificar(!$form->has('plan') && !$form->has('ayudaIaPrueba'), 'Planes: administrador comercial sin campos de habilitación');
$form->submit(['plan' => 'premium', 'ayudaIaPrueba' => true], false);
verificar(!$form->isValid() && $basico->getPlan() === 'basico' && !$basico->isAyudaIaPrueba(), 'Planes: parámetros falsificados no cambian el acceso');
$auth->roles = ['ROLE_SUPER_ADMIN'];
$form = $factory->create(App\Form\InstitutoType::class, $basico, ['is_edit' => true]);
verificar($form->has('plan') && $form->has('ayudaIaPrueba'), 'Planes: superadministrador puede configurar plan y prueba');
