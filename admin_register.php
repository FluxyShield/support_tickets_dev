<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <?php
        // ⭐ CORRECTION SÉCURITÉ : Définir ROOT_PATH avant d'inclure config.php
        define('ROOT_PATH', __DIR__);
        require_once 'config.php';
        initialize_session();
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Finaliser l'inscription Admin - Support Ticketing</title>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .register-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
        }
        .register-header {
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .register-header h1 {
            font-size: 28px;
            margin-bottom: 5px;
            color: white; /* S'assurer que le h1 est blanc */
        }
        .register-content {
            padding: 40px;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h1>🎫 Support Ticketing</h1>
            <p>Finaliser l'inscription Administrateur</p>
        </div>
        
        <div class="register-content">
            <div id="errorMsg" class="error-message" style="display:none;"></div>
            <div id="successMsg" class="success-message" style="display:none;"></div>

            <form id="registerForm" onsubmit="registerAdmin(event)">
                <div class="form-group">
                    <label>Prénom *</label>
                    <input type="text" id="firstname" required minlength="2">
                </div>
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" id="lastname" required minlength="2">
                </div>
                <div class="form-group">
                    <label>Nouveau mot de passe *</label>
                    <input type="password" id="password" required minlength="8" placeholder="8+ caractères, 1 majuscule, 1 chiffre">
                </div>
                <div class="form-group">
                    <label>Confirmer le mot de passe *</label>
                    <input type="password" id="confirmPassword" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Créer mon compte</button>
            </form>
            
            <div id="loading" style="text-align:center;padding:20px;display:none;">
                <p>Vérification...</p>
            </div>
            
            <div id="postRegister" style="text-align:center;margin-top:20px;display:none;">
                <button class="btn btn-secondary" style="width:100%;" onclick="location.href='login.php'">
                    Retour à la connexion
                </button>
            </div>
        </div>
    </div>

    <script>
        let urlToken = '';

        // ⭐ AMÉLIORATION SÉCURITÉ : Validation du jeton au chargement de la page
        document.addEventListener('DOMContentLoaded', async () => {
            const params = new URLSearchParams(window.location.search);
            if (!params.has('token')) {
                // Si aucun token, redirection vers la page de connexion admin
                window.location.href = 'login.php';
                return;
            }
            urlToken = params.get('token');

            // Afficher le chargement pendant la vérification
            // La validation se fait maintenant côté serveur, on peut enlever le pré-chargement

            // Appeler notre nouveau script de validation
            const res = await fetch(`validate_token.php?token=${encodeURIComponent(urlToken)}`);
            const data = await res.json();

            if (!data.valid) {
                // ⭐ SOLUTION : Au lieu de rediriger, on affiche une erreur claire sur la page.
                const form = document.getElementById('registerForm');
                const errorDiv = document.getElementById('errorMsg');
                form.style.display = 'none'; // Cacher le formulaire
                errorDiv.innerHTML = '❌ Ce lien d\'invitation est invalide ou a expiré. <br>Veuillez contacter un administrateur pour recevoir une nouvelle invitation.';
                errorDiv.style.display = 'block';
                document.getElementById('postRegister').style.display = 'block'; // Afficher le bouton de retour
            } else {
                // Le token est valide, on affiche le formulaire
            }
        });

        async function registerAdmin(e) {
            e.preventDefault();
            
            const firstname = document.getElementById('firstname').value.trim();
            const lastname = document.getElementById('lastname').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (password !== confirmPassword) {
                showError('Les mots de passe ne correspondent pas');
                return;
            }
            if (password.length < 8 || !/[A-Z]/.test(password) || !/[0-9]/.test(password)) {
                showError('Le mot de passe doit contenir au moins 8 caractères, une majuscule et un chiffre.');
                return;
            }
            
            showLoading(true);

            try {
                const res = await fetch('api.php?action=admin_register_complete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        firstname, 
                        lastname, 
                        password, 
                        token: urlToken 
                    })
                });

                const data = await res.json();
                showLoading(false);

                if (data.success) {
                    showSuccess(data.message);
                    document.getElementById('registerForm').style.display = 'none';
                    document.getElementById('postRegister').style.display = 'block';
                } else {
                    showError(data.message);
                }
            } catch (error) {
                showLoading(false);
                console.error('Erreur:', error);
                showError('Erreur de connexion au serveur');
            }
        }
        
        function showLoading(isLoading) {
             document.getElementById('loading').style.display = isLoading ? 'block' : 'none';
             document.getElementById('registerForm').style.display = isLoading ? 'none' : 'block';
        }

        function showError(message) {
            const errorDiv = document.getElementById('errorMsg');
            errorDiv.textContent = '❌ ' + message;
            errorDiv.style.display = 'block';
            document.getElementById('successMsg').style.display = 'none';
        }

        function showSuccess(message) {
            const successDiv = document.getElementById('successMsg');
            successDiv.textContent = '✅ ' + message;
            successDiv.style.display = 'block';
            document.getElementById('errorMsg').style.display = 'none';
        }
    </script>
</body>
</html>