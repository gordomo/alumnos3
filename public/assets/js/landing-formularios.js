// El enlace sigue funcionando sin JavaScript; este detalle selecciona el curso elegido.
document.querySelectorAll('.preinscribir-curso').forEach(function (enlace) {
    enlace.addEventListener('click', function () {
        var select = document.getElementById('preinscripcion-curso');
        if (select) { select.value = enlace.dataset.curso; }
    });
});
document.querySelectorAll('.landing-form').forEach(function (form) {
    form.addEventListener('submit', function () {
        var boton = form.querySelector('button[type="submit"]');
        if (boton) { boton.disabled = true; boton.textContent = 'Enviando…'; }
    });
});
