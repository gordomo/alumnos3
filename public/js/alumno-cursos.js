document.addEventListener('DOMContentLoaded', function() {
    // Manejar el envío del formulario de cursos
    const cursoForms = document.querySelectorAll('.curso-form');
    cursoForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const alumnoId = this.dataset.alumnoId;
            
            fetch(`/admin/alumno/${alumnoId}/update-cursos`, {
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