// ============================================================
// APP LOGIC
// ============================================================

const APP_KEY = 'pifpaf_reels_data';

// --- Data Management ---
function getReels() {
    try {
        const data = localStorage.getItem(APP_KEY);
        return data ? JSON.parse(data) : [];
    } catch (e) {
        return [];
    }
}

function saveReels(reels) {
    localStorage.setItem(APP_KEY, JSON.stringify(reels));
}

function addReel(reelData) {
    const reels = getReels();
    const exists = reels.some(r => r.url === reelData.url);
    if (!exists) {
        // Убеждаемся, что все поля есть
        const cleanData = {
            url: reelData.url || '',
            views: parseInt(reelData.views) || 0,
            likes: parseInt(reelData.likes) || 0,
            title: reelData.title || 'Reel без описания',
            thumbnailUrl: reelData.thumbnailUrl || null,
            date: reelData.date || new Date().toISOString(),
            comments: parseInt(reelData.comments) || 0
        };
        reels.unshift(cleanData);
        saveReels(reels);
        return true;
    }
    return false;
}

function deleteReel(url) {
    const reels = getReels();
    const filtered = reels.filter(r => r.url !== url);
    if (filtered.length !== reels.length) {
        saveReels(filtered);
        return true;
    }
    return false;
}

function clearAllData() {
    if (confirm('🗑️ Удалить все Reels?')) {
        saveReels([]);
        renderDashboard();
    }
}

// --- Helpers ---
function formatNumber(num) {
    if (num >= 1_000_000) return (num / 1_000_000).toFixed(1) + 'M';
    if (num >= 1_000) return (num / 1_000).toFixed(1) + 'K';
    return num.toString();
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showStatus(element, message, type) {
    if (!element) return;
    element.textContent = message;
    element.className = `status-message ${type}`;
    element.style.display = 'block';

    if (type === 'success' || type === 'error') {
        setTimeout(() => {
            if (element) {
                element.style.display = 'none';
            }
        }, 8000);
    }
}

// --- Render ---
function renderDashboard() {
    const reels = getReels();
    renderReels(reels);
    updateStats(reels);
}

function renderReels(reels) {
    const grid = document.getElementById('reelsGrid');

    if (reels.length === 0) {
        grid.innerHTML = `
            <div class="empty-state">
                <div class="empty-icon">🎬</div>
                <h3>У вас пока нет Reels</h3>
                <p>Добавьте первый Reel, вставив ссылку выше</p>
            </div>
        `;
        return;
    }

    grid.innerHTML = reels.map((reel, index) => `
        <div class="reel-card" style="animation-delay: ${index * 0.06}s">
            <div class="reel-thumbnail-wrapper">
                <img 
                    src="${reel.thumbnailUrl || `https://picsum.photos/seed/${index}/300/533`}" 
                    alt="Reel обложка" 
                    class="reel-thumbnail"
                    onerror="this.src='https://picsum.photos/seed/${Date.now() + index}/300/533'"
                    loading="lazy"
                >
                <div class="reel-overlay">
                    <a href="${reel.url}" target="_blank" class="reel-link">Открыть в Instagram →</a>
                </div>
            </div>
            <div class="reel-info">
                <h3 title="${escapeHtml(reel.title || 'Reel без названия')}">
                    ${escapeHtml(reel.title || 'Reel без названия')}
                </h3>
                <div class="reel-meta">
                    <div class="reel-stats">
                        <span title="Просмотры">👁️ ${formatNumber(reel.views || 0)}</span>
                        <span title="Лайки">❤️ ${formatNumber(reel.likes || 0)}</span>
                        ${reel.comments ? `<span title="Комментарии">💬 ${formatNumber(reel.comments)}</span>` : ''}
                    </div>
                </div>
                <div class="reel-actions">
                    <button onclick="handleDeleteReel('${reel.url.replace(/'/g, "\\'")}')" 
                            class="btn-delete">
                        🗑️ Удалить
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

function updateStats(reels) {
    const total = reels.length;
    const totalViews = reels.reduce((sum, r) => sum + (r.views || 0), 0);
    const totalLikes = reels.reduce((sum, r) => sum + (r.likes || 0), 0);

    document.getElementById('totalReels').textContent = total;
    document.getElementById('totalViews').textContent = formatNumber(totalViews);
    document.getElementById('totalLikes').textContent = formatNumber(totalLikes);

    document.getElementById('avgViews').textContent = total > 0 ? formatNumber(Math.round(totalViews / total)) : '0';
    document.getElementById('avgLikes').textContent = total > 0 ? formatNumber(Math.round(totalLikes / total)) : '0';

    document.getElementById('reelsCount').textContent = total;
}

// --- Global Handlers ---
window.handleDeleteReel = function(url) {
    if (confirm('Удалить этот Reel из вашего списка?')) {
        const deleted = deleteReel(url);
        if (deleted) {
            renderDashboard();
        }
    }
};

// --- Event Listeners ---
document.addEventListener('DOMContentLoaded', () => {
    renderDashboard();

    document.getElementById('clearDataBtn').addEventListener('click', clearAllData);

    document.getElementById('addReelForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const urlInput = document.getElementById('reelUrl');
        const url = urlInput.value.trim();
        const statusDiv = document.getElementById('apiStatus');
        const button = document.getElementById('fetchReelBtn');

        if (!url) {
            showStatus(statusDiv, '⚠️ Пожалуйста, введите ссылку на Reel', 'error');
            return;
        }

        if (!url.includes('instagram.com/')) {
            showStatus(statusDiv, '⚠️ Введите корректную ссылку на Instagram (например, instagram.com/reel/...)', 'error');
            return;
        }

        const reels = getReels();
        if (reels.some(r => r.url === url)) {
            showStatus(statusDiv, '⚠️ Этот Reel уже добавлен', 'error');
            return;
        }

        showStatus(statusDiv, '⏳ Получение данных через Apify... Это может занять до 30 секунд', 'loading');
        button.disabled = true;
        urlInput.disabled = true;

        try {
            const reelData = await window.fetchReelData(url);

            if (reelData && reelData.views !== undefined) {
                const added = addReel(reelData);
                if (added) {
                    showStatus(statusDiv, `✅ Reel успешно добавлен! 👁️ ${formatNumber(reelData.views)} просмотров`, 'success');
                    renderDashboard();
                    urlInput.value = '';
                    
                    // Плавная прокрутка к ленте
                    const feed = document.querySelector('.reels-feed');
                    if (feed) {
                        setTimeout(() => {
                            feed.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 500);
                    }
                } else {
                    showStatus(statusDiv, '⚠️ Не удалось добавить Reel', 'error');
                }
            } else {
                showStatus(statusDiv, '❌ Не удалось получить данные. Проверьте ссылку.', 'error');
            }
        } catch (error) {
            console.error('Ошибка:', error);
            showStatus(statusDiv, `❌ ${error.message || 'Неизвестная ошибка'}`, 'error');
        } finally {
            button.disabled = false;
            urlInput.disabled = false;
        }
    });
});