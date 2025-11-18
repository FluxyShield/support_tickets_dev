<?php
/**
 * @file login.php
 * @brief Page de connexion dédiée aux administrateurs.
 *
 * Cette page fournit une interface sécurisée pour que les administrateurs puissent se connecter
 * au panneau d'administration. Elle initialise une session, génère un jeton CSRF pour la
 * sécurité du formulaire et utilise JavaScript pour envoyer les identifiants de manière
 * asynchrone à l'API (endpoint `admin_login`).
 */

// ⭐ SOLUTION : Toute la logique PHP AVANT le HTML
define('ROOT_PATH', __DIR__);
require_once 'config.php';
session_name('admin_session');
initialize_session();

// ⭐ AMÉLIORATION : Si l'admin est déjà connecté, le rediriger vers admin.php
if (isset($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit();
}

// ⭐ Récupération du token CSRF APRÈS l'initialisation de session
$csrf_token = $_SESSION['csrf_token'] ?? '';

// ⭐ Libération de la session pour les performances
session_write_close();

// ⭐ Maintenant on peut afficher le HTML en toute sécurité
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <link rel="stylesheet" href="style.css">
    <title>Connexion Admin - Support Ticketing</title>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
        }
        .login-header {
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            color: white;
            padding: 40px;
            text-align: center;
            border-bottom: 4px solid var(--orange);
        }
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 5px;
            color: white;
        }
        .login-content {
            padding: 40px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>🎫 Support Ticketing</h1>
            <p>Connexion Administrateur</p>
        </div>
        
        <div class="login-content">
            <div id="errorMsg" class="error-message" style="display:none;"></div>

            <form id="loginForm" onsubmit="loginAdmin(event)">
                <div class="form-group">
                    <label>Adresse email</label>
                    <input type="email" id="email" required>
                </div>
                <div class="form-group">
                    <label>Mot de passe</label>
                    <input type="password" id="password" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Se connecter</button>
            </form>
            
            <div style="text-align:center;margin-top:20px;">
                <a href="forgot_password.php" style="color:var(--primary);text-decoration:none;">Mot de passe oublié ?</a>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        async function loginAdmin(e) {
            e.preventDefault();
            
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            try {
                const res = await fetch('api.php?action=admin_login', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ email, password })
                });

                const data = await res.json();

                if (data.success) {
                    // ⭐ AMÉLIORATION : Plus besoin de localStorage, la session PHP gère tout
                    window.location.href = 'admin.php';
                } else {
                    showError(data.message);
                }
            } catch (error) {
                console.error('Erreur:', error);
                showError('Erreur de connexion au serveur');
            }
        }

        function showError(message) {
            const errorDiv = document.getElementById('errorMsg');
            errorDiv.textContent = '❌ ' + message;
            errorDiv.style.display = 'block';
            
            setTimeout(() => errorDiv.style.display = 'none', 5000);
        }
    </script>
</body>
</html>