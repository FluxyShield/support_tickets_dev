<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <?php
        // ⭐ CORRECTION SÉCURITÉ : Définir ROOT_PATH avant d'inclure config.php
        define('ROOT_PATH', __DIR__);
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
        require_once 'config.php';
        initialize_session();
    ?>
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    <link rel="stylesheet" href="style.css">
    <title>Mot de passe oublié - Support Ticketing</title>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🎫 Support Ticketing</div>
            <div class="nav-buttons">
                <button class="btn btn-secondary" onclick="location.href='index.php'">Retour à l'accueil</button>
            </div>
        </div>

        <div class="content">
            <div style="max-width:500px;margin:0 auto;text-align:center;">
                <h2 style="color:var(--gray-900);margin-bottom:20px;">Réinitialiser votre mot de passe</h2>
                <p style="color:var(--gray-600);margin-bottom:30px;">Entrez votre adresse email et nous vous enverrons un lien pour réinitialiser votre mot de passe.</p>

                <div id="responseMsg" style="display:none; margin-bottom: 20px;"></div>

                <form onsubmit="requestReset(event)" id="resetForm">
                    <div class="form-group" style="text-align:left;">
                        <label>Adresse email</label>
                        <input type="email" id="email" required placeholder="votre@email.com">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Envoyer le lien</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        async function requestReset(e) {
            e.preventDefault();
            const email = document.getElementById('email').value;
            const form = document.getElementById('resetForm');
            const responseMsg = document.getElementById('responseMsg');

            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = 'Envoi en cours...';

            try {
                const res = await fetch('api.php?action=request_password_reset', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ email })
                });

                const data = await res.json();
                
                if (data.success) {
                    responseMsg.className = 'success-message';
                    responseMsg.textContent = '✅ ' + data.message;
                    form.style.display = 'none'; // Cacher le formulaire après succès
                } else {
                    responseMsg.className = 'error-message';
                    responseMsg.textContent = '❌ ' + data.message;
                }
                responseMsg.style.display = 'block';

            } catch (error) {
                responseMsg.className = 'error-message';
                responseMsg.textContent = '❌ Erreur de connexion au serveur.';
                responseMsg.style.display = 'block';
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'Envoyer le lien';
            }
        }
    </script>
</body>
</html>