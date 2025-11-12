<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
        // ⭐ CORRECTION SÉCURITÉ : Définir ROOT_PATH et initialiser la session pour le CSRF.
        define('ROOT_PATH', __DIR__);
        require_once 'config.php';
        initialize_session();
    ?>
    <!-- Jeton CSRF généré de manière fiable -->
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
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

    <script>
        // ⭐ CORRECTION SÉCURITÉ : Lire le jeton CSRF depuis la balise meta.
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
                        // Pas de CSRF token ici, car c'est une action publique (anonyme)
                    },
                    body: JSON.stringify({ email, password })
                });

                const data = await res.json(); // Cette ligne ne devrait plus causer d'erreur.

                if (data.success) {
                    localStorage.setItem('admin_firstname', data.user.firstname);
                    localStorage.setItem('admin_lastname', data.user.lastname);
                    localStorage.setItem('admin_email', data.user.email);
                    localStorage.setItem('admin_id', data.user.id);
                    
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
    <?php session_write_close(); // Libère la session pour les autres requêtes ?>
</body>
</html>