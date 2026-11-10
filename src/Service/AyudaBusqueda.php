<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Búsqueda local sobre el manual visible; sin consultas a datos ni servicios externos. */
class AyudaBusqueda
{
    private const VACIAS = 'a al algo alguna algunas alguno algunos ante antes aquel aqui asi aun cada como con cual cuales cuando de del desde donde el ella ellas ellos en entre era es esa esas ese esos esta estas este estos estoy fue ha hacer hacerlo hago hasta hay la las le les lo los mas me mi mis mismo mucha muchas mucho muchos muy nada necesito no nos nuestra nuestro o otra otras otro otros para pero por porque puedo puede pueden podemos que quien quiero se sea ser si sin sobre solo su sus te tener tiene tienen todo todos tu tus un una unas uno unos usted ustedes voy ya ver veo dos tres varios varias enero febrero marzo abril mayo junio julio agosto septiembre octubre noviembre diciembre';

    private const SINONIMOS = [
        'pago' => ['pagos', 'pagar', 'pagando', 'pague', 'abonar', 'abono', 'abonado', 'cobrar', 'cobro', 'cobros', 'cobrado', 'cobranza'],
        'deuda' => ['deudas', 'debo', 'debe', 'deben', 'adeuda', 'adeudado', 'adeudados', 'debiendo'],
        'cuota' => ['cuotas', 'mensualidad', 'mensualidades', 'arancel', 'aranceles'],
        'inscripcion' => ['inscripciones', 'inscribir', 'inscribo', 'inscribirme', 'inscripto', 'inscriptos', 'anotar'],
        'alumno' => ['alumnos', 'socio', 'socios', 'estudiante', 'estudiantes'],
        'curso' => ['cursos', 'deporte', 'deportes', 'disciplina', 'disciplinas'],
        'profesor' => ['profesores', 'profe', 'profes', 'docente', 'docentes'],
        'calificacion' => ['calificaciones', 'nota', 'notas', 'calificar'],
        'evaluacion' => ['evaluaciones', 'examen', 'examenes', 'parcial', 'parciales'],
        'tarea' => ['tareas', 'deberes'],
        'clase' => ['clases', 'entrenamiento', 'entrenamientos'],
        'enviar' => ['envio', 'envios', 'enviarlo', 'enviarla', 'mandar', 'mando', 'manda', 'mandarlo'],
        'asistencia' => ['asistencias', 'asistir', 'asistio'],
        'interes' => ['intereses', 'recargo', 'recargos', 'mora'],
        'contrasena' => ['contrasenas', 'clave', 'claves', 'password'],
        'recuperar' => ['recuperacion', 'recupero', 'olvide', 'olvidaste', 'olvidada'],
        'registrar' => ['registro', 'registrando', 'registrado', 'cargar', 'cargo', 'cargado'],
        'aumento' => ['aumentar', 'aumentos', 'aumenta'],
        'precio' => ['precios', 'importe', 'importes', 'monto', 'montos'],
        'saldo' => ['saldos'],
        'mes' => ['meses'],
        'liquidacion' => ['liquidaciones', 'liquidar'],
        'recibo' => ['recibos'],
        'entrega' => ['entregas', 'entregar', 'entregada', 'entregadas'],
        'credito' => ['creditos'],
        'descuento' => ['descuentos'],
        'adelantado' => ['adelantados', 'adelantar', 'anticipado', 'anticipados'],
        'cerrar' => ['cierre', 'cerrado', 'cerrados'],
        'email' => ['emails', 'mail', 'mails', 'correo', 'correos'],
        'familia' => ['familias', 'tutor', 'tutores'],
        'juntos' => ['junto', 'simultaneamente'],
    ];

    private array $vacias;
    private array $sinonimos = [];

    public function __construct(private AyudaFuente $fuente, private ?CacheInterface $cache = null)
    {
        $this->vacias = array_flip(explode(' ', self::VACIAS));
        foreach (self::SINONIMOS as $clave => $variantes) {
            foreach ($variantes as $variante) {
                $this->sinonimos[$variante] = $clave;
            }
        }
    }

    public function buscar(string $consulta, int $limite = 5): array
    {
        if (mb_strlen($consulta) > 300) {
            throw new \InvalidArgumentException('La búsqueda admite hasta 300 caracteres.');
        }
        $terminos = array_values(array_unique($this->palabras($consulta)));
        if (!$terminos) {
            return [];
        }
        // Se comparte solo el índice estático para la misma combinación de
        // permisos, vertical y versión del manual. Nunca respuestas ni consultas.
        $indice = $this->cache !== null
            ? $this->cache->get('ayuda_indice.' . hash('sha256', $this->fuente->claveIndice() . hash_file('sha256', __FILE__)), function (ItemInterface $item): array {
                $item->expiresAfter(3600);

                return $this->construirIndice();
            })
            : $this->construirIndice();
        $documentos = $indice['documentos'];
        $frecuencias = $indice['frecuencias'];
        $resultados = [];
        foreach ($documentos as [$seccion, $campos]) {
            $coincidencias = 0;
            $puntaje = 0;
            foreach ($terminos as $termino) {
                $valor = 0;
                foreach (['titulo' => 6, 'contexto' => 3, 'tituloTema' => 2, 'texto' => 1] as $campo => $peso) {
                    if (isset($campos[$campo][$termino])) {
                        $valor += $peso * (1 + log($campos[$campo][$termino]));
                    }
                }
                if ($valor > 0) {
                    $coincidencias++;
                    $puntaje += $valor * log(1 + (count($documentos) + 1) / (($frecuencias[$termino] ?? 0) + 1));
                }
            }
            $minimo = count($terminos) <= 2 ? count($terminos) : max(2, (int) ceil(count($terminos) * 0.6));
            if ($coincidencias < $minimo) {
                continue;
            }
            $puntaje *= ($coincidencias / count($terminos)) ** 2;
            $resultados[] = ['puntaje' => $puntaje, 'seccion' => $seccion];
        }
        usort($resultados, static function (array $a, array $b): int {
            return ($b['puntaje'] <=> $a['puntaje']) ?: strcmp($a['seccion']['id'], $b['seccion']['id']);
        });

        return array_column(array_slice($resultados, 0, max(1, min(10, $limite))), 'seccion');
    }

    private function construirIndice(): array
    {
        $documentos = [];
        $frecuencias = [];
        foreach ($this->fuente->seccionesVisibles() as $seccion) {
            $campos = [];
            $presentes = [];
            foreach (['titulo', 'contexto', 'tituloTema', 'texto'] as $campo) {
                $campos[$campo] = array_count_values($this->palabras($seccion[$campo] ?? ''));
                $presentes += $campos[$campo];
            }
            foreach (array_keys($presentes) as $termino) {
                $frecuencias[$termino] = ($frecuencias[$termino] ?? 0) + 1;
            }
            $seccion['extracto'] = $this->extracto($seccion['texto']);
            $documentos[] = [$seccion, $campos];
        }

        return ['documentos' => $documentos, 'frecuencias' => $frecuencias];
    }

    private function palabras(string $texto): array
    {
        // El glosario usa alumn@s/soci@s; ambos deben coincidir con escritura corriente.
        $texto = str_replace('@', 'o', mb_strtolower($texto));
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        $palabras = preg_split('/[^a-z0-9]+/', strtolower($texto), -1, PREG_SPLIT_NO_EMPTY);
        $resultado = [];
        foreach ($palabras as $palabra) {
            if (strlen($palabra) < 2 || ctype_digit($palabra) || isset($this->vacias[$palabra])) {
                continue;
            }
            $resultado[] = $this->sinonimos[$palabra] ?? $palabra;
        }

        return $resultado;
    }

    private function extracto(string $texto): string
    {
        $texto = preg_replace('/\s+/u', ' ', $texto);
        if (mb_strlen($texto) <= 280) {
            return $texto;
        }
        $corte = mb_substr($texto, 0, 280);
        $espacio = mb_strrpos($corte, ' ');

        return mb_substr($corte, 0, $espacio === false ? 280 : $espacio) . '…';
    }
}
