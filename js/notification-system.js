/**
 * Système de Notifications en Temps Réel
 * ⭐ MIS À JOUR : Envoie un événement 'ticketsUpdated' pour rafraîchir l'UI
 */

class NotificationSystem {
    constructor() {
        this.pollingInterval = null;
        this.pollingDelay = 30000; // 30 secondes
        this.lastNotificationCount = 0;
        this.soundEnabled = localStorage.getItem('notif_sound') !== 'false';
        this.init();
    }

    init() {
        this.injectStyles();
        this.injectHTML();
        this.attachEventListeners();
        this.startPolling();
        this.loadNotifications();
    }

    injectStyles() {
        const style = document.createElement('style');
        style.textContent = `
            /* Badge de notification */
            .notification-badge {
                position: relative;
                cursor: pointer;
                padding: 8px 12px;
                border-radius: 8px;
                transition: all 0.3s;
            }
            .notification-badge:hover {
                background: rgba(239, 128, 0, 0.1);
            }
            .notification-badge .badge-icon {
                font-size: 24px;
                position: relative;
            }
            .notification-badge .badge-count {
                position: absolute;
                top: -5px;
                right: -5px;
                background: #ef4444;
                color: white;
                font-size: 11px;
                font-weight: 700;
                padding: 2px 6px;
                border-radius: 10px;
                min-width: 18px;
                text-align: center;
                animation: pulse 2s infinite;
            }
            @keyframes pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.1); }
            }
            /* Panneau de notifications */
            .notification-panel {
                position: fixed;
                top: 70px;
                right: 20px;
                width: 400px;
                max-width: 90vw;
                max-height: 600px;
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                z-index: 9999;
                display: none;
                flex-direction: column;
                animation: slideDown 0.3s ease;
            }
            .notification-panel.active {
                display: flex;
            }
            @keyframes slideDown {
                from { opacity: 0; transform: translateY(-20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .notification-panel-header {
                padding: 20px;
                border-bottom: 2px solid var(--gray-200);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .notification-panel-header h3 {
                color: var(--gray-900);
                font-size: 18px;
                margin: 0;
            }
            .notification-panel-actions {
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .sound-toggle {
                background: none;
                border: none;
                cursor: pointer;
                font-size: 20px;
                padding: 5px;
                border-radius: 5px;
                transition: background 0.3s;
            }
            .sound-toggle:hover { background: var(--gray-100); }
            .sound-toggle.disabled { opacity: 0.4; }
            .mark-all-read {
                background: var(--orange);
                color: white;
                border: none;
                padding: 6px 12px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 12px;
                font-weight: 600;
                transition: all 0.3s;
            }
            .mark-all-read:hover {
                background: #D67200;
                transform: translateY(-2px);
            }
            .notification-panel-body {
                overflow-y: auto;
                max-height: 500px;
                padding: 10px;
            }
            .notification-item {
                padding: 15px;
                margin-bottom: 10px;
                background: var(--gray-50);
                border-radius: 8px;
                border-left: 4px solid var(--orange);
                cursor: pointer;
                transition: all 0.3s;
                animation: fadeIn 0.3s ease;
            }
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            .notification-item:hover {
                background: rgba(239, 128, 0, 0.05);
                transform: translateX(5px);
            }
            .notification-item.unread {
                background: rgba(239, 128, 0, 0.1);
                border-left-width: 6px;
            }
            .notification-item-header {
                display: flex;
                justify-content: space-between;
                align-items: start;
                margin-bottom: 8px;
            }
            .notification-item-title {
                font-weight: 600;
                color: var(--gray-900);
                font-size: 14px;
            }
            .notification-item-time {
                font-size: 11px;
                color: var(--gray-600);
                white-space: nowrap;
            }
            .notification-item-preview {
                color: var(--gray-700);
                font-size: 13px;
                line-height: 1.4;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            .notification-empty {
                text-align: center;
                padding: 60px 20px;
                color: var(--gray-600);
            }
            .notification-empty-icon {
                font-size: 48px;
                margin-bottom: 15px;
                opacity: 0.5;
            }
            .notification-toast {
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                z-index: 10000;
                display: none;
                max-width: 350px;
                animation: slideInRight 0.3s ease;
                border-left: 4px solid var(--orange);
            }
            @keyframes slideInRight {
                from { opacity: 0; transform: translateX(100px); }
                to { opacity: 1; transform: translateX(0); }
            }
            .notification-toast.active { display: block; }
            .notification-toast-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            .notification-toast-icon { font-size: 24px; }
            .notification-toast-title {
                font-weight: 600;
                color: var(--gray-900);
            }
            .notification-toast-body {
                color: var(--gray-700);
                font-size: 14px;
            }
            .notification-loading {
                text-align: center;
                padding: 20px;
                color: var(--gray-600);
            }
            .notification-loading::after {
                content: '⏳';
                font-size: 24px;
                animation: spin 2s linear infinite;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            @media (max-width: 768px) {
                .notification-panel {
                    right: 10px;
                    left: 10px;
                    width: auto;
                    top: 60px;
                }
                .notification-badge .badge-icon {
                    font-size: 20px;
                }
                .notification-toast {
                    right: 10px;
                    left: 10px;
                    max-width: none;
                }
            }
        `;
        document.head.appendChild(style);
    }

    injectHTML() {
        // Badge de notification dans le header
        const navButtons = document.querySelector('.nav-buttons');
        if (navButtons) {
            const badge = document.createElement('div');
            badge.className = 'notification-badge';
            badge.id = 'notificationBadge';
            badge.innerHTML = `
                <div class="badge-icon">
                    🔔
                    <span class="badge-count" id="notificationCount" style="display:none;">0</span>
                </div>
            `;
            navButtons.insertBefore(badge, navButtons.firstChild);
        }

        // Panneau de notifications
        const panel = document.createElement('div');
        panel.className = 'notification-panel';
        panel.id = 'notificationPanel';
        panel.innerHTML = `
            <div class="notification-panel-header">
                <h3>📬 Notifications</h3>
                <div class="notification-panel-actions">
                    <button class="sound-toggle" id="soundToggle" title="Activer/Désactiver le son">
                        ${this.soundEnabled ? '🔔' : '🔕'}
                    </button>
                    <button class="mark-all-read" id="markAllRead">Tout marquer lu</button>
                </div>
            </div>
            <div class="notification-panel-body" id="notificationList">
                <div class="notification-loading">Chargement...</div>
            </div>
        `;
        document.body.appendChild(panel);

        // Toast de notification
        const toast = document.createElement('div');
        toast.className = 'notification-toast';
        toast.id = 'notificationToast';
        document.body.appendChild(toast);
    }

    attachEventListeners() {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            badge.addEventListener('click', () => this.togglePanel());
        }
        document.addEventListener('click', (e) => {
            const panel = document.getElementById('notificationPanel');
            const badge = document.getElementById('notificationBadge');
            if (panel && badge && !panel.contains(e.target) && !badge.contains(e.target)) {
                panel.classList.remove('active');
            }
        });
        const soundToggle = document.getElementById('soundToggle');
        if (soundToggle) {
            soundToggle.addEventListener('click', () => this.toggleSound());
        }
        const markAllRead = document.getElementById('markAllRead');
        if (markAllRead) {
            markAllRead.addEventListener('click', () => this.markAllAsRead());
        }
    }

    startPolling() {
        this.loadNotifications();
        this.pollingInterval = setInterval(() => {
            this.loadNotifications(true); // true = silent mode
        }, this.pollingDelay);
    }

    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
    }

    async loadNotifications(silent = false) {
        try {
            const res = await fetch('api.php?action=notifications');
            const data = await res.json();

            if (data.success) {
                const count = data.notifications.length;
                this.updateBadge(count);
                this.renderNotifications(data.notifications);

                if (count > this.lastNotificationCount && count > 0) {
                    if (!silent) {
                        this.showToast(data.notifications[0]);
                        this.playSound();
                    }
                    
                    // ⭐ NOUVEAU : On prévient le reste de l'application
                    document.dispatchEvent(new CustomEvent('ticketsUpdated'));
                }

                this.lastNotificationCount = count;
            }
        } catch (error) {
            console.error('Erreur de chargement des notifications:', error);
        }
    }

    updateBadge(count) {
        const badge = document.getElementById('notificationCount');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'block';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    renderNotifications(notifications) {
        const list = document.getElementById('notificationList');
        if (!list) return;

        if (notifications.length === 0) {
            list.innerHTML = `
                <div class="notification-empty">
                    <div class="notification-empty-icon">📭</div>
                    <p>Aucune nouvelle notification</p>
                </div>
            `;
            return;
        }

        list.innerHTML = notifications.map(notif => `
            <div class="notification-item unread" onclick="notificationSystem.openTicket(${notif.ticket_id})">
                <div class="notification-item-header">
                    <div class="notification-item-title">
                        🎫 Ticket #${notif.ticket_id} - ${notif.subject}
                    </div>
                    <div class="notification-item-time">${notif.time_ago}</div>
                </div>
                <div class="notification-item-preview">${notif.preview}</div>
            </div>
        `).join('');
    }

    togglePanel() {
        const panel = document.getElementById('notificationPanel');
        if (panel) {
            panel.classList.toggle('active');
        }
    }

    toggleSound() {
        this.soundEnabled = !this.soundEnabled;
        localStorage.setItem('notif_sound', this.soundEnabled);
        
        const soundToggle = document.getElementById('soundToggle');
        if (soundToggle) {
            soundToggle.textContent = this.soundEnabled ? '🔔' : '🔕';
            soundToggle.classList.toggle('disabled', !this.soundEnabled);
        }

        this.showToast({
            subject: this.soundEnabled ? 'Son activé' : 'Son désactivé',
            preview: this.soundEnabled ? 'Vous recevrez des alertes sonores' : 'Les alertes sonores sont désactivées'
        });
    }

    playSound() {
        if (!this.soundEnabled) return;
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.5);
        } catch (error) {
            console.warn('Audio non supporté:', error);
        }
    }

    showToast(notification) {
        const toast = document.getElementById('notificationToast');
        if (!toast) return;

        toast.innerHTML = `
            <div class="notification-toast-header">
                <div class="notification-toast-icon">📬</div>
                <div class="notification-toast-title">Nouvelle notification</div>
            </div>
            <div class="notification-toast-body">
                <strong>Ticket #${notification.ticket_id || ''} - ${notification.subject}</strong>
                <p style="margin-top:5px;">${notification.preview}</p>
            </div>
        `;

        toast.classList.add('active');

        setTimeout(() => {
            toast.classList.remove('active');
        }, 5000);
    }

    async markAllAsRead() {
        try {
            const res = await fetch('api.php?action=notifications_read_all', {
                method: 'POST'
            });
            const data = await res.json();
            if (data.success) {
                this.loadNotifications();
                // ⭐ NOUVEAU : On prévient aussi ici
                document.dispatchEvent(new CustomEvent('ticketsUpdated'));
                this.showToast({
                    subject: 'Notifications marquées',
                    preview: 'Toutes les notifications ont été marquées comme lues'
                });
            }
        } catch (error) {
            console.error('Erreur:', error);
        }
    }

    openTicket(ticketId) {
        const panel = document.getElementById('notificationPanel');
        if (panel) {
            panel.classList.remove('active');
        }

        fetch('api.php?action=message_read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId })
        });
        
        // ⭐ NOUVEAU : On prévient aussi ici
        document.dispatchEvent(new CustomEvent('ticketsUpdated'));

        if (typeof viewTicket === 'function') {
            viewTicket(ticketId);
        } else {
            console.log("Tentative d'ouverture du ticket", ticketId);
        }
    }

    destroy() {
        this.stopPolling();
    }
}

let notificationSystem;
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        notificationSystem = new NotificationSystem();
    });
} else {
    notificationSystem = new NotificationSystem();
}
window.addEventListener('beforeunload', () => {
    if (notificationSystem) {
        notificationSystem.destroy();
    }
});