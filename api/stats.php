<?php
/**
 * ===================================================================
 * API - STATISTIQUES AVANCÉES (api/stats.php)
 * ===================================================================
 * Dashboard professionnel avec KPIs, graphiques et analyses
 * ===================================================================
 */
if (!defined('ROOT_PATH')) {
    die('Accès direct non autorisé');
}

// Vérifier que config.php est bien chargé
if (!function_exists('sendEmail')) {
    require_once ROOT_PATH . '/config.php';
}

/**
 * 📊 Récupère toutes les statistiques pour le dashboard
 */
function get_advanced_stats() {
    requireAuth('admin');
    $db = Database::getInstance()->getConnection();
    
    // Récupérer la période depuis les paramètres (par défaut : 30 jours)
    $period = $_GET['period'] ?? '30';
    $date_from = date('Y-m-d H:i:s', strtotime("-{$period} days"));
    
    $stats = [
        'kpis' => getKPIs($db),
        'timeline' => getTimelineData($db, $date_from),
        'categories' => getCategoryDistribution($db),
        'priorities' => getPriorityDistribution($db),
        'admins_performance' => getAdminsPerformance($db),
        'satisfaction' => getSatisfactionStats($db),
        'response_times' => getResponseTimes($db),
        'peak_hours' => getPeakHours($db),
        'top_categories' => getTopCategories($db, 5),
        'unassigned' => getUnassignedTickets($db),
        'trends' => getTrends($db),
        // Nouvelles stats avancées
        'forecast' => getForecastData($db),
        'keywords' => getKeywordAnalysis($db),
        'agent_load' => getAgentLoad($db),
        'correlation' => getSatisfactionCorrelation($db)
    ];
    
    jsonResponse(true, 'Statistiques récupérées', $stats);
}

/**
 * 🎯 KPIs Principaux
 */
function getKPIs($db) {
    // Total tickets
    $total = $db->query("SELECT COUNT(*) as count FROM tickets")->fetch_assoc()['count'];
    
    // Tickets par statut
    $open = $db->query("SELECT COUNT(*) as count FROM tickets WHERE status = 'Ouvert'")->fetch_assoc()['count'];
    $in_progress = $db->query("SELECT COUNT(*) as count FROM tickets WHERE status = 'En cours'")->fetch_assoc()['count'];
    $closed = $db->query("SELECT COUNT(*) as count FROM tickets WHERE status = 'Fermé'")->fetch_assoc()['count'];
    
    // Taux de satisfaction (moyenne des avis)
    $satisfaction_query = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM ticket_reviews";
    $satisfaction_result = $db->query($satisfaction_query)->fetch_assoc();
    $avg_satisfaction = round($satisfaction_result['avg_rating'] ?? 0, 1);
    
    // Temps de résolution moyen (en heures)
    $avg_resolution_query = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as avg_hours 
                            FROM tickets WHERE status = 'Fermé' AND closed_at IS NOT NULL";
    $avg_resolution = round($db->query($avg_resolution_query)->fetch_assoc()['avg_hours'] ?? 0, 1);
    
    // Tickets non assignés
    $unassigned = $db->query("SELECT COUNT(*) as count FROM tickets WHERE assigned_to IS NULL AND status != 'Fermé'")->fetch_assoc()['count'];
    
    return [
        'total_tickets' => (int)$total,
        'open_tickets' => (int)$open,
        'in_progress_tickets' => (int)$in_progress,
        'closed_tickets' => (int)$closed,
        'satisfaction_rate' => (float)$avg_satisfaction,
        'avg_resolution_time' => (float)$avg_resolution,
        'unassigned_tickets' => (int)$unassigned,
        'total_reviews' => (int)$satisfaction_result['total_reviews']
    ];
}

/**
 * 📈 Évolution temporelle des tickets
 */
function getTimelineData($db, $date_from) {
    $query = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'Ouvert' THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN status = 'Fermé' THEN 1 ELSE 0 END) as closed
              FROM tickets 
              WHERE created_at >= ?
              GROUP BY DATE(created_at)
              ORDER BY date ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param("s", $date_from);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $timeline = [];
    while ($row = $result->fetch_assoc()) {
        $timeline[] = [
            'date' => $row['date'],
            'total' => (int)$row['total'],
            'opened' => (int)$row['opened'],
            'closed' => (int)$row['closed']
        ];
    }
    
    return $timeline;
}

/**
 * 🎨 Distribution par catégorie
 */
function getCategoryDistribution($db) {
    $result = $db->query("SELECT category_encrypted, COUNT(*) as count FROM tickets GROUP BY category_encrypted");
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $category = decrypt($row['category_encrypted']);
        $categories[] = [
            'name' => $category,
            'count' => (int)$row['count']
        ];
    }
    
    return $categories;
}

/**
 * 🎯 Distribution par priorité
 */
function getPriorityDistribution($db) {
    $result = $db->query("SELECT priority, COUNT(*) as count FROM tickets GROUP BY priority");
    
    $priorities = [];
    while ($row = $result->fetch_assoc()) {
        $priority = $row['priority'];
        $priorities[] = [
            'name' => $priority,
            'count' => (int)$row['count']
        ];
    }
    
    return $priorities;
}

/**
 * 👥 Performance des admins
 */
function getAdminsPerformance($db) {
    $query = "SELECT 
                u.id,
                u.firstname_encrypted,
                u.lastname_encrypted,
                COUNT(t.id) as total_assigned,
                SUM(CASE WHEN t.status = 'Fermé' THEN 1 ELSE 0 END) as resolved,
                AVG(CASE WHEN t.status = 'Fermé' THEN TIMESTAMPDIFF(HOUR, t.assigned_at, t.closed_at) END) as avg_resolution_time
              FROM users u
              LEFT JOIN tickets t ON u.id = t.assigned_to
              WHERE u.role = 'admin'
              GROUP BY u.id
              ORDER BY resolved DESC";
    
    $result = $db->query($query);
    
    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = [
            'id' => (int)$row['id'],
            'name' => decrypt($row['firstname_encrypted']) . ' ' . decrypt($row['lastname_encrypted']),
            'total_assigned' => (int)$row['total_assigned'],
            'resolved' => (int)$row['resolved'],
            'avg_resolution_time' => round($row['avg_resolution_time'] ?? 0, 1)
        ];
    }
    
    return $admins;
}

/**
 * ⭐ Statistiques de satisfaction
 */
function getSatisfactionStats($db) {
    $query = "SELECT 
                rating,
                COUNT(*) as count
              FROM ticket_reviews
              GROUP BY rating
              ORDER BY rating DESC";
    
    $result = $db->query($query);
    
    $satisfaction = [];
    for ($i = 5; $i >= 1; $i--) {
        $satisfaction[$i] = 0;
    }
    
    while ($row = $result->fetch_assoc()) {
        $satisfaction[(int)$row['rating']] = (int)$row['count'];
    }
    
    return $satisfaction;
}

/**
 * ⏱️ Temps de réponse moyen
 */
function getResponseTimes($db) {
    // Temps de première réponse (admin)
    $first_response_query = "SELECT AVG(TIMESTAMPDIFF(MINUTE, t.created_at, m.created_at)) as avg_minutes
                             FROM tickets t
                             INNER JOIN messages m ON t.id = m.ticket_id
                             WHERE m.author_role = 'admin'
                             AND m.id = (
                                 SELECT MIN(id) FROM messages 
                                 WHERE ticket_id = t.id AND author_role = 'admin'
                             )";
    
    $first_response = $db->query($first_response_query)->fetch_assoc()['avg_minutes'] ?? 0;
    
    return [
        'first_response_avg' => round($first_response, 0), // en minutes
        'first_response_hours' => round($first_response / 60, 1) // en heures
    ];
}

/**
 * 🕐 Heures de pointe (affluence)
 */
function getPeakHours($db) {
    $query = "SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as count
              FROM tickets
              GROUP BY HOUR(created_at)
              ORDER BY hour ASC";
    
    $result = $db->query($query);
    
    $hours = array_fill(0, 24, 0);
    while ($row = $result->fetch_assoc()) {
        $hours[(int)$row['hour']] = (int)$row['count'];
    }
    
    return $hours;
}

/**
 * 🏆 Top catégories
 */
function getTopCategories($db, $limit = 5) {
    $query = "SELECT category_encrypted, COUNT(*) as count 
              FROM tickets 
              GROUP BY category_encrypted 
              ORDER BY count DESC 
              LIMIT ?";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $top = [];
    while ($row = $result->fetch_assoc()) {
        $top[] = [
            'category' => decrypt($row['category_encrypted']),
            'count' => (int)$row['count']
        ];
    }
    
    return $top;
}

/**
 * 📌 Tickets non assignés
 */
function getUnassignedTickets($db) {
    $query = "SELECT 
                id,
                subject_encrypted,
                priority,
                created_at
              FROM tickets
              WHERE assigned_to IS NULL AND status != 'Fermé'
              ORDER BY created_at ASC
              LIMIT 10";
    
    $result = $db->query($query);
    
    $unassigned = [];
    while ($row = $result->fetch_assoc()) {
        $unassigned[] = [
            'id' => (int)$row['id'],
            'subject' => decrypt($row['subject_encrypted']),
            'priority' => $row['priority'],
            'waiting_time' => round((time() - strtotime($row['created_at'])) / 3600, 1) // heures
        ];
    }
    
    return $unassigned;
}

/**
 * 📊 Tendances (comparaison période actuelle vs précédente)
 */
function getTrends($db) {
    // 30 derniers jours
    $current_period_start = date('Y-m-d H:i:s', strtotime('-30 days'));
    $previous_period_start = date('Y-m-d H:i:s', strtotime('-60 days'));
    $previous_period_end = date('Y-m-d H:i:s', strtotime('-30 days'));
    
    // ⭐ SÉCURITÉ : Utiliser des requêtes préparées pour éviter l'injection SQL
    // Tickets créés - période actuelle
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM tickets WHERE created_at >= ?");
    $stmt->bind_param("s", $current_period_start);
    $stmt->execute();
    $current_created = $stmt->get_result()->fetch_assoc()['count'];
    
    // Tickets créés - période précédente
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM tickets WHERE created_at >= ? AND created_at < ?");
    $stmt->bind_param("ss", $previous_period_start, $previous_period_end);
    $stmt->execute();
    $previous_created = $stmt->get_result()->fetch_assoc()['count'];
    
    // Calcul de la variation
    $created_variation = $previous_created > 0 ? round((($current_created - $previous_created) / $previous_created) * 100, 1) : 0;
    
    // ⭐ SÉCURITÉ : Utiliser des requêtes préparées pour éviter l'injection SQL
    // Tickets résolus - période actuelle
    $status_closed = 'Fermé';
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM tickets WHERE status = ? AND closed_at >= ?");
    $stmt->bind_param("ss", $status_closed, $current_period_start);
    $stmt->execute();
    $current_resolved = $stmt->get_result()->fetch_assoc()['count'];
    
    // Tickets résolus - période précédente
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM tickets WHERE status = ? AND closed_at >= ? AND closed_at < ?");
    $stmt->bind_param("sss", $status_closed, $previous_period_start, $previous_period_end);
    $stmt->execute();
    $previous_resolved = $stmt->get_result()->fetch_assoc()['count'];
    
    $resolved_variation = $previous_resolved > 0 ? round((($current_resolved - $previous_resolved) / $previous_resolved) * 100, 1) : 0;
    
    return [
        'created' => [
            'current' => (int)$current_created,
            'previous' => (int)$previous_created,
            'variation' => (float)$created_variation
        ],
        'resolved' => [
            'current' => (int)$current_resolved,
            'previous' => (int)$previous_resolved,
            'variation' => (float)$resolved_variation
        ]
    ];
}

/**
 * 🔮 Prédictions de Volume (Forecast)
 * Régression linéaire simple sur les 30 derniers jours pour prédire les 7 prochains
 */
function getForecastData($db) {
    // Récupérer les données des 30 derniers jours
    $query = "SELECT DATE(created_at) as date, COUNT(*) as count 
              FROM tickets 
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              GROUP BY DATE(created_at) 
              ORDER BY date ASC";
    $result = $db->query($query);
    
    $data = [];
    $x = []; // Jours (0, 1, 2...)
    $y = []; // Nombre de tickets
    $i = 0;
    
    while ($row = $result->fetch_assoc()) {
        $data[] = ['date' => $row['date'], 'count' => (int)$row['count']];
        $x[] = $i++;
        $y[] = (int)$row['count'];
    }
    
    // Si pas assez de données, pas de prédiction
    if (count($data) < 5) return [];
    
    // Calcul Régression Linéaire : y = mx + b
    $n = count($x);
    $sumX = array_sum($x);
    $sumY = array_sum($y);
    $sumXY = 0;
    $sumXX = 0;
    
    for ($j = 0; $j < $n; $j++) {
        $sumXY += $x[$j] * $y[$j];
        $sumXX += $x[$j] * $x[$j];
    }
    
    $denominator = ($n * $sumXX) - ($sumX * $sumX);
    if ($denominator == 0) return []; // Éviter division par zéro
    
    $m = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
    $b = ($sumY - ($m * $sumX)) / $n;
    
    // Générer les prédictions pour les 7 prochains jours
    $forecast = [];
    $lastDate = end($data)['date'];
    
    for ($k = 1; $k <= 7; $k++) {
        $nextDayIndex = $n - 1 + $k;
        $predictedValue = ($m * $nextDayIndex) + $b;
        $predictedValue = max(0, round($predictedValue)); // Pas de tickets négatifs
        
        $forecast[] = [
            'date' => date('Y-m-d', strtotime("$lastDate +$k days")),
            'count' => (int)$predictedValue
        ];
    }
    
    return $forecast;
}

/**
 * 🧠 Analyse Sémantique (Mots-clés fréquents)
 */
function getKeywordAnalysis($db) {
    $query = "SELECT subject_encrypted FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
    $result = $db->query($query);
    
    $text = "";
    while ($row = $result->fetch_assoc()) {
        $text .= " " . strtolower(decrypt($row['subject_encrypted']));
    }
    
    // Liste basique de "stop words" français à ignorer
    $stopWords = ['le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'à', 'en', 'pour', 'sur', 'dans', 'au', 'aux', 'ce', 'cette', 'ces', 'mon', 'ma', 'mes', 'je', 'tu', 'il', 'elle', 'nous', 'vous', 'ils', 'elles', 'pas', 'ne', 'que', 'qui', 'quoi', 'ou', 'où', 'est', 'sont', 'avoir', 'être', 'faire', 'ticket', 'problème', 'bug', 'erreur', 'bonjour', 'merci', 'svp', 'urgent', 'test', 'aide', 'besoin', 'question', 'demande', 'souci', 'impossible', 'marche', 'fonctionne', 'plus', 'très', 'trop', 'avec', 'sans', 'sous', 'par'];
    
    // Tokenisation simple
    $words = str_word_count($text, 1, 'àâäéèêëîïôöùûüç1234567890');
    $wordCounts = array_count_values($words);
    
    // Filtrer et trier
    $filtered = [];
    foreach ($wordCounts as $word => $count) {
        if (strlen($word) > 3 && !in_array($word, $stopWords)) {
            $filtered[$word] = $count;
        }
    }
    
    arsort($filtered);
    
    // Top 20
    $topKeywords = [];
    $i = 0;
    foreach ($filtered as $word => $count) {
        if ($i++ >= 20) break;
        $topKeywords[] = ['word' => $word, 'count' => $count];
    }
    
    return $topKeywords;
}

/**
 * ⚖️ Charge de Travail & Risque (Agent Load)
 */
function getAgentLoad($db) {
    // Récupérer les admins
    $admins = [];
    $res = $db->query("SELECT id, firstname_encrypted, lastname_encrypted FROM users WHERE role = 'admin'");
    while ($row = $res->fetch_assoc()) {
        $admins[$row['id']] = [
            'name' => decrypt($row['firstname_encrypted']) . ' ' . decrypt($row['lastname_encrypted']),
            'load_score' => 0,
            'tickets_count' => 0,
            'high_priority' => 0
        ];
    }
    
    // Calculer la charge
    // Priorité Haute = 3 pts, Moyenne = 2 pts, Basse = 1 pt
    $query = "SELECT assigned_to, priority FROM tickets WHERE status IN ('Ouvert', 'En cours') AND assigned_to IS NOT NULL";
    $result = $db->query($query);
    
    while ($row = $result->fetch_assoc()) {
        $adminId = $row['assigned_to'];
        if (isset($admins[$adminId])) {
            $priority = $row['priority'];
            $points = ($priority === 'Haute') ? 3 : (($priority === 'Moyenne') ? 2 : 1);
            
            $admins[$adminId]['tickets_count']++;
            $admins[$adminId]['load_score'] += $points;
            if ($priority === 'Haute') $admins[$adminId]['high_priority']++;
        }
    }
    
    // Convertir en tableau indexé et trier par charge
    $loadData = array_values($admins);
    usort($loadData, function($a, $b) {
        return $b['load_score'] - $a['load_score'];
    });
    
    return $loadData;
}

/**
 * 📉 Corrélation Satisfaction / Temps de résolution
 */
function getSatisfactionCorrelation($db) {
    $query = "SELECT 
                t.id,
                TIMESTAMPDIFF(HOUR, t.created_at, t.closed_at) as hours,
                r.rating
              FROM tickets t
              JOIN ticket_reviews r ON t.id = r.ticket_id
              WHERE t.status = 'Fermé' AND t.closed_at IS NOT NULL
              LIMIT 100"; // Limiter pour la performance et la lisibilité
              
    $result = $db->query($query);
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'x' => round($row['hours'], 1),
            'y' => (int)$row['rating']
        ];
    }
    
    return $data;
}