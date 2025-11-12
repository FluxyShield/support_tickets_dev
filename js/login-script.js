/**
 * Script pour la page de connexion utilisateur (login.php)
 */

document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', handleLogin);
    }
});

async function handleLogin(event) {
    event.preventDefault();

    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    const errorDiv = document.getElementById('errorMsg');
    const successDiv = document.getElementById('successMsg');

    // Cacher les anciens messages
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';

    try {
        const response = await fetch('api.php?action=login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ email, password })
        });

        const data = await response.json();

        if (data.success) {
            // Stocker les informations utilisateur et rediriger
            localStorage.setItem('user_id', data.user.id);
            localStorage.setItem('user_firstname', data.user.firstname);
            window.location.href = 'index.php';
        } else {
            // Afficher l'erreur dans la div, et non plus avec une alerte
            errorDiv.textContent = '❌ ' + data.message;
            errorDiv.style.display = 'block';
        }

    } catch (error) {
        console.error('Erreur de connexion:', error);
        errorDiv.textContent = '❌ Erreur de connexion au serveur. Veuillez réessayer.';
        errorDiv.style.display = 'block';
    }
}