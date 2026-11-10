<?php

namespace App\Service;

use App\Entity\Instituto;

class AyudaIa
{
    private const SIN_RESPUESTA = 'Solo puedo guiarte sobre el uso del sistema con la ayuda disponible para tu usuario. No encontré información suficiente para responder esa pregunta. Probá indicando qué pantalla o tarea necesitás resolver.';

    public function __construct(private AyudaBusqueda $busqueda, private GeminiAyudaCliente $cliente, private AyudaIaCuota $cuota) {}

    public function responder(string $pregunta, Instituto $instituto): array
    {
        $secciones = $this->busqueda->buscar($pregunta, 3);
        $fuentes = array_map(static fn (array $s): array => [
            'id' => $s['id'], 'titulo' => $s['tituloTema'] . ': ' . $s['titulo'], 'url' => $s['url'],
        ], $secciones);
        if (!$secciones) {
            return $this->resultado('sin_fuentes', self::SIN_RESPUESTA, []);
        }
        // Solo texto estático ya filtrado por permisos. Nunca entidades ni datos de sesión.
        $documentos = array_map(static fn (array $s): array => [
            'id' => $s['id'], 'tema' => $s['tituloTema'], 'titulo' => $s['titulo'],
            'texto' => mb_substr($s['texto'], 0, 3000),
        ], $secciones);
        $espera = $this->cuota->consumir($instituto->getId());
        if ($espera > 0) {
            return $this->resultado('limite', 'Alcanzamos el límite de consultas de la prueba. Podés seguir usando la búsqueda y estos artículos del manual.', $fuentes) + ['reintentarEn' => $espera];
        }
        try {
            $respuesta = $this->cliente->responder($pregunta, $documentos);
            if ($respuesta['respuesta'] === '' || !$respuesta['fuentes']) {
                return $this->resultado('sin_fuentes', self::SIN_RESPUESTA, []);
            }
            // Los enlaces se construyen con el manual local, nunca con URLs del modelo.
            $citadas = array_values(array_filter($fuentes, static fn (array $s): bool => in_array($s['id'], $respuesta['fuentes'], true)));
            return $this->resultado('ok', $respuesta['respuesta'], $citadas);
        } catch (\Throwable $error) {
            // No guardar preguntas, respuestas, claves ni cuerpos de error del proveedor.
            return $this->resultado('no_disponible', 'La ayuda con IA no está disponible en este momento. Podés seguir usando la búsqueda y consultar estos artículos.', $fuentes);
        }
    }

    private function resultado(string $estado, string $respuesta, array $fuentes): array
    {
        return compact('estado', 'respuesta', 'fuentes');
    }
}
