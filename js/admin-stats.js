/**
 * ===================================================================
 * DASHBOARD STATISTIQUES AVANCÉES
 * ===================================================================
 * Interface moderne avec graphiques interactifs ApexCharts
 * ===================================================================
 */

let statsData = null;
let currentPeriod = '30';

/**
 * 📊 Charge le dashboard complet
 */
async function loadAdvancedStats(period = '30') {
    currentPeriod = period;
    const container = document.getElementById('statsTab');

    // Afficher le loader
    container.innerHTML = `
        <div style="text-align:center; padding:60px;">
            <div style="width:60px;height:60px;border:6px solid var(--gray-200);border-top-color:var(--orange);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 20px;"></div>
            <h3 style="color:var(--gray-600);">Chargement des statistiques...</h3>
        </div>
    `;

    try {
        const res = await apiFetch(`api.php?action=get_advanced_stats&period=${period}`);
        const data = await res.json();

        if (data.success) {
            statsData = data;
            renderDashboard(data);
        } else {
            container.innerHTML = `<div class="error-message">${data.message}</div>`;
        }
    } catch (error) {
        console.error('Erreur chargement stats:', error);
        container.innerHTML = `<div class="error-message">Impossible de charger les statistiques</div>`;
    }
}

/**
 * 🎨 Rendu du dashboard complet
 */
function renderDashboard(data) {
    const container = document.getElementById('statsTab');

    container.innerHTML = `
        <!-- Header avec filtres -->
        <div class="stats-header">
            <h2>📊 Tableau de Bord Avancé</h2>
            <div class="stats-filters">
                <button class="period-btn ${currentPeriod === '7' ? 'active' : ''}" onclick="loadAdvancedStats('7')">7 jours</button>
                <button class="period-btn ${currentPeriod === '30' ? 'active' : ''}" onclick="loadAdvancedStats('30')">30 jours</button>
                <button class="period-btn ${currentPeriod === '90' ? 'active' : ''}" onclick="loadAdvancedStats('90')">3 mois</button>
                <button class="period-btn ${currentPeriod === '365' ? 'active' : ''}" onclick="loadAdvancedStats('365')">1 an</button>
                <button class="btn btn-secondary btn-small" onclick="exportDashboard()">📥 Export PDF</button>
            </div>
        </div>

        <!-- KPIs Cards -->
        <div class="kpi-grid">
            ${renderKPICard('Total Tickets', data.kpis.total_tickets, '🎫', 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)')}
            ${renderKPICard('Ouverts', data.kpis.open_tickets, '📬', 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)', data.trends.created.variation)}
            ${renderKPICard('En Cours', data.kpis.in_progress_tickets, '⚙️', 'linear-gradient(135deg, #EF8000 0%, #D67200 100%)')}
            ${renderKPICard('Fermés', data.kpis.closed_tickets, '✅', 'linear-gradient(135deg, #10b981 0%, #059669 100%)', data.trends.resolved.variation)}
            ${renderKPICard('Satisfaction', data.kpis.satisfaction_rate + '/5', '⭐', 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)')}
            ${renderKPICard('Temps Résolution', data.kpis.avg_resolution_time + 'h', '⏱️', 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)')}
            ${renderKPICard('Non Assignés', data.kpis.unassigned_tickets, '📌', 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)')}
            ${renderKPICard('Avis Reçus', data.kpis.total_reviews, '💬', 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)')}
        </div>

        <!-- Graphiques principaux -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>📈 Évolution & Prédictions</h3>
                </div>
                <div id="timelineChart"></div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3>🧠 Analyse Sémantique (Mots-clés)</h3>
                </div>
                <div id="keywordChart" class="keyword-cloud"></div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3>⚖️ Charge & Risque Agents</h3>
                </div>
                <div id="agentLoadChart"></div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3>📉 Corrélation Satisfaction / Temps</h3>
                </div>
                <div id="correlationChart"></div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>🎨 Répartition par Catégorie</h3>
                </div>
                <div id="categoryChart"></div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3>🎯 Répartition par Priorité</h3>
                </div>
                <div id="priorityChart"></div>
            </div>
        </div>

        <!-- Performance admins -->
        <div class="performance-section">
            <h3>👥 Performance des Administrateurs</h3>
            <div id="adminsPerformance"></div>
        </div>

        <!-- Graphique heures de pointe -->
        <div class="chart-card">
            <div class="chart-header">
                <h3>🕐 Heures de Pointe</h3>
            </div>
            <div id="peakHoursChart"></div>
        </div>

        <!-- Top catégories + Tickets non assignés -->
        <div class="bottom-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>🏆 Top 5 Catégories</h3>
                </div>
                <div id="topCategories"></div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3>📌 Tickets en Attente</h3>
                </div>
                <div id="unassignedTickets"></div>
            </div>
        </div>
    `;

    // Rendre tous les graphiques
    renderForecastChart(data.timeline, data.forecast);
    renderKeywordAnalysis(data.keywords);
    renderAgentLoad(data.agent_load);
    renderCorrelationChart(data.correlation);

    renderCategoryChart(data.categories);
    renderPriorityChart(data.priorities);
    renderAdminsPerformance(data.admins_performance);
    renderPeakHoursChart(data.peak_hours);
    renderTopCategories(data.top_categories);
    renderUnassignedTickets(data.unassigned);
}

/**
 * 🎯 KPI Card avec variation
 */
function renderKPICard(label, value, icon, gradient, variation = null) {
    let variationHTML = '';
    if (variation !== null) {
        const isPositive = variation >= 0;
        const arrow = isPositive ? '↗' : '↘';
        const color = isPositive ? '#10b981' : '#ef4444';
        variationHTML = `<div class="kpi-variation" style="color:${color}">${arrow} ${Math.abs(variation)}%</div>`;
    }

    return `
        <div class="kpi-card" style="background:${gradient}">
            <div class="kpi-icon">${icon}</div>
            <div class="kpi-content">
                <div class="kpi-value">${value}</div>
                <div class="kpi-label">${label}</div>
                ${variationHTML}
            </div>
        </div>
    `;
}

/**
 * 📈 Graphique Timeline + Forecast
 */
function renderForecastChart(timelineData, forecastData) {
    const historicalData = timelineData.map(d => ({
        x: new Date(d.date).getTime(),
        y: d.total
    }));

    const predictionData = forecastData.map(d => ({
        x: new Date(d.date).getTime(),
        y: d.count
    }));

    const options = {
        series: [
            {
                name: 'Historique',
                data: historicalData
            },
            {
                name: 'Prédiction',
                data: predictionData
            }
        ],
        chart: {
            type: 'area',
            height: 350,
            toolbar: { show: false },
            zoom: { enabled: true }
        },
        dataLabels: { enabled: false },
        stroke: {
            curve: 'smooth',
            width: [3, 3],
            dashArray: [0, 5] // Pointillés pour la prédiction
        },
        colors: ['#EF8000', '#9ca3af'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.2
            }
        },
        xaxis: {
            type: 'datetime',
            labels: {
                format: 'dd MMM',
                style: { colors: '#6b7280' }
            }
        },
        tooltip: {
            x: { format: 'dd MMM yyyy' }
        },
        legend: { position: 'top' }
    };

    const chart = new ApexCharts(document.querySelector("#timelineChart"), options);
    chart.render();
}

/**
 * 🧠 Analyse Sémantique
 */
function renderKeywordAnalysis(data) {
    const container = document.getElementById('keywordChart');

    if (!data || data.length === 0) {
        container.innerHTML = '<p style="text-align:center;width:100%;color:var(--gray-600);">Pas assez de données</p>';
        return;
    }

    // Normaliser les tailles
    const maxCount = Math.max(...data.map(d => d.count));

    container.innerHTML = data.map(item => {
        const sizeClass = item.count > maxCount * 0.7 ? 'high' : (item.count > maxCount * 0.4 ? 'medium' : 'low');
        return `<span class="keyword-tag ${sizeClass}" title="${item.count} occurrences">${item.word}</span>`;
    }).join('');
}

/**
 * ⚖️ Charge Agent
 */
function renderAgentLoad(data) {
    const container = document.getElementById('agentLoadChart');

    if (!data || data.length === 0) {
        container.innerHTML = '<p style="text-align:center;color:var(--gray-600);">Aucun agent actif</p>';
        return;
    }

    container.innerHTML = data.map(agent => {
        // Calcul du risque/charge (0-100 arbitraire pour la couleur)
        // Disons que 10 points de charge = 100% (très chargé)
        const loadPercent = Math.min(100, agent.load_score * 10);
        const color = loadPercent > 70 ? '#ef4444' : (loadPercent > 40 ? '#f59e0b' : '#10b981');

        return `
            <div class="agent-load-card">
                <div class="agent-load-gauge" style="color:${color};border:4px solid ${color}">
                    ${agent.load_score}
                </div>
                <div class="agent-load-info">
                    <div class="agent-load-name">${agent.name}</div>
                    <div class="agent-load-details">
                        ${agent.tickets_count} tickets actifs<br>
                        <span style="color:#ef4444">${agent.high_priority} urgents</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * 📉 Corrélation Satisfaction
 */
function renderCorrelationChart(data) {
    const options = {
        series: [{
            name: "Tickets",
            data: data.map(d => [d.x, d.y])
        }],
        chart: {
            height: 350,
            type: 'scatter',
            zoom: { enabled: true, type: 'xy' },
            toolbar: { show: false }
        },
        xaxis: {
            tickAmount: 10,
            labels: {
                formatter: function (val) { return parseFloat(val).toFixed(1) }
            },
            title: { text: 'Temps de résolution (heures)' }
        },
        yaxis: {
            tickAmount: 5,
            min: 1,
            max: 5,
            title: { text: 'Note (1-5)' }
        },
        colors: ['#EF8000'],
        markers: { size: 6 }
    };

    const chart = new ApexCharts(document.querySelector("#correlationChart"), options);
    chart.render();
}

/**
 * 🎨 Graphique Catégories
 */
function renderCategoryChart(data) {
    const options = { // Les données sont déjà agrégées
        series: data.map(d => d.count),
        chart: {
            type: 'donut',
            height: 350
        },
        labels: data.map(d => d.name),
        colors: ['#667eea', '#EF8000', '#10b981', '#f59e0b', '#ef4444'],
        legend: {
            position: 'bottom'
        },
        plotOptions: {
            pie: {
                donut: {
                    size: '70%',
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: 'Total',
                            fontSize: '16px',
                            fontWeight: 600,
                            color: '#374151'
                        }
                    }
                }
            }
        },
        responsive: [{
            breakpoint: 480,
            options: {
                chart: { width: 300 },
                legend: { position: 'bottom' }
            }
        }]
    };

    const chart = new ApexCharts(document.querySelector("#categoryChart"), options);
    chart.render();
}

/**
 * 🎯 Graphique Priorités
 */
function renderPriorityChart(data) {
    const options = { // Les données sont déjà agrégées
        series: data.map(d => d.count),
        chart: {
            type: 'polarArea',
            height: 350
        },
        labels: data.map(d => d.name),
        colors: ['#ef4444', '#EF8000', '#9ca3af'],
        legend: {
            position: 'bottom'
        },
        stroke: {
            colors: ['#fff']
        },
        fill: {
            opacity: 0.8
        }
    };

    const chart = new ApexCharts(document.querySelector("#priorityChart"), options);
    chart.render();
}

/**
 * ⭐ Graphique Satisfaction
 */
function renderSatisfactionChart(data) {
    // Note: Ce graphique a été remplacé par la corrélation dans la vue principale, 
    // mais on peut le garder si besoin ou le supprimer.
    // Pour l'instant, je ne l'appelle plus dans renderDashboard pour gagner de la place.
}

/**
 * 👥 Performance Admins
 */
function renderAdminsPerformance(data) {
    const container = document.getElementById('adminsPerformance');

    if (!data || data.length === 0) {
        container.innerHTML = '<p style="text-align:center;padding:20px;color:var(--gray-600);">Aucune donnée disponible</p>';
        return;
    }

    container.innerHTML = data.map((admin, index) => {
        const resolvedRate = admin.total_assigned > 0 ? Math.round((admin.resolved / admin.total_assigned) * 100) : 0;
        const medal = index === 0 ? '🥇' : index === 1 ? '🥈' : index === 2 ? '🥉' : '';

        return `
            <div class="admin-performance-card">
                <div class="admin-rank">${medal || '#' + (index + 1)}</div>
                <div class="admin-info">
                    <div class="admin-name">${admin.name}</div>
                    <div class="admin-stats">
                        <span>📊 ${admin.total_assigned} assignés</span>
                        <span>✅ ${admin.resolved} résolus</span>
                        <span>⏱️ ${admin.avg_resolution_time}h moy.</span>
                    </div>
                </div>
                <div class="admin-progress">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width:${resolvedRate}%;background:${resolvedRate > 80 ? '#10b981' : resolvedRate > 50 ? '#EF8000' : '#ef4444'}"></div>
                    </div>
                    <span class="progress-label">${resolvedRate}% résolus</span>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * 🕐 Graphique heures de pointe
 */
function renderPeakHoursChart(data) {
    const options = {
        series: [{
            name: 'Tickets créés',
            data: data
        }],
        chart: {
            type: 'heatmap',
            height: 250,
            toolbar: { show: false }
        },
        dataLabels: {
            enabled: false
        },
        colors: ['#EF8000'],
        xaxis: {
            categories: Array.from({ length: 24 }, (_, i) => `${i}h`),
            labels: {
                style: { fontSize: '11px' }
            }
        },
        plotOptions: {
            heatmap: {
                radius: 8,
                enableShades: true,
                shadeIntensity: 0.5,
                colorScale: {
                    ranges: [
                        { from: 0, to: 5, color: '#d1fae5', name: 'Faible' },
                        { from: 6, to: 15, color: '#fef3c7', name: 'Moyen' },
                        { from: 16, to: 50, color: '#fed7aa', name: 'Élevé' },
                        { from: 51, to: 1000, color: '#fca5a5', name: 'Très élevé' }
                    ]
                }
            }
        }
    };

    const chart = new ApexCharts(document.querySelector("#peakHoursChart"), options);
    chart.render();
}

/**
 * 🏆 Top Catégories
 */
function renderTopCategories(data) {
    const container = document.getElementById('topCategories');

    if (!data || data.length === 0) {
        container.innerHTML = '<p style="text-align:center;padding:20px;color:var(--gray-600);">Aucune donnée</p>';
        return;
    }

    const maxCount = Math.max(...data.map(d => d.count));

    container.innerHTML = data.map((item, index) => {
        const percentage = Math.round((item.count / maxCount) * 100);

        return `
            <div class="top-item">
                <div class="top-rank">#${index + 1}</div>
                <div class="top-info">
                    <div class="top-name">${item.category}</div>
                    <div class="top-bar">
                        <div class="top-bar-fill" style="width:${percentage}%"></div>
                    </div>
                </div>
                <div class="top-count">${item.count}</div>
            </div>
        `;
    }).join('');
}

/**
 * 📌 Tickets non assignés
 */
function renderUnassignedTickets(data) {
    const container = document.getElementById('unassignedTickets');

    if (!data || data.length === 0) {
        container.innerHTML = '<div class="empty-alert">✅ Aucun ticket en attente !</div>';
        return;
    }

    container.innerHTML = data.map(ticket => {
        const priorityColor = ticket.priority === 'Haute' ? '#ef4444' : ticket.priority === 'Moyenne' ? '#EF8000' : '#9ca3af';

        return `
            <div class="unassigned-item" onclick="viewTicket(${ticket.id})">
                <div class="unassigned-id">#${ticket.id}</div>
                <div class="unassigned-info">
                    <div class="unassigned-subject">${ticket.subject}</div>
                    <div class="unassigned-meta">
                        <span class="badge" style="background:${priorityColor};color:white;">${ticket.priority}</span>
                        <span>⏳ En attente depuis ${ticket.waiting_time}h</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * 📥 Export PDF - Full Implementation
 */
async function exportDashboard() {
    // Vérifier que les bibliothèques sont chargées
    if (typeof html2canvas === 'undefined' || typeof jspdf === 'undefined') {
        alert('❌ Erreur : Les bibliothèques PDF ne sont pas chargées. Veuillez rafraîchir la page.');
        return;
    }

    const statsContainer = document.getElementById('statsTab');
    if (!statsContainer || !statsData) {
        alert('❌ Aucune donnée à exporter. Veuillez d\'abord charger les statistiques.');
        return;
    }

    // Afficher un indicateur de chargement
    const loadingOverlay = document.createElement('div');
    loadingOverlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        color: white;
    `;
    loadingOverlay.innerHTML = `
        <div style="width:60px;height:60px;border:6px solid #fff;border-top-color:#EF8000;border-radius:50%;animation:spin 1s linear infinite;margin-bottom:20px;"></div>
        <h3 style="margin:0;font-size:1.5rem;">Génération du PDF en cours...</h3>
        <p style="margin:10px 0 0 0;opacity:0.8;">Veuillez patienter quelques secondes</p>
    `;
    document.body.appendChild(loadingOverlay);

    try {
        // Attendre un peu pour que l'overlay s'affiche
        await new Promise(resolve => setTimeout(resolve, 100));

        // Capturer le contenu avec html2canvas
        const canvas = await html2canvas(statsContainer, {
            scale: 2, // Haute résolution
            useCORS: true,
            logging: false,
            backgroundColor: '#EDEDED',
            windowWidth: statsContainer.scrollWidth,
            windowHeight: statsContainer.scrollHeight
        });

        // Créer le PDF avec jsPDF
        const { jsPDF } = jspdf;
        const pdf = new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: 'a4'
        });

        // Dimensions A4 en mm
        const pageWidth = 210;
        const pageHeight = 297;
        const margin = 10;
        const contentWidth = pageWidth - (2 * margin);

        // Ajouter un en-tête
        pdf.setFontSize(18);
        pdf.setTextColor(74, 74, 73); // var(--primary-dark)
        pdf.text('📊 Rapport Statistiques - Support Ticketing', margin, 15);

        pdf.setFontSize(10);
        pdf.setTextColor(102, 102, 102); // var(--text-muted)
        const now = new Date();
        const dateStr = now.toLocaleDateString('fr-FR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
        pdf.text(`Généré le ${dateStr}`, margin, 22);
        pdf.text(`Période : ${currentPeriod} jours`, margin, 27);

        // Ligne de séparation
        pdf.setDrawColor(239, 128, 0); // var(--orange)
        pdf.setLineWidth(0.5);
        pdf.line(margin, 30, pageWidth - margin, 30);

        // Calculer les dimensions de l'image pour qu'elle rentre dans la page
        const imgWidth = contentWidth;
        const imgHeight = (canvas.height * imgWidth) / canvas.width;

        // Position de départ du contenu
        let yPosition = 35;

        // Si l'image est plus haute qu'une page, on la divise en plusieurs pages
        if (imgHeight > (pageHeight - yPosition - margin)) {
            // Image trop haute, on la divise
            const imgData = canvas.toDataURL('image/png');
            let heightLeft = imgHeight;
            let position = yPosition;

            // Première page
            pdf.addImage(imgData, 'PNG', margin, position, imgWidth, imgHeight);
            heightLeft -= (pageHeight - position - margin);

            // Pages suivantes
            while (heightLeft > 0) {
                position = heightLeft - imgHeight + margin;
                pdf.addPage();
                pdf.addImage(imgData, 'PNG', margin, position, imgWidth, imgHeight);
                heightLeft -= (pageHeight - margin);
            }
        } else {
            // L'image rentre sur une seule page
            const imgData = canvas.toDataURL('image/png');
            pdf.addImage(imgData, 'PNG', margin, yPosition, imgWidth, imgHeight);
        }

        // Ajouter un pied de page sur chaque page
        const pageCount = pdf.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            pdf.setPage(i);
            pdf.setFontSize(8);
            pdf.setTextColor(150, 150, 150);
            pdf.text(
                `Page ${i} sur ${pageCount} - Support Ticketing System`,
                pageWidth / 2,
                pageHeight - 5,
                { align: 'center' }
            );
        }

        // Générer le nom du fichier avec date et heure
        const filename = `Statistiques_${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}_${String(now.getHours()).padStart(2, '0')}-${String(now.getMinutes()).padStart(2, '0')}.pdf`;

        // Télécharger le PDF
        pdf.save(filename);

        // Retirer l'overlay de chargement
        document.body.removeChild(loadingOverlay);

        // Message de succès
        const successMsg = document.createElement('div');
        successMsg.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            z-index: 10000;
            font-weight: 600;
            animation: slideIn 0.3s ease;
        `;
        successMsg.textContent = '✅ PDF téléchargé avec succès !';
        document.body.appendChild(successMsg);

        setTimeout(() => {
            successMsg.style.opacity = '0';
            successMsg.style.transition = 'opacity 0.3s';
            setTimeout(() => document.body.removeChild(successMsg), 300);
        }, 3000);

    } catch (error) {
        console.error('Erreur lors de la génération du PDF:', error);
        document.body.removeChild(loadingOverlay);
        alert('❌ Erreur lors de la génération du PDF. Veuillez réessayer.\n\nDétails : ' + error.message);
    }
}

// Export pour utilisation dans admin-script.js
window.loadAdvancedStats = loadAdvancedStats;