/* La ayuda conserva el GET normal sin JavaScript; con JS solo reemplaza resultados. */
(function () {
    'use strict';
    function iniciar() {
        var form = document.getElementById('ayuda-busqueda-form');
        var results = document.getElementById('ayuda-resultados-contenido');
        if (!form || !results || !window.XMLHttpRequest || !window.history.pushState) return;
        var input = document.getElementById('ayuda-consulta');
        var status = document.getElementById('ayuda-busqueda-estado');
        var button = form.querySelector('button[type="submit"]');
        var pending = null;

        function busy(value) {
            button.disabled = value;
            results.setAttribute('aria-busy', value ? 'true' : 'false');
            status.textContent = value ? 'Buscando…' : '';
        }
        function pageUrl(query) {
            var url = new URL(form.action, window.location.href);
            if (query) url.searchParams.set('q', query);
            return url;
        }
        function search(updateHistory) {
            if (pending) pending.abort();
            pending = null;
            var query = input.value.trim();
            var destination = pageUrl(query);
            if (!query) {
                results.textContent = '';
                busy(false);
                if (updateHistory) window.history.pushState(null, '', destination);
                return;
            }
            var url = new URL(form.dataset.resultadosUrl, window.location.href);
            url.searchParams.set('q', query);
            var xhr = new XMLHttpRequest();
            pending = xhr;
            busy(true);
            // XHR evita que el indicador global de fetch tape toda la página.
            xhr.open('GET', url.href, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = 15000;
            xhr.onload = function () {
                if (pending !== xhr) return;
                pending = null;
                busy(false);
                if (xhr.status === 200 && xhr.getResponseHeader('X-Ayuda-Fragment') === '1') {
                    // Fragmento de Twig del mismo servidor, con consulta y extractos escapados.
                    results.innerHTML = xhr.responseText;
                    if (updateHistory) window.history.pushState(null, '', destination);
                } else if (xhr.status === 401 || xhr.status === 403 ||
                           (xhr.status === 200 && xhr.getResponseHeader('X-Ayuda-Fragment') !== '1')) {
                    // Sesión vencida o redirección al login: volver a la navegación normal.
                    window.location.assign(destination);
                } else {
                    status.textContent = 'No se pudo completar la búsqueda. Volvé a intentarlo.';
                }
            };
            xhr.onerror = xhr.ontimeout = function () {
                if (pending !== xhr) return;
                pending = null;
                busy(false);
                status.textContent = 'No se pudo completar la búsqueda. Revisá la conexión y volvé a intentarlo.';
            };
            xhr.send();
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            search(true);
        });
        results.addEventListener('click', function (event) {
            var link = event.target.closest('[data-ayuda-limpiar]');
            if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            input.value = '';
            search(true);
            input.focus();
        });
        window.addEventListener('popstate', function () {
            if (window.location.pathname !== new URL(form.action).pathname) return;
            if (typeof window.hideLoading === 'function') window.hideLoading();
            input.value = new URL(window.location.href).searchParams.get('q') || '';
            search(false);
        });
    }
    // El indicador global registra sus eventos en DOMContentLoaded. Inicializar
    // después permite que la navegación local oculte su overlay al volver atrás.
    if (document.readyState === 'complete') iniciar();
    else document.addEventListener('DOMContentLoaded', iniciar);
}());
