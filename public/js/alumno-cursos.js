document.addEventListener('DOMContentLoaded', function() {
    // Manejar el envío del formulario de cursos
    const cursoForms = document.querySelectorAll('.curso-form');
    cursoForms.forEach(form => {
        const alumnoId = form.dataset.alumnoId;
        const checkboxes = form.querySelectorAll('input[type="checkbox"][name="cursos[]"]');
        const comenzarDeudaContainer = document.getElementById(`comenzarDeudaContainer_${alumnoId}`);
        const comenzarDeudaCheckbox = document.getElementById(`comenzarDeudaProximoMes_${alumnoId}`);
        
        // Guardar el estado inicial de los cursos
        const cursosIniciales = new Set();
        checkboxes.forEach(cb => {
            if (cb.checked) {
                cursosIniciales.add(cb.value);
            }
        });
        
        // Función para verificar si hay cursos nuevos siendo agregados
        function verificarCursosNuevos() {
            let hayCursosNuevos = false;
            checkboxes.forEach(cb => {
                // Si está marcado ahora pero no estaba inicialmente, es un curso nuevo
                if (cb.checked && !cursosIniciales.has(cb.value)) {
                    hayCursosNuevos = true;
                }
            });
            
            // Mostrar/ocultar el checkbox según si hay cursos nuevos
            if (comenzarDeudaContainer) {
                comenzarDeudaContainer.style.display = hayCursosNuevos ? 'block' : 'none';
                // Desmarcar si se oculta
                if (!hayCursosNuevos && comenzarDeudaCheckbox) {
                    comenzarDeudaCheckbox.checked = false;
                }
            }
        }
        
        // Verificar cuando cambian los checkboxes
        checkboxes.forEach(cb => {
            cb.addEventListener('change', verificarCursosNuevos);
        });
        
        // Verificar al abrir el modal
        verificarCursosNuevos();
        
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(`/instituto/alumno/${alumnoId}/update-cursos`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Cerrar el modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById(`cursosModal${alumnoId}`));
                    modal.hide();
                    
                    // Recargar la página para mostrar los cambios
                    window.location.reload();
                } else {
                    alert('Error al actualizar los cursos');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al actualizar los cursos');
            });
        });
    });
}); 