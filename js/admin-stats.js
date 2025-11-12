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
            <h2>📊 Tableau de Bord</h2>
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
                    <h3>📈 Évolution des Tickets</h3>
                </div>
                <div id="timelineChart"></div>
            </div>

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

            <div class="chart-card">
                <div class="chart-header">
                    <h3>⭐ Satisfaction Client</h3>
                </div>
                <div id="satisfactionChart"></div>
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
    renderTimelineChart(data.timeline);
    renderCategoryChart(data.categories);
    renderPriorityChart(data.priorities);
    renderSatisfactionChart(data.satisfaction);
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
 * 📈 Graphique Timeline
 */
function renderTimelineChart(data) {
    const options = {
        series: [
            {
                name: 'Créés',
                data: data.map(d => d.total)
            },
            {
                name: 'Fermés',
                data: data.map(d => d.closed)
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
            width: 3
        },
        colors: ['#EF8000', '#10b981'],
        xaxis: {
            categories: data.map(d => new Date(d.date).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })),
            labels: {
                style: { colors: '#6b7280' }
            }
        },
        yaxis: {
            labels: {
                style: { colors: '#6b7280' }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.2
            }
        },
        legend: {
            position: 'top',
            horizontalAlign: 'right'
        },
        tooltip: {
            shared: true,
            intersect: false
        }
    };
    
    const chart = new ApexCharts(document.querySelector("#timelineChart"), options);
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
    const ratings = [5, 4, 3, 2, 1];
    const counts = ratings.map(r => data[r] || 0);
    
    const options = {
        series: [{
            name: 'Nombre d\'avis',
            data: counts
        }],
        chart: {
            type: 'bar',
            height: 350,
            horizontal: true
        },
        plotOptions: {
            bar: {
                borderRadius: 8,
                distributed: true,
                horizontal: true
            }
        },
        dataLabels: {
            enabled: true
        },
        colors: ['#10b981', '#34d399', '#fbbf24', '#fb923c', '#ef4444'],
        xaxis: {
            categories: ratings.map(r => '★'.repeat(r) + '☆'.repeat(5-r))
        },
        legend: {
            show: false
        }
    };
    
    const chart = new ApexCharts(document.querySelector("#satisfactionChart"), options);
    chart.render();
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
            categories: Array.from({length: 24}, (_, i) => `${i}h`),
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
 * 📥 Export PDF (placeholder)
 */
function exportDashboard() {
    alert('🚧 Fonctionnalité en cours de développement...\n\nProchainement : Export PDF complet du dashboard !');
}

// Export pour utilisation dans admin-script.js
window.loadAdvancedStats = loadAdvancedStats;