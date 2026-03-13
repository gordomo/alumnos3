document.addEventListener('DOMContentLoaded', function() {
    // Manejar apertura de modales de cursos (usar 'shown' en lugar de 'show')
    document.addEventListener('shown.bs.modal', function(e) {
        const modal = e.target;
        if (!modal.id || !modal.id.startsWith('cursosModal')) {
            return;
        }
        
        const alumnoId = modal.id.replace('cursosModal', '');
        const form = modal.querySelector('.curso-form');
        
        if (!form) {
            console.error('No se encontró el formulario en el modal');
            return;
        }
        
        // Buscar checkboxes directamente en el modal (no en el form)
        const checkboxes = modal.querySelectorAll('input[type="checkbox"][name="cursos[]"]');
        const comenzarDeudaContainer = document.getElementById(`comenzarDeudaContainer_${alumnoId}`);
        const comenzarDeudaCheckbox = document.getElementById(`comenzarDeudaProximoMes_${alumnoId}`);
        
        // Guardar cursos iniciales
        const cursosIniciales = new Set();
        checkboxes.forEach(cb => {
            if (cb.checked) {
                cursosIniciales.add(cb.value);
            }
        });
        
        // Función para verificar si hay cursos nuevos
        function verificarCursosNuevos() {
            let hayCursosNuevos = false;
            checkboxes.forEach(cb => {
                if (cb.checked && !cursosIniciales.has(cb.value)) {
                    hayCursosNuevos = true;
                }
            });
            
            if (comenzarDeudaContainer) {
                comenzarDeudaContainer.style.display = hayCursosNuevos ? 'block' : 'none';
                
                if (!hayCursosNuevos && comenzarDeudaCheckbox) {
                    comenzarDeudaCheckbox.checked = false;
                }
            }
        }
        
        // Escuchar cambios en los checkboxes
        checkboxes.forEach(cb => {
            cb.addEventListener('change', verificarCursosNuevos);
        });
        
        // Verificar al abrir
        verificarCursosNuevos();
    });
    
    // Manejar envío de formularios de cursos
    document.querySelectorAll('.curso-form').forEach(form => {
        const alumnoId = form.dataset.alumnoId;
        
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