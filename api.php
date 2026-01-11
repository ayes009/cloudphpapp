<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Azure Storage Configuration
define('STORAGE_ACCOUNT', 'photosharestorag');
define('CONTAINER_NAME', 'photos');
define('METADATA_CONTAINER', 'metadata');
define('SAS_TOKEN', 'sv=2024-11-04&ss=b&srt=co&sp=rwdlactfx&se=2027-01-11T08:43:29Z&st=2026-01-11T00:28:29Z&spr=https&sig=uXXyKqLml7g2mgRhQBFJbQjzRCfml7Mk9eyyjpWGL3w%3D');
define('BLOB_SERVICE_URL', 'https://' . STORAGE_ACCOUNT . '.blob.core.windows.net');

// Get the request path and clean it
$request_uri = $_SERVER['REQUEST_URI'];
$request_method = $_SERVER['REQUEST_METHOD'];

// Remove query string and base path
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove /api prefix if present
$path = preg_replace('#^/api#', '', $path);

// Log for debugging
error_log("Request: $request_method $path");

// Route handling
if ($path === '/login' && $request_method === 'POST') {
    handleLogin();
} 
elseif ($path === '/photos' && $request_method === 'GET') {
    getPhotos();
} 
elseif ($path === '/photos' && $request_method === 'POST') {
    uploadPhoto();
} 
elseif (preg_match('#^/photos/([^/]+)$#', $path, $matches) && $request_method === 'DELETE') {
    deletePhoto($matches[1]);
} 
elseif (preg_match('#^/photos/([^/]+)/like$#', $path, $matches) && $request_method === 'POST') {
    likePhoto($matches[1]);
} 
elseif (preg_match('#^/photos/([^/]+)/rate$#', $path, $matches) && $request_method === 'POST') {
    ratePhoto($matches[1]);
} 
elseif (preg_match('#^/photos/([^/]+)/comments$#', $path, $matches) && $request_method === 'POST') {
    addComment($matches[1]);
} 
else {
    http_response_code(404);
    echo json_encode([
        'error' => 'Endpoint not found',
        'path' => $path,
        'method' => $request_method,
        'debug' => [
            'request_uri' => $request_uri,
            'available_endpoints' => [
                'POST /login',
                'GET /photos',
                'POST /photos',
                'DELETE /photos/{id}',
                'POST /photos/{id}/like',
                'POST /photos/{id}/rate',
                'POST /photos/{id}/comments'
            ]
        ]
    ]);
}

// ============================================
// API Handlers
// ============================================

function handleLogin() {
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? 'Guest' . rand(100, 999);
    $role = $data['role'] ?? 'consumer';
    
    $user = [
        'id' => (string)time(),
        'username' => $username,
        'role' => $role,
        'token' => base64_encode($username . ':' . time())
    ];
    
    echo json_encode([
        'user' => $user,
        'message' => 'Welcome to PhotoShare!'
    ]);
}

function getPhotos() {
    $photos = [];
    
    // List all metadata blobs
    $url = BLOB_SERVICE_URL . '/' . METADATA_CONTAINER . '?restype=container&comp=list&' . SAS_TOKEN;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-ms-version: 2020-04-08']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $xml = simplexml_load_string($response);
        
        if ($xml && isset($xml->Blobs->Blob)) {
            foreach ($xml->Blobs->Blob as $blob) {
                $blobName = (string)$blob->Name;
                
                if (strpos($blobName, '.json') !== false) {
                    // Get blob content
                    $blobUrl = BLOB_SERVICE_URL . '/' . METADATA_CONTAINER . '/' . $blobName . '?' . SAS_TOKEN;
                    
                    $ch = curl_init($blobUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $blobContent = curl_exec($ch);
                    curl_close($ch);
                    
                    if ($blobContent) {
                        $photo = json_decode($blobContent, true);
                        if ($photo) {
                            $photos[] = $photo;
                        }
                    }
                }
            }
        }
    }
    
    // Sort by upload date (newest first)
    usort($photos, function($a, $b) {
        return strtotime($b['uploadedAt']) - strtotime($a['uploadedAt']);
    });
    
    echo json_encode($photos);
}

function uploadPhoto() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $title = $data['title'] ?? '';
    $caption = $data['caption'] ?? '';
    $location = $data['location'] ?? '';
    $tags = $data['tags'] ?? '';
    $imageData = $data['imageData'] ?? '';
    $fileName = $data['fileName'] ?? 'image.jpg';
    
    if (empty($title) || empty($imageData)) {
        http_response_code(400);
        echo json_encode(['error' => 'Title and imageData required']);
        return;
    }
    
    // Extract username from auth header
    $username = 'Anonymous';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
        $decoded = base64_decode($token);
        $parts = explode(':', $decoded);
        $username = $parts[0] ?? 'Anonymous';
    }
    
    $photoId = (string)time() . rand(100, 999);
    $blobName = $photoId . '-' . preg_replace('/[^a-zA-Z0-9.-]/', '_', $fileName);
    
    // Decode base64 image
    if (strpos($imageData, ',') !== false) {
        $imageData = explode(',', $imageData)[1];
    }
    $imageBytes = base64_decode($imageData);
    
    // Determine content type
    $contentType = 'image/jpeg';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === 'png') $contentType = 'image/png';
    elseif ($ext === 'gif') $contentType = 'image/gif';
    elseif ($ext === 'webp') $contentType = 'image/webp';
    
    // Upload image to Azure Blob
    $imageUrl = BLOB_SERVICE_URL . '/' . CONTAINER_NAME . '/' . $blobName . '?' . SAS_TOKEN;
    
    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $imageBytes);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-ms-blob-type: BlockBlob',
        'x-ms-version: 2020-04-08',
        'Content-Type: ' . $contentType,
        'Content-Length: ' . strlen($imageBytes)
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 201) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to upload image to blob storage', 'httpCode' => $httpCode]);
        return;
    }
    
    $imagePublicUrl = BLOB_SERVICE_URL . '/' . CONTAINER_NAME . '/' . $blobName . '?' . SAS_TOKEN;
    
    // Create photo metadata
    $photo = [
        'id' => $photoId,
        'title' => $title,
        'caption' => $caption,
        'location' => $location,
        'tags' => $tags,
        'url' => $imagePublicUrl,
        'creatorName' => $username,
        'likes' => 0,
        'comments' => [],
        'rating' => 0,
        'ratingCount' => 0,
        'uploadedAt' => date('c')
    ];
    
    // Save metadata
    $metadataUrl = BLOB_SERVICE_URL . '/' . METADATA_CONTAINER . '/' . $photoId . '.json?' . SAS_TOKEN;
    $metadataJson = json_encode($photo);
    
    $ch = curl_init($metadataUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $metadataJson);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-ms-blob-type: BlockBlob',
        'x-ms-version: 2020-04-08',
        'Content-Type: application/json',
        'Content-Length: ' . strlen($metadataJson)
    ]);
    curl_exec($ch);
    curl_close($ch);
    
    http_response_code(201);
    echo json_encode($photo);
}

function deletePhoto($photoId) {
    // Get metadata
    $metadataUrl = BLOB_SERVICE_URL . '/' . METADATA_CONTAINER . '/' . $photoId . '.json?' . SAS_TOKEN;
    
    $ch = curl_init($metadataUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $metadataContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        http_response_code(404);
        echo json_encode(['error' => 'Photo not found']);
        return;
    }
    
    $photo = json_decode($metadataContent, true);
    
    // Extract blob name from URL
    $urlParts = parse_url($photo['url']);
    $path = $urlParts['path'];
    $blobName = basename($path);
    
    // Delete image blob
    $imageUrl = BLOB_SERVICE_URL . '/' . CONTAINER_NAME . '/' . $blobName . '?' . SAS_TOKEN;
    
    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-ms-version: 2020-04-08']);
    curl_exec($ch);
    curl_close($ch);
    
    // Delete metadata blob
    $ch = curl_init($metadataUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-ms-version: 2020-04-08']);
    curl_exec($ch);
    curl_close($ch);
    
    echo json_encode(['message' => 'Photo deleted', 'photoId' => $photoId]);
}

function likePhoto($photoId) {
    $photo = getPhotoMetadata($photoId);
    
    if (!$photo) {
        http_response_code(404);
        echo json_encode(['error' => 'Photo not found']);
        return;
    }
    
    $photo['likes'] = ($photo['likes'] ?? 0) + 1;
    
    savePhotoMetadata($photoId, $photo);
    
    echo json_encode(['success' => true, 'likes' => $photo['likes']]);
}

function ratePhoto($photoId) {
    $data = json_decode(file_get_contents('php://input'), true);
    $rating = $data['rating'] ?? 0;
    
    if ($rating < 1 || $rating > 5) {
        http_response_code(400);
        echo json_encode(['error' => 'Rating must be 1-5']);
        return;
    }
    
    $photo = getPhotoMetadata($photoId);
    
    if (!$photo) {
        http_response_code(404);
        echo json_encode(['error' => 'Photo not found']);
        return;
    }
    
    $currentRating = $photo['rating'] ?? 0;
    $currentCount = $photo['ratingCount'] ?? 0;
    $newCount = $currentCount + 1;
    
    $photo['rating'] = (($currentRating * $currentCount) + $rating) / $newCount;
    $photo['ratingCount'] = $newCount;
    
    savePhotoMetadata($photoId, $photo);
    
    echo json_encode([
        'success' => true,
        'rating' => $photo['rating'],
        'ratingCount' => $photo['ratingCount']
    ]);
}

function addComment($photoId) {
    $data = json_decode(file_get_contents('php://input'), true);
    $text = $data['text'] ?? '';
    
    if (empty($text)) {
        http_response_code(400);
        echo json_encode(['error' => 'Comment text required']);
        return;
    }
    
    $photo = getPhotoMetadata($photoId);
    
    if (!$photo) {
        http_response_code(404);
        echo json_encode(['error' => 'Photo not found']);
        return;
    }
    
    // Extract username
    $username = 'Anonymous';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
        $decoded = base64_decode($token);
        $parts = explode(':', $decoded);
        $username = $parts[0] ?? 'Anonymous';
    }
    
    $comment = [
        'id' => (string)time(),
        'userId' => (string)time(),
        'username' => $username,
        'text' => $text,
        'timestamp' => date('c')
    ];
    
    if (!isset($photo['comments'])) {
        $photo['comments'] = [];
    }
    $photo['comments'][] = $comment;
    
    savePhotoMetadata($photoId, $photo);
    
    http_response_code(201);
    echo json_encode($comment);
}

// ============================================
// Helper Functions
// ============================================

function getPhotoMetadata($photoId) {
    $url = BLOB_SERVICE_URL . '/' . METADATA_CONTAINER . '/' . $photoId . '.json?' . SAS_TOKEN;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    return json_decode($content, true);
}

function savePhotoMetadata($photoId, $photo) {
    $url = BLOB_SERVICE_URL . '/' . METADATA_CONTAINER . '/' . $photoId . '.json?' . SAS_TOKEN;
    $json = json_encode($photo);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-ms-blob-type: BlockBlob',
        'x-ms-version: 2020-04-08',
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json)
    ]);
    curl_exec($ch);
    curl_close($ch);
}
?>
