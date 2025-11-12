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
    <!-- ⭐ AJOUT : Jeton CSRF pour la sécurité des requêtes POST -->
    <!-- NOTE: Pour que cela fonctionne, cette page doit être un .php qui appelle initialize_session() -->
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <title>Inscription - Support Descamps</title>
    <style>
        /* Animation de création de compte */
        @keyframes successPulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.1);
                opacity: 0.8;
            }
        }

        .success-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }

        .success-animation.active {
            display: flex;
        }

        .success-box {
            background: white;
            padding: 50px;
            border-radius: 20px;
            text-align: center;
            animation: successPulse 2s ease-in-out infinite;
        }

        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }

        .progress-bar {
            width: 100%;
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 20px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
            width: 0%;
            transition: width 2s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🎫 Support Descamps</div>
            <div class="nav-buttons">
                <button class="btn btn-secondary" onclick="location.href='index.php'">Retour</button>
            </div>
        </div>

        <div class="content">
            <h2 style="text-align:center;color:var(--gray-900);margin-bottom:30px;">Créer un compte</h2>

            <div style="max-width:500px;margin:0 auto;">
                <div id="errorMsg" class="error-message" style="display:none;"></div>

                <form onsubmit="register(event)" id="registerForm">
                    <div class="form-group">
                        <label>Prénom *</label>
                        <input type="text" id="firstname" required minlength="2" placeholder="Votre prénom">
                    </div>

                    <div class="form-group">
                        <label>Nom *</label>
                        <input type="text" id="lastname" required minlength="2" placeholder="Votre nom">
                    </div>

                    <div class="form-group">
                        <label>Adresse email *</label>
                        <input type="email" id="email" required placeholder="votre@email.com">
                    </div>

                    <div class="form-group">
                        <label>Mot de passe *</label>
                        <input type="password" id="password" required minlength="8" placeholder="8 caractères, 1 majuscule, 1 chiffre">
                        <small style="color:var(--gray-600);">Minimum 8 caractères, incluant une majuscule et un chiffre.</small>
                    </div>

                    <div class="form-group">
                        <label>Confirmer le mot de passe *</label>
                        <input type="password" id="confirmPassword" required placeholder="Confirmez votre mot de passe">
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;">Créer mon compte</button>
                </form>

                <div style="text-align:center;margin-top:20px;">
                    <p style="color:var(--gray-600);">
                        Déjà un compte ? <a href="index.php" style="color:var(--primary);text-decoration:none;">Se connecter</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Animation de succès -->
    <div id="successAnimation" class="success-animation">
        <div class="success-box">
            <div class="success-icon">🎉</div>
            <h2 style="color:var(--gray-900);margin-bottom:10px;">Compte créé avec succès !</h2>
            <p style="color:var(--gray-600);margin-bottom:20px;">Redirection vers votre espace...</p>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
        </div>
    </div>
    <script>
async function register(e) {
    e.preventDefault();

    const firstname = document.getElementById('firstname').value.trim();
    const lastname = document.getElementById('lastname').value.trim();
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirmPassword').value;

    // ⭐ AJOUT : Récupérer le jeton CSRF depuis la balise meta
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // Validation côté client
    if (password !== confirmPassword) {
        showError('Les mots de passe ne correspondent pas');
        return;
    }

    if (password.length < 8 || !/[A-Z]/.test(password) || !/[0-9]/.test(password)) {
        showError('Le mot de passe doit contenir au moins 8 caractères, une majuscule et un chiffre.');
        return;
    }

    if (firstname.length < 2 || lastname.length < 2) {
        showError('Le prénom et le nom doivent contenir au moins 2 caractères');
        return;
    }

    try {
        const res = await fetch('api.php?action=register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken // ⭐ AJOUT : Envoyer le jeton CSRF
            },
            body: JSON.stringify({ firstname, lastname, email, password, confirmPassword })
        });

        let data;
        try {
            data = await res.json();
        } catch (parseError) {
            console.error('Erreur de parsing JSON:', parseError);
            showError('Erreur serveur: réponse non valide - vérifiez la console');
            return;
        }

        if (data.success) {
            localStorage.setItem('firstname', data.user.firstname);
            localStorage.setItem('lastname', data.user.lastname);
            localStorage.setItem('email', data.user.email);
            localStorage.setItem('user_id', data.user.id);
            showSuccessAnimation();
        } else {
            showError(data.message);
        }
    } catch (error) {
        console.error('Erreur réseau:', error);
        showError('Erreur de connexion au serveur: ' + error.message);
    }
}

        function showSuccessAnimation() {
            const animation = document.getElementById('successAnimation');
            const progressFill = document.getElementById('progressFill');
            
            animation.classList.add('active');
            
            // Animer la barre de progression
            setTimeout(() => {
                progressFill.style.width = '100%';
            }, 100);

            // Rediriger après 2 secondes
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 2000);
        }

        function showError(message) {
            const errorDiv = document.getElementById('errorMsg');
            errorDiv.textContent = '❌ ' + message;
            errorDiv.style.display = 'block';

            // Masquer après 5 secondes
            setTimeout(() => {
                errorDiv.style.display = 'none';
            }, 5000);
        }
    </script>
</body>
</html>