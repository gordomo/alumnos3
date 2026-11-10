/* Prueba de interacción sin navegador: node tests/ayuda_busqueda_ui.js */
function probarAyudaBusqueda(codigo, URLImpl) {
    'use strict';
    function check(value, label) { if (!value) throw new Error(label); }
    var ready = [], pops = [], requests = [], pushes = [], redirects = [];
    var overlay = false;
    var input = { value: '', focus: function () { this.focused = true; } };
    var status = { textContent: '' };
    var button = { disabled: false };
    var form = { action: 'http://localhost:8080/ayuda', dataset: { resultadosUrl: '/ayuda/resultados' },
        querySelector: function () { return button; },
        addEventListener: function (event, fn) { this[event] = fn; } };
    var results = { innerHTML: '', attrs: {},
        setAttribute: function (key, value) { this.attrs[key] = value; },
        addEventListener: function (event, fn) { this[event] = fn; } };
    Object.defineProperty(results, 'textContent', { set: function (text) { this.innerHTML = text; } });
    function XHR() { requests.push(this); }
    XHR.prototype.abort = function () { this.aborted = true; };
    XHR.prototype.open = function (method, url) { this.method = method; this.url = url; };
    XHR.prototype.setRequestHeader = function () {};
    XHR.prototype.send = function () {};
    XHR.prototype.getResponseHeader = function () { return this.fragment; };
    var elements = { 'ayuda-busqueda-form': form, 'ayuda-resultados-contenido': results,
        'ayuda-consulta': input, 'ayuda-busqueda-estado': status };
    var document = { readyState: 'loading', getElementById: function (id) { return elements[id]; },
        addEventListener: function (name, fn) { if (name === 'DOMContentLoaded') ready.push(fn); } };
    var location = new URLImpl(form.action);
    location.assign = function (url) { redirects.push(String(url)); };
    var window = { location: location, XMLHttpRequest: XHR,
        history: { pushState: function (_, title, url) {
            pushes.push(String(url));
            var next = new URLImpl(String(url)); location.href = next.href; location.pathname = next.pathname;
        } },
        hideLoading: function () { overlay = false; },
        addEventListener: function (name, fn) { if (name === 'popstate') pops.push(fn); } };
    // Simula el indicador global: registra popstate al terminar la carga del DOM.
    ready.push(function () { pops.push(function () { overlay = true; }); });
    new Function('window', 'document', 'XMLHttpRequest', 'URL', codigo)(window, document, XHR, URLImpl);
    ready.forEach(function (fn) { fn(); });
    function submit(query) {
        input.value = query;
        var prevented = false;
        form.submit({ preventDefault: function () { prevented = true; } });
        check(prevented, 'Debe evitar una recarga completa');
        return requests[requests.length - 1];
    }
    function ok(xhr, text) { xhr.status = 200; xhr.fragment = '1'; xhr.responseText = text; xhr.onload(); }
    var first = submit('saldo a favor');
    check(first.method === 'GET' && first.url.indexOf('/ayuda/resultados?') !== -1, 'Debe pedir solo el fragmento');
    check(button.disabled && results.attrs['aria-busy'] === 'true', 'Estado de carga local');
    ok(first, '<p>Resultado</p>');
    check(results.innerHTML === '<p>Resultado</p>' && pushes.length === 1 && !button.disabled, 'Resultado e historial sin navegar');
    var stale = submit('pago');
    var latest = submit('asistencia');
    check(stale.aborted, 'Cancelar consulta anterior');
    ok(stale, '<p>Viejo</p>');
    check(results.innerHTML === '<p>Resultado</p>', 'Una respuesta vieja no debe pisar la consulta nueva');
    ok(latest, '<p>Asistencia</p>');
    var beforeBack = pushes.length;
    var oldUrl = new URLImpl(form.action + '?q=pago'); location.href = oldUrl.href; location.pathname = oldUrl.pathname;
    pops.forEach(function (fn) { fn(); });
    check(!overlay && input.value === 'pago', 'Atrás restaura consulta y oculta el indicador global');
    ok(requests[requests.length - 1], '<p>Pago</p>');
    check(pushes.length === beforeBack, 'Atrás no crea otra entrada en el historial');
    var count = requests.length;
    results.click({ target: { closest: function () { return {}; } }, button: 0, preventDefault: function () {} });
    check(input.value === '' && results.innerHTML === '' && requests.length === count, 'Limpiar no necesita una petición');
    var failed = submit('pago'); failed.ontimeout();
    check(!button.disabled && status.textContent.indexOf('conexión') !== -1, 'Un timeout permite reintentar');
    var expired = submit('pago'); expired.status = 200; expired.fragment = null; expired.onload();
    check(redirects.length === 1 && redirects[0].indexOf('/ayuda?q=pago') !== -1, 'La sesión vencida vuelve a navegación normal');
    return 'OK: actualización parcial, concurrencia, historial, limpieza, timeout y sesión vencida';
}
if (typeof module !== 'undefined') module.exports = probarAyudaBusqueda;
if (typeof require !== 'undefined' && require.main === module) {
    var fs = require('fs');
    console.log(probarAyudaBusqueda(fs.readFileSync(require('path').join(__dirname, '../public/assets/js/ayuda-busqueda.js'), 'utf8'), URL));
}
