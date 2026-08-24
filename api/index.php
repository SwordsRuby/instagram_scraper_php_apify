<?php
/**
 * api/index.php - Прокси для Apify Instagram Scraper
 * ИСПРАВЛЕННАЯ ВЕРСИЯ - правильный парсинг
 */

// --- КОНФИГУРАЦИЯ ---
$APIFY_TOKEN = 'apify_api_key';

// --- ВКЛЮЧАЕМ ОТОБРАЖЕНИЕ ОШИБОК ---
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- CORS ЗАГОЛОВКИ ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Используйте POST']));
}

// --- ПОЛУЧАЕМ ССЫЛКУ ---
$input = json_decode(file_get_contents('php://input'), true);
$url = $input['url'] ?? '';

if (empty($url) || strpos($url, 'instagram.com') === false) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Введите корректную ссылку на Instagram']));
}

// --- ФУНКЦИЯ ЗАПРОСА К APIFY ---
function callApify($method, $endpoint, $data = null) {
    global $APIFY_TOKEN;
    
    $url = "https://api.apify.com/v2/$endpoint?token=" . $APIFY_TOKEN;
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

// --- ЛОГИРОВАНИЕ ---
function logMsg($msg) {
    file_put_contents(__DIR__ . '/apify_log.txt', date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

logMsg("📥 Запрос: $url");

try {
    // --- 1. ЗАПУСКАЕМ АКТОР ---
    logMsg("🚀 Запуск актора...");
    
    $run = callApify('POST', "acts/apify~instagram-scraper/runs", [
        'directUrls' => [$url],
        'resultsType' => 'posts',
        'resultsLimit' => 1,
        'addParentData' => false
    ]);
    
    logMsg("📊 Код ответа: {$run['code']}");
    
    if ($run['code'] !== 201 && $run['code'] !== 200) {
        $errorMsg = $run['body']['error']['message'] ?? json_encode($run['body']);
        throw new Exception("Ошибка запуска: $errorMsg");
    }
    
    $runId = $run['body']['data']['id'] ?? null;
    $datasetId = $run['body']['data']['defaultDatasetId'] ?? null;
    
    if (!$runId || !$datasetId) {
        throw new Exception("Не удалось получить ID запуска");
    }
    
    logMsg("✅ Актор запущен. Run ID: $runId, Dataset ID: $datasetId");
    logMsg("⏳ Ожидание завершения...");
    
    // --- 2. ЖДЕМ ЗАВЕРШЕНИЯ ---
    $result = null;
    $attempts = 0;
    $maxAttempts = 30;
    
    while ($attempts < $maxAttempts) {
        sleep(3);
        $attempts++;
        
        $status = callApify('GET', "actor-runs/$runId");
        $state = $status['body']['data']['status'] ?? 'UNKNOWN';
        $finishedAt = $status['body']['data']['finishedAt'] ?? null;
        
        logMsg("⏳ Попытка $attempts/$maxAttempts - Статус: $state");
        
        if ($finishedAt !== null || $state === 'SUCCEEDED' || $state === 'FAILED') {
            logMsg("✅ Актор завершен! Статус: $state");
            
            if ($state === 'SUCCEEDED') {
                // Получаем данные
                logMsg("📊 Получение данных из датасета...");
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://api.apify.com/v2/datasets/$datasetId/items?token=" . $GLOBALS['APIFY_TOKEN'] . "&format=json&limit=1");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $items = json_decode($response, true);
                logMsg("📊 Сырые данные: " . json_encode($items));
                
                // --- ПАРСИНГ ---
                $item = null;
                if (is_array($items) && count($items) > 0) {
                    $item = $items[0];
                } elseif (isset($items['data']['items']) && count($items['data']['items']) > 0) {
                    $item = $items['data']['items'][0];
                } elseif (isset($items['items']) && count($items['items']) > 0) {
                    $item = $items['items'][0];
                }
                
                if ($item) {
                    // --- ЗАГОЛОВОК ---
                    $caption = '';
                    if (isset($item['caption']) && !empty($item['caption'])) {
                        $caption = $item['caption'];
                    } elseif (isset($item['text']) && !empty($item['text'])) {
                        $caption = $item['text'];
                    } elseif (isset($item['title']) && !empty($item['title'])) {
                        $caption = $item['title'];
                    }
                    
                    if (strlen($caption) > 60) {
                        $caption = substr($caption, 0, 60) . '...';
                    }
                    
                    // ============================================
                    // ПРАВИЛЬНЫЙ ПАРСИНГ ЛАЙКОВ И ПРОСМОТРОВ
                    // ============================================
                    
                    // --- ЛАЙКИ ---
                    $likes = 0;
                    // Пробуем все возможные варианты
                    if (isset($item['likeCount'])) {
                        $likes = (int)$item['likeCount'];
                    } elseif (isset($item['likes'])) {
                        $likes = (int)$item['likes'];
                    } elseif (isset($item['like_count'])) {
                        $likes = (int)$item['like_count'];
                    } elseif (isset($item['likesCount'])) {
                        $likes = (int)$item['likesCount'];
                    } elseif (isset($item['diggCount'])) {
                        $likes = (int)$item['diggCount'];
                    } elseif (isset($item['favoriteCount'])) {
                        $likes = (int)$item['favoriteCount'];
                    } elseif (isset($item['reactions']) && is_array($item['reactions'])) {
                        $likes = (int)($item['reactions']['likes'] ?? $item['reactions']['like'] ?? 0);
                    }
                    
                    // --- ПРОСМОТРЫ ---
                    $views = 0;
                    // Пробуем все возможные варианты
                    if (isset($item['videoViewCount'])) {
                        $views = (int)$item['videoViewCount'];
                    } elseif (isset($item['videoPlayCount'])) {
                        $views = (int)$item['videoPlayCount'];
                    } elseif (isset($item['playCount'])) {
                        $views = (int)$item['playCount'];
                    } elseif (isset($item['viewCount'])) {
                        $views = (int)$item['viewCount'];
                    } elseif (isset($item['views'])) {
                        $views = (int)$item['views'];
                    } elseif (isset($item['video_views'])) {
                        $views = (int)$item['video_views'];
                    } elseif (isset($item['plays'])) {
                        $views = (int)$item['plays'];
                    } elseif (isset($item['view_count'])) {
                        $views = (int)$item['view_count'];
                    } elseif (isset($item['viewsCount'])) {
                        $views = (int)$item['viewsCount'];
                    }
                    
                    // --- КОММЕНТАРИИ ---
                    $comments = 0;
                    if (isset($item['commentCount'])) {
                        $comments = (int)$item['commentCount'];
                    } elseif (isset($item['comments'])) {
                        $comments = (int)$item['comments'];
                    } elseif (isset($item['comment_count'])) {
                        $comments = (int)$item['comment_count'];
                    } elseif (isset($item['commentsCount'])) {
                        $comments = (int)$item['commentsCount'];
                    }
                    
                    // --- ОБЛОЖКА ---
                    $thumbnail = null;
                    if (isset($item['thumbnailUrl']) && !empty($item['thumbnailUrl'])) {
                        $thumbnail = $item['thumbnailUrl'];
                    } elseif (isset($item['displayUrl']) && !empty($item['displayUrl'])) {
                        $thumbnail = $item['displayUrl'];
                    } elseif (isset($item['image']) && !empty($item['image'])) {
                        $thumbnail = $item['image'];
                    } elseif (isset($item['videoThumbnail']) && !empty($item['videoThumbnail'])) {
                        $thumbnail = $item['videoThumbnail'];
                    }
                    
                    // --- URL ---
                    $postUrl = $url;
                    if (isset($item['url']) && !empty($item['url'])) {
                        $postUrl = $item['url'];
                    } elseif (isset($item['postUrl']) && !empty($item['postUrl'])) {
                        $postUrl = $item['postUrl'];
                    }
                    
                    $result = [
                        'url' => $postUrl,
                        'views' => $views,
                        'likes' => $likes,
                        'title' => $caption ?: 'Reel без описания',
                        'thumbnailUrl' => $thumbnail,
                        'date' => date('c'), // БЕЗ ДАТЫ ИЗ INSTAGRAM
                        'comments' => $comments
                    ];
                    
                    logMsg("✅ Данные успешно получены: " . json_encode($result));
                    break;
                } else {
                    logMsg("⚠️ Не удалось найти элемент в данных");
                }
            } else {
                throw new Exception("Актор завершился с ошибкой: $state");
            }
            break;
        }
    }
    
    if (!$result) {
        throw new Exception("Не удалось получить данные. Попробуйте позже.");
    }
    
    // --- 3. УСПЕШНЫЙ ОТВЕТ ---
    logMsg("✅ Отправка успешного ответа");
    echo json_encode(['success' => true, 'data' => $result]);
    
} catch (Exception $e) {
    logMsg("❌ ОШИБКА: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}