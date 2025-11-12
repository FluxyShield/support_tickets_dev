<?php
define('ROOT_PATH', __DIR__);
require_once 'config.php';

// ⭐ CORRECTION : Initialiser la session pour obtenir le jeton CSRF
session_name('user_session');
initialize_session();

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("Jeton manquant.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <!-- ⭐ CORRECTION : Ajouter le jeton CSRF dans les balises meta -->
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
    <?php 
        // Fermer la session après avoir récupéré le jeton pour ne pas bloquer d'autres requêtes
        session_write_close(); 
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donner votre avis - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: var(--gray-50);
        }
        .review-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 40px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
        }
        .rating-stars {
            display: flex; flex-direction: row-reverse; 
            justify-content: center; gap: 10px; margin-bottom: 20px;
        }
        .rating-stars input { display: none; }
        .rating-stars label {
            font-size: 50px; color: var(--gray-200);
            cursor: pointer; transition: color 0.2s;
        }
        .rating-stars input:checked ~ label,
        .rating-stars label:hover,
        .rating-stars label:hover ~ label { color: #f59e0b; }
    </style>
</head>
<body>

    <!-- ⭐ CORRECTION : Ajouter l'en-tête pour la cohérence visuelle -->
    <div class="container" style="max-width: 800px;">
        <div class="header">
            <div class="logo">
                <img src="<?php echo APP_LOGO_URL; ?>" alt="<?php echo APP_NAME; ?> Logo" style="height: 40px; margin-right: 15px;">
                <?php echo APP_NAME; ?>
            </div>
        </div>
    </div>
    <div id="reviewContainer" class="review-container">
        <!-- Le contenu sera injecté par JavaScript -->
    </div>

    <script>
        const token = '<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>';
        const container = document.getElementById('reviewContainer');
        // ⭐ CORRECTION : Récupérer le jeton CSRF depuis la balise meta
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // Fonction helper pour les appels API, incluant le CSRF
        async function apiFetch(url, options = {}) {
            options.headers = options.headers || {};
            options.headers['X-CSRF-TOKEN'] = csrfToken;

            if (options.body && typeof options.body === 'object') {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(options.body);
            }

            return fetch(url, options);
        }

        async function loadReviewForm() {
            container.innerHTML = '<p>Chargement...</p>';
            try {
                const res = await fetch(`api.php?action=get_ticket_by_review_token&token=${token}`);
                const data = await res.json();

                if (data.success) {
                    const ticket = data.ticket;
                    if (ticket.review_id) {
                        container.innerHTML = `
                            <h2 style="color:var(--gray-900);">Merci !</h2>
                            <p style="color:var(--gray-600);margin:20px 0;">Vous avez déjà laissé un avis pour le ticket #${ticket.id}.</p>
                            <p style="font-size: 24px;">${'★'.repeat(ticket.review_rating)}${'☆'.repeat(5 - ticket.review_rating)}</p>
                        `;
                    } else {
                        container.innerHTML = `
                            <h2 style="color:var(--gray-900);">Votre avis sur le ticket #${ticket.id}</h2>
                            <p style="color:var(--gray-600);margin-bottom:30px;">"${ticket.subject}"</p>
                            <form onsubmit="submitReview(event)">
                                <div class="form-group">
                                    <div class="rating-stars">
                                        <input type="radio" id="star5" name="rating" value="5" required><label for="star5">★</label>
                                        <input type="radio" id="star4" name="rating" value="4"><label for="star4">★</label>
                                        <input type="radio" id="star3" name="rating" value="3"><label for="star3">★</label>
                                        <input type="radio" id="star2" name="rating" value="2"><label for="star2">★</label>
                                        <input type="radio" id="star1" name="rating" value="1"><label for="star1">★</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <textarea id="reviewComment" placeholder="Votre commentaire (optionnel)" style="min-height:120px;"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width:100%;">Envoyer mon avis</button>
                            </form>
                        `;
                    }
                } else {
                    container.innerHTML = `<h2 style="color:var(--danger);">Erreur</h2><p>${data.message}</p>`;
                }
            } catch (e) {
                container.innerHTML = `<h2 style="color:var(--danger);">Erreur</h2><p>Impossible de charger le formulaire d'avis.</p>`;
            }
        }

        async function submitReview(e) {
            e.preventDefault();
            const rating = document.querySelector('input[name="rating"]:checked');
            const comment = document.getElementById('reviewComment').value;

            if (!rating) {
                alert('Veuillez sélectionner une note.');
                return;
            }

            try {
                const res = await apiFetch('api.php?action=submit_review_by_token', {
                    method: 'POST',
                    body: {
                        token: token,
                        rating: parseInt(rating.value),
                        comment: comment
                    }
                });
                const data = await res.json();

                if (data.success) {
                    container.innerHTML = `
                        <h2 style="color:var(--success);">Merci pour votre avis !</h2>
                        <p style="color:var(--gray-600);margin-top:20px;">Vos retours nous sont précieux.</p>
                    `;
                } else {
                    alert('Erreur : ' + data.message);
                }
            } catch (e) {
                alert('Une erreur est survenue.');
            }
        }

        document.addEventListener('DOMContentLoaded', loadReviewForm);
    </script>

</body>
</html>