// ============================================================
// APIFY INTEGRATION — через PHP-прокси на SpaceWeb
// ============================================================

/**
 * Получает данные о Reel через ваш PHP-прокси
 * @param {string} url - Ссылка на Instagram Reel
 * @returns {Promise<Object>} - Данные Reel
 */
async function fetchReelData(url) {
    try {
        console.log('🚀 Отправка запроса к PHP-прокси для:', url);
        console.log('📍 URL прокси:', API_PROXY_URL);

        const response = await fetch(API_PROXY_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ url: url })
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || `Ошибка сервера: ${response.status}`);
        }

        const result = await response.json();
        console.log('📥 Ответ от прокси:', result);

        if (!result.success) {
            throw new Error(result.error || 'Неизвестная ошибка');
        }

        if (!result.data) {
            throw new Error('Данные не получены');
        }

        return result.data;

    } catch (error) {
        console.error('❌ Ошибка fetchReelData:', error);
        throw error;
    }
}

// Делаем функцию доступной глобально
window.fetchReelData = fetchReelData;