<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
        // ⭐ CORRECTION SÉCURITÉ : Définir ROOT_PATH avant d'inclure config.php
        define('ROOT_PATH', __DIR__);
        require_once 'config.php';
        initialize_session();
        $token = htmlspecialchars($_GET['token'] ?? '');
    ?>
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    <link rel="stylesheet" href="style.css">
    <title>Nouveau mot de passe - Support Descamps</title>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🎫 Support Descamps</div>
        </div>

        <div class="content">
            <div style="max-width:500px;margin:0 auto;">
                <h2 style="text-align:center;color:var(--gray-900);margin-bottom:30px;">Définir un nouveau mot de passe</h2>

                <div id="responseMsg" style="display:none; margin-bottom: 20px;"></div>

                <?php if (empty($token)): ?>
                    <div class="error-message">Jeton de réinitialisation manquant ou invalide.</div>
                <?php else: ?>
                    <form onsubmit="performReset(event)" id="resetForm">
                        <input type="hidden" id="token" value="<?php echo $token; ?>">
                        <div class="form-group">
                            <label>Nouveau mot de passe *</label>
                            <input type="password" id="password" required minlength="8" placeholder="8 caractères, 1 majuscule, 1 chiffre">
                        </div>
                        <div class="form-group">
                            <label>Confirmer le mot de passe *</label>
                            <input type="password" id="confirmPassword" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">Enregistrer</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        async function performReset(e) {
            e.preventDefault();
            const token = document.getElementById('token').value;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const form = document.getElementById('resetForm');
            const responseMsg = document.getElementById('responseMsg');

            // ⭐ SÉCURITÉ : Validation côté client (amélioration UX)
            if (password !== confirmPassword) {
                responseMsg.className = 'error-message';
                responseMsg.textContent = '❌ Les mots de passe ne correspondent pas.';
                responseMsg.style.display = 'block';
                return;
            }
            
            // ⭐ SÉCURITÉ : Validation de la politique de mot de passe côté client
            if (password.length < 8 || !/[A-Z]/.test(password) || !/[0-9]/.test(password)) {
                responseMsg.className = 'error-message';
                responseMsg.textContent = '❌ Le mot de passe doit contenir au moins 8 caractères, une majuscule et un chiffre.';
                responseMsg.style.display = 'block';
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = 'Enregistrement...';

            try {
                const res = await fetch('api.php?action=perform_password_reset', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ token, password })
                });

                const data = await res.json();
                
                if (data.success) {
                    responseMsg.className = 'success-message';
                    responseMsg.innerHTML = `✅ ${data.message} <br><a href='index.php'>Se connecter</a>`;
                    form.style.display = 'none';
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
                submitButton.textContent = 'Enregistrer';
            }
        }
    </script>
</body>
</html>