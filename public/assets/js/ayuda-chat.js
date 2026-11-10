(function () {
    'use strict';
    function iniciar() {
        var form = document.getElementById('ayuda-chat-form');
        if (!form) return;
        var pregunta = document.getElementById('ayuda-chat-pregunta');
        var acepta = document.getElementById('ayuda-chat-acepta');
        var enviar = document.getElementById('ayuda-chat-enviar');
        var mensajes = document.getElementById('ayuda-chat-mensajes');
        var estado = document.getElementById('ayuda-chat-estado');
        var pendiente = null;

        function agregar(titulo, texto, fuentes) {
            var bloque = document.createElement('div');
            bloque.className = 'border rounded p-3 mb-3';
            var autor = document.createElement('strong');
            autor.textContent = titulo;
            var contenido = document.createElement('p');
            contenido.className = 'mb-1 mt-1';
            contenido.style.whiteSpace = 'pre-wrap';
            contenido.textContent = texto;
            bloque.appendChild(autor);
            bloque.appendChild(contenido);
            if (Array.isArray(fuentes)) {
                fuentes.forEach(function (fuente) {
                    // El servidor genera estos enlaces desde el manual. Defensa adicional local.
                    if (!fuente || typeof fuente.url !== 'string') return;
                    var url;
                    try { url = new URL(fuente.url, window.location.href); } catch (e) { return; }
                    if (url.origin !== window.location.origin || !/\/ayuda\/[a-z0-9-]+$/.test(url.pathname)) return;
                    var enlace = document.createElement('a');
                    enlace.className = 'd-block small mt-1';
                    enlace.href = url.href;
                    enlace.textContent = fuente.titulo;
                    bloque.appendChild(enlace);
                });
            }
            mensajes.appendChild(bloque);
            // Acotar memoria y contenido del navegador, sin almacenamiento persistente.
            while (mensajes.children.length > 20) mensajes.removeChild(mensajes.firstChild);
            mensajes.scrollTop = mensajes.scrollHeight;
        }
        function liberar() {
            pendiente = null;
            enviar.disabled = false;
            pregunta.disabled = false;
            form.removeAttribute('aria-busy');
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var texto = pregunta.value.trim();
            if (pendiente || !texto || !acepta.checked) return;
            agregar('Vos', texto);
            pregunta.value = '';
            enviar.disabled = true;
            pregunta.disabled = true;
            form.setAttribute('aria-busy', 'true');
            estado.textContent = 'Consultando el manual…';
            var xhr = new XMLHttpRequest();
            pendiente = xhr;
            xhr.open('POST', form.dataset.url, true);
            xhr.timeout = 30000;
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', form.dataset.token);
            xhr.onload = function () {
                if (pendiente !== xhr) return;
                liberar();
                var datos;
                try { datos = JSON.parse(xhr.responseText); } catch (e) {
                    estado.textContent = 'No se pudo completar la consulta. Actualizá la página para comprobar tu sesión; la búsqueda sigue disponible.';
                    return;
                }
                estado.textContent = '';
                if (typeof datos.respuesta === 'string') agregar('Asistente', datos.respuesta, datos.fuentes);
                else estado.textContent = datos.error || 'No se pudo completar la consulta. Usá la búsqueda o intentá más tarde.';
                pregunta.focus();
            };
            xhr.onerror = xhr.ontimeout = function () {
                if (pendiente !== xhr) return;
                liberar();
                estado.textContent = 'No se pudo completar la consulta. La búsqueda sigue disponible. Podés reintentar más tarde.';
            };
            xhr.send(JSON.stringify({ pregunta: texto, aceptaPrueba: true }));
        });
        document.getElementById('ayuda-chat-limpiar').addEventListener('click', function () {
            var xhr = pendiente;
            liberar();
            if (xhr) xhr.abort();
            mensajes.textContent = '';
            estado.textContent = '';
            pregunta.value = '';
            pregunta.focus();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
