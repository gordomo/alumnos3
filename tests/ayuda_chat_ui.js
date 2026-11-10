/* node tests/ayuda_chat_ui.js — DOM y XHR simulados, sin llamadas externas. */
function probarAyudaChat(codigo, URLImpl) {
    'use strict';
    function check(ok, mensaje) { if (!ok) throw new Error(mensaje); }
    function elemento() {
        var e = { children: [], style: {}, dataset: {}, attrs: {}, value: '', disabled: false,
            addEventListener: function (nombre, fn) { this[nombre] = fn; },
            setAttribute: function (k, v) { this.attrs[k] = v; },
            removeAttribute: function (k) { delete this.attrs[k]; },
            appendChild: function (hijo) { this.children.push(hijo); },
            removeChild: function (hijo) { this.children.splice(this.children.indexOf(hijo), 1); },
            focus: function () { this.focused = true; } };
        Object.defineProperty(e, 'textContent', { get: function () { return this.text || ''; }, set: function (t) { this.text = t; this.children = []; } });
        Object.defineProperty(e, 'firstChild', { get: function () { return this.children[0]; } });
        // Cualquier uso de innerHTML en el chat falla: debe renderizar solo texto.
        Object.defineProperty(e, 'innerHTML', { set: function () { throw new Error('No usar innerHTML'); } });
        return e;
    }
    var ids = ['form', 'pregunta', 'acepta', 'enviar', 'mensajes', 'estado', 'limpiar'];
    var elementos = {};
    ids.forEach(function (id) { elementos['ayuda-chat-' + id] = elemento(); });
    var form = elementos['ayuda-chat-form'], input = elementos['ayuda-chat-pregunta'];
    var mensajes = elementos['ayuda-chat-mensajes'], estado = elementos['ayuda-chat-estado'];
    form.dataset = { url: '/ayuda/chat', token: 'csrf-de-prueba' };
    var requests = [];
    function XHR() { this.headers = {}; requests.push(this); }
    XHR.prototype.open = function (method, url) { this.method = method; this.url = url; };
    XHR.prototype.setRequestHeader = function (k, v) { this.headers[k] = v; };
    XHR.prototype.send = function (texto) { this.payload = JSON.parse(texto); };
    XHR.prototype.abort = function () { this.aborted = true; };
    var doc = { readyState: 'complete', getElementById: function (id) { return elementos[id]; }, createElement: elemento };
    new Function('document', 'window', 'XMLHttpRequest', 'URL', codigo)(doc, { location: new URLImpl('http://localhost:8080/ayuda') }, XHR, URLImpl);
    function enviar(texto) {
        input.value = texto;
        form.submit({ preventDefault: function () {} });
        return requests[requests.length - 1];
    }
    enviar('pago'); check(requests.length === 0, 'Sin aceptación no se llama al servidor');
    elementos['ayuda-chat-acepta'].checked = true;
    var primera = enviar('<img src=x onerror=alert(1)>');
    check(primera.method === 'POST' && primera.headers['X-CSRF-Token'] === 'csrf-de-prueba', 'POST con CSRF');
    check(primera.payload.aceptaPrueba === true && input.disabled, 'Consentimiento y estado pendiente');
    enviar('duplicada'); check(requests.length === 1, 'Evita consultas duplicadas');
    primera.responseText = JSON.stringify({ respuesta: '<script>alert(1)</script>', fuentes: [
        { titulo: 'Manual', url: '/ayuda/pagos#registrar' },
        { titulo: 'Externo', url: 'https://evil.example/ayuda/pagos' },
        { titulo: 'Otro módulo', url: '/instituto/alumno' }
    ] });
    primera.onload();
    check(mensajes.children[1].children[1].textContent === '<script>alert(1)</script>', 'Respuesta como texto sin interpretar HTML');
    check(mensajes.children[1].children.length === 3 && !input.disabled, 'Solo enlaces del manual local y controles liberados');
    var pendiente = enviar('curso');
    elementos['ayuda-chat-limpiar'].click();
    pendiente.responseText = primera.responseText; pendiente.onload();
    check(pendiente.aborted && mensajes.children.length === 0, 'Borrar cancela y descarta respuestas tardías');
    var timeout = enviar('asistencia'); timeout.ontimeout();
    check(!input.disabled && estado.textContent.indexOf('reintentar') !== -1, 'Timeout permite reintentar');
    var error = enviar('pago'); error.responseText = '<html>Login</html>'; error.onload();
    check(!input.disabled && estado.textContent.indexOf('sesión') !== -1, 'Sesión vencida sin insertar página de login');
    return 'OK: consentimiento, CSRF, texto seguro, enlaces locales, duplicados, cancelación, timeout y sesión vencida';
}
if (typeof module !== 'undefined') module.exports = probarAyudaChat;
if (typeof require !== 'undefined' && require.main === module) {
    var fs = require('fs');
    console.log(probarAyudaChat(fs.readFileSync(require('path').join(__dirname, '../public/assets/js/ayuda-chat.js'), 'utf8'), URL));
}
