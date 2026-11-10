(function () {
    'use strict';
    function iniciar() {
        var form = document.getElementById('curso-busqueda-form');
        var limpiar = document.getElementById('curso-limpiar-busqueda');
        var tabs = document.getElementById('myTab');
        if (!form || !limpiar || !tabs) return;
        function actualizar() {
            var seleccion = tabs.querySelector('.nav-link.active');
            if (!seleccion) return;
            var tab = seleccion.id === 'profile-tab' ? 'disabled' : 'active';
            form.querySelector('input[name="tab"]').value = tab;
            var url = new URL(limpiar.href, window.location.href);
            url.searchParams.set('tab', tab);
            limpiar.href = url.href;
        }
        tabs.addEventListener('shown.bs.tab', actualizar);
        form.addEventListener('submit', actualizar);
        limpiar.addEventListener('click', actualizar);
        actualizar();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
