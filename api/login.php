<!DOCTYPE html>
<html lang="fr">
<head>
    <?php
        require_once 'config.php';
        initialize_session();
    ?>
    <!-- Jeton CSRF pour une éventuelle protection future sur cette page -->
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
    <title>Connexion Admin - Support Ticketing</title>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
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
        }
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 5px;
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
            <p>Administration</p>
        </div>
        
        <div class="login-content">
            <div id="errorMsg" class="error-message" style="display:none;"></div>
            <div id="successMsg" class="success-message" style="display:none;"></div>

            <div id="loginTab" class="tab-content active">
                <form onsubmit="login(event)">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="loginEmail" required>
                    </div>
                    <div class="form-group">
                        <label>Mot de passe</label>
                        <input type="password" id="loginPassword" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Se connecter</button>
                </form>
            </div>

            <div style="text-align:center;margin-top:20px;">
                <button class="btn btn-secondary" style="width:100%;" onclick="location.href='index.php'">
                    Retour à l'accueil
                </button>
            </div>
        </div>
    </div>

    <script>
        async function login(e) {
            e.preventDefault();
            
            const email = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value;

            try {
                const res = await fetch('api.php?action=admin_login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password })
                });

                const data = await res.json();

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
            document.getElementById('successMsg').style.display = 'none';
            
            setTimeout(() => errorDiv.style.display = 'none', 5000);
        }

        function showSuccess(message) {
            const successDiv = document.getElementById('successMsg');
            successDiv.textContent = '✅ ' + message;
            successDiv.style.display = 'block';
            document.getElementById('errorMsg').style.display = 'none';
        }

        function hideMessages() {
            document.getElementById('errorMsg').style.display = 'none';
            document.getElementById('successMsg').style.display = 'none';
        }
    </script>
</body>
</html>