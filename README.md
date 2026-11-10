# alumnos3

## Criterio visual de controles del panel

`public/assets/css/controles.css` se carga al final del `<head>` desde `base.html.twig`
para usuarios autenticados. Centraliza los controles del panel de ambas verticales.

- Acción principal: `btn btn-primary` (buscar, filtrar, guardar, confirmar).
- Acción secundaria: `btn btn-outline-secondary` (limpiar, volver, importar, ver, editar).
- Acción destructiva: `btn btn-danger` o `btn btn-outline-danger`.
- Advertencia: `btn btn-warning` o `btn btn-outline-warning` para acciones con consecuencias.
- Estados con significado propio, como asistencia: agregar `control-estado` para conservar sus colores.
- Controles habituales de 40 px; botones compactos de 36 px y acciones de tabla de 38 px.
- Barras de filtros: `ui-filter-card`; no redefinir por pantalla alturas, radios ni degradados de botones.

Los botones históricos `btn-success` se representan como acciones principales;
`btn-secondary`, `btn-info` y variantes outline de navegación usan el estilo secundario.
La hoja conserva textareas de altura variable, controles deshabilitados y estados de asistencia.
