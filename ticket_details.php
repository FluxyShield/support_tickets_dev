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
        if (!isset($_SESSION['user_id'])) {
            header('Location: index.php');
            exit();
        }
    ?>
    <link rel="stylesheet" href="style.css">
    <title>Ticket créé - Support Descamps</title>
    <style>
        body {
            background: linear-gradient(135deg, #7C7C7B 0%, #4A4A49 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .success-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 700px;
            width: 100%;
            overflow: hidden;
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .success-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 50px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .success-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
        }

        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: checkmark 0.6s ease;
            position: relative;
            z-index: 1;
        }

        @keyframes checkmark {
            0% { transform: scale(0) rotate(-45deg); }
            50% { transform: scale(1.2) rotate(10deg); }
            100% { transform: scale(1) rotate(0deg); }
        }

        .success-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }

        .success-header p {
            font-size: 16px;
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }

        .success-body {
            padding: 40px;
        }

        .ticket-info-card {
            background: var(--gray-50);
            border-left: 4px solid var(--orange);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
        }

        .ticket-info-card h3 {
            color: var(--gray-900);
            font-size: 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 15px;
            align-items: start;
        }

        .info-label {
            font-weight: 600;
            color: var(--gray-700);
        }

        .info-value {
            color: var(--gray-900);
        }

        .ticket-id {
            font-size: 24px;
            font-weight: 700;
            color: var(--orange);
        }

        .badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .next-steps {
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .next-steps h3 {
            color: var(--gray-900);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .step {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            padding: 20px;
            background: var(--gray-50);
            border-radius: 12px;
            transition: all 0.3s;
        }

        .step:hover {
            background: rgba(239, 128, 0, 0.05);
            transform: translateX(5px);
        }

        .step:last-child {
            margin-bottom: 0;
        }

        .step-number {
            background: var(--orange);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            flex-shrink: 0;
        }

        .step-content {
            flex: 1;
        }

        .step-title {
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 5px;
            font-size: 16px;
        }

        .step-description {
            color: var(--gray-600);
            font-size: 14px;
            line-height: 1.5;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            flex: 1;
            min-width: 200px;
            justify-content: center;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .success-header {
                padding: 40px 30px;
            }

            .success-header h1 {
                font-size: 26px;
            }

            .success-icon {
                font-size: 60px;
            }

            .success-body {
                padding: 30px 20px;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .info-label {
                font-size: 13px;
            }

            .step {
                padding: 15px;
            }

            .step-number {
                width: 35px;
                height: 35px;
                font-size: 16px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
            }
        }

        /* Animation de progression */
        .progress-bar {
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 30px;
        }

        .progress-fill {
            height: 100%;
            background: var(--orange);
            width: 0%;
            animation: progressFill 2s ease forwards;
        }

        @keyframes progressFill {
            to { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-header">
            <div class="success-icon">✓</div>
            <h1>Ticket créé avec succès !</h1>
            <p>Votre demande a été enregistrée et sera traitée rapidement</p>
        </div>

        <div class="success-body">
            <!-- Informations du ticket -->
            <div class="ticket-info-card">
                <h3>🎫 Informations de votre ticket</h3>
                <div id="ticketInfo"></div>
            </div>

            <!-- Prochaines étapes -->
            <div class="next-steps">
                <h3>📋 Que se passe-t-il maintenant ?</h3>
                
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <div class="step-title">Confirmation par email</div>
                        <div class="step-description">
                            Vous allez recevoir un email de confirmation avec toutes les informations de votre ticket.
                        </div>
                    </div>
                </div>

                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <div class="step-title">Prise en charge</div>
                        <div class="step-description">
                            Notre équipe va analyser votre demande et vous répondra dans les plus brefs délais.
                        </div>
                    </div>
                </div>

                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <div class="step-title">Notification de réponse</div>
                        <div class="step-description">
                            Vous recevrez un email dès qu'une réponse sera disponible sur votre ticket.
                        </div>
                    </div>
                </div>

                <div class="step">
                    <div class="step-number">4</div>
                    <div class="step-content">
                        <div class="step-title">Suivi en temps réel</div>
                        <div class="step-description">
                            Connectez-vous à tout moment pour suivre l'avancement et échanger avec notre équipe.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Boutons d'action -->
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="location.href='index.php'">
                    Voir mes tickets
                </button>
            </div>

            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
        </div>
    </div>

    <script>
        // Récupérer l'ID du ticket depuis l'URL
        const urlParams = new URLSearchParams(window.location.search);
        const ticketId = urlParams.get('id');

        if (!ticketId) {
            window.location.href = 'index.php';
        }

        // Charger les détails du ticket
        loadTicketDetails();

        async function loadTicketDetails() {
            try {
                const res = await fetch(`api.php?action=get_ticket_details&id=${ticketId}`);
                const data = await res.json();

                if (data.success && data.ticket) {
                    displayTicketInfo(data.ticket);
                } else {
                    window.location.href = 'index.php';
                }
            } catch (error) {
                console.error('Erreur:', error);
                window.location.href = 'index.php';
            }
        }

        function displayTicketInfo(ticket) {
            const priorityColors = {
                'Haute': '#ef4444',
                'Moyenne': '#f59e0b',
                'Basse': '#6b7280'
            };

            const statusColors = {
                'Ouvert': '#3b82f6',
                'En cours': '#f59e0b',
                'Fermé': '#10b981'
            };

            const infoHtml = `
                <div class="info-grid">
                    <div class="info-label">Numéro :</div>
                    <div class="info-value">
                        <span class="ticket-id">#${ticket.id}</span>
                    </div>

                    <div class="info-label">Sujet :</div>
                    <div class="info-value">${ticket.subject}</div>

                    <div class="info-label">Catégorie :</div>
                    <div class="info-value">${ticket.category}</div>

                    <div class="info-label">Priorité :</div>
                    <div class="info-value">
                        <span class="badge" style="background-color: ${priorityColors[ticket.priority]}20; color: ${priorityColors[ticket.priority]};">
                            ${ticket.priority}
                        </span>
                    </div>

                    <div class="info-label">Statut :</div>
                    <div class="info-value">
                        <span class="badge" style="background-color: ${statusColors[ticket.status]}20; color: ${statusColors[ticket.status]};">
                            ${ticket.status}
                        </span>
                    </div>

                    <div class="info-label">Date de création :</div>
                    <div class="info-value">${ticket.date}</div>

                    <div class="info-label">Description :</div>
                    <div class="info-value" style="white-space: pre-wrap;">${ticket.description}</div>
                </div>
            `;

            document.getElementById('ticketInfo').innerHTML = infoHtml;
        }
    </script>
</body>
</html>