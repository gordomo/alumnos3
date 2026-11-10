<?php

namespace App\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Fuente de conocimiento generada desde el bloque de contenido del manual.
 * No renderiza el layout ni consulta entidades de negocio. Los permisos se
 * aplican antes de renderizar y Twig resuelve las ramas del rol actual.
 */
class AyudaFuente
{
    public function __construct(
        private AyudaCatalogo $catalogo,
        private Environment $twig,
        private UrlGeneratorInterface $router
    ) {
    }

    /**
     * Identifica únicamente contenido estático y permisos efectivos. No incluye
     * preguntas, sesiones, nombres ni información del instituto. El hash del
     * contenido invalida el índice aun si se conserva la fecha de los archivos.
     */
    public function claveIndice(): string
    {
        $temas = $this->catalogo->temasVisibles();
        $hash = hash_init('sha256');
        hash_update($hash, json_encode([
            $temas,
            $this->catalogo->contextoPermisos(),
            $this->twig->getGlobals()['vertical'] ?? 'instituto',
            $this->router->generate('app_ayuda_tema', ['tema' => 'contexto']),
        ], JSON_THROW_ON_ERROR));
        foreach (array_keys($temas) as $tema) {
            hash_update($hash, $this->twig->getLoader()->getSourceContext('ayuda/temas/' . $tema . '.html.twig')->getCode());
        }
        hash_update($hash, $this->twig->getLoader()->getSourceContext('ayuda/_macros.html.twig')->getCode());
        foreach ([__FILE__, __DIR__ . '/Vocabulario.php'] as $archivo) {
            hash_update($hash, hash_file('sha256', $archivo));
        }

        return hash_final($hash);
    }

    public function seccionesVisibles(): array
    {
        $temas = $this->catalogo->temasVisibles();
        $secciones = [];
        foreach ($temas as $slug => $tema) {
            $html = $this->twig->load('ayuda/temas/' . $slug . '.html.twig')->renderBlock('contenido', [
                'tema' => $tema + ['slug' => $slug],
                'temas' => $temas,
            ]);
            $documento = new \DOMDocument();
            $documento->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><main>' . $html . '</main></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            $xpath = new \DOMXPath($documento);
            foreach ($xpath->query('//figure | //script | //style | //img') as $nodo) {
                $nodo->parentNode->removeChild($nodo);
            }

            $titulo = $tema['titulo'];
            $ancla = 'introduccion';
            $padre = null;
            $contenido = '';
            foreach ($documento->getElementsByTagName('main')->item(0)->childNodes as $nodo) {
                if ($nodo instanceof \DOMElement && in_array($nodo->tagName, ['h2', 'h3'], true)) {
                    $this->agregar($secciones, $slug, $tema, $titulo, $ancla, $padre, $contenido);
                    if ($nodo->tagName === 'h2') {
                        $padre = trim($nodo->textContent);
                    }
                    $titulo = trim($nodo->textContent);
                    $ancla = $nodo->getAttribute('id');
                    if ($ancla === '') {
                        throw new \LogicException('Falta un id estable en el manual: ' . $slug . ' / ' . $titulo);
                    }
                    $contenido = '';
                } else {
                    $contenido .= $this->texto($nodo);
                }
            }
            $this->agregar($secciones, $slug, $tema, $titulo, $ancla, $padre, $contenido);
        }

        return $secciones;
    }

    private function agregar(array &$secciones, string $slug, array $tema, string $titulo, string $ancla, ?string $padre, string $texto): void
    {
        $texto = preg_replace('/[\p{Zs}\t]+/u', ' ', $texto);
        $texto = trim(preg_replace('/\n\s*\n+/u', "\n\n", $texto));
        if ($texto === '') {
            return;
        }
        $secciones[] = [
            'id' => $slug . '#' . $ancla,
            'tema' => $slug,
            'tituloTema' => $tema['titulo'],
            'titulo' => $titulo,
            'contexto' => $padre !== $titulo ? $padre : null,
            'texto' => $texto,
            'url' => $this->router->generate('app_ayuda_tema', ['tema' => $slug]) . '#' . $ancla,
        ];
    }

    private function texto(\DOMNode $nodo): string
    {
        if ($nodo instanceof \DOMText) {
            return $nodo->nodeValue;
        }
        if (!$nodo instanceof \DOMElement) {
            return '';
        }
        if ($nodo->tagName === 'br') {
            return "\n";
        }
        $texto = '';
        foreach ($nodo->childNodes as $hijo) {
            $texto .= $this->texto($hijo);
        }
        if (in_array($nodo->tagName, ['td', 'th'], true)) {
            return trim($texto) . ' | ';
        }
        if (in_array($nodo->tagName, ['p', 'li', 'ol', 'ul', 'div', 'tr', 'table'], true)) {
            return "\n" . trim($texto) . "\n";
        }

        return $texto;
    }
}
