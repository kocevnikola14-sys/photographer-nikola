<?php
/**
 * Photographer Nikola - Server-side Gallery Upload & Management
 * Works with Nginx + PHP on /var/www/portfolio_test (or any root directory)
 */

header('Content-Type: application/json; charset=utf-8');

// Disable error display in output to ensure clean JSON responses
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Locate project root directory (one level up from /admin)
$rootDir = dirname(__DIR__);
$deliverDir = $rootDir . '/deliver';
$adminLoginFile = $rootDir . '/admin_login';
if (!file_exists($adminLoginFile) && file_exists($rootDir . '/admin_login.txt')) {
    $adminLoginFile = $rootDir . '/admin_login.txt';
}
$eventsFile = $deliverDir . '/events.txt';

// Helper: send JSON response and exit
function jsonResponse($success, $data = [], $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: verify admin credentials against admin_login file
function verifyAdmin($user, $pass, $adminLoginFile) {
    if (empty($user) || empty($pass)) {
        return false;
    }
    if (!file_exists($adminLoginFile)) {
        // Fallback hardcoded if file is missing
        return ($user === 'Nikola_user' && $pass === 'Password_123');
    }

    $content = file_get_contents($adminLoginFile);
    $lines = preg_split('/\r\n|\r|\n/', trim($content));

    // Check "username : password" on single line
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, ':') !== false) {
            $parts = explode(':', $line, 2);
            $u = trim($parts[0]);
            $p = trim($parts[1]);
            if ($u === $user && $p === $pass) {
                return true;
            }
        }
    }

    // Check line 1 = user, line 2 = pass
    if (count($lines) >= 2 && trim($lines[0]) === $user && trim($lines[1]) === $pass) {
        return true;
    }

    return false;
}

// Helper: sanitize event / folder name
function sanitizeEventName($name) {
    // Allow letters, numbers, spaces, dashes, underscores
    $clean = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $name);
    $clean = trim(preg_replace('/\s+/', ' ', $clean));
    return $clean;
}

// Helper: sanitize filename
function sanitizeFileName($filename) {
    $info = pathinfo($filename);
    $ext = isset($info['extension']) ? strtolower($info['extension']) : 'jpg';
    $base = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $info['filename']);
    $base = trim($base);
    if (empty($base)) $base = 'photo_' . time();
    return $base . '.' . $ext;
}

// Helper: recursively delete a directory safely inside a specified parent directory
function deleteDirectoryRecursive($dir, $baseDir) {
    if (!is_dir($dir)) {
        return false;
    }
    $realTarget = realpath($dir);
    $realBase = realpath($baseDir);
    // Security check: target must be inside base directory and must not BE the base directory
    if (!$realTarget || !$realBase || strpos($realTarget, $realBase) !== 0 || $realTarget === $realBase) {
        return false;
    }

    $items = array_diff(scandir($realTarget), ['.', '..']);
    foreach ($items as $item) {
        $path = $realTarget . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectoryRecursive($path, $realBase);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($realTarget);
}

// Check for post_max_size overflow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    $max = ini_get('post_max_size');
    jsonResponse(false, ['error' => "Velikost fotografije presega PHP omejitev (post_max_size = {$max}). Povečajte upload_max_filesize in post_max_size v /etc/php/.../fpm/php.ini na 64M."], 413);
}

// Read action and credentials from POST, GET, or Headers
$action = !empty($_POST['action']) ? $_POST['action'] : (!empty($_GET['action']) ? $_GET['action'] : '');
$adminUser = !empty($_POST['adminUser']) ? trim($_POST['adminUser']) : (!empty($_GET['adminUser']) ? trim($_GET['adminUser']) : (!empty($_GET['u']) ? trim($_GET['u']) : (!empty($_SERVER['HTTP_X_ADMIN_USER']) ? trim($_SERVER['HTTP_X_ADMIN_USER']) : '')));
$adminPass = !empty($_POST['adminPass']) ? trim($_POST['adminPass']) : (!empty($_GET['adminPass']) ? trim($_GET['adminPass']) : (!empty($_GET['p']) ? trim($_GET['p']) : (!empty($_SERVER['HTTP_X_ADMIN_PASS']) ? trim($_SERVER['HTTP_X_ADMIN_PASS']) : '')));

// 1. Authenticate admin
if (!verifyAdmin($adminUser, $adminPass, $adminLoginFile)) {
    jsonResponse(false, ['error' => 'Neveljavni skrbniški podatki. Prosimo, odjavite se in prijavite ponovno.'], 401);
}

// Ensure deliver directory exists
if (!is_dir($deliverDir)) {
    @mkdir($deliverDir, 0775, true);
}

switch ($action) {

    // ── ACTION: INIT GALLERY ──
    case 'init':
        $eventName = isset($_POST['eventName']) ? sanitizeEventName($_POST['eventName']) : '';
        $eventPassword = isset($_POST['eventPassword']) ? trim($_POST['eventPassword']) : '';

        if (empty($eventName)) {
            jsonResponse(false, ['error' => 'Ime dogodka ne sme biti prazno.']);
        }
        if (empty($eventPassword)) {
            jsonResponse(false, ['error' => 'Geslo za stranko ne sme biti prazno.']);
        }

        $eventDir = $deliverDir . '/' . $eventName;
        if (!is_dir($eventDir)) {
            if (!@mkdir($eventDir, 0775, true)) {
                jsonResponse(false, ['error' => "Ni bilo mogoče ustvariti mape: deliver/{$eventName}. Preverite dovoljenja na strežniku (chmod/chown)."]);
            }
        }

        // Update or append to deliver/events.txt
        $existing = file_exists($eventsFile) ? file_get_contents($eventsFile) : '';
        $lines = preg_split('/\r\n|\r|\n/', trim($existing));
        $newLines = [];
        $found = false;

        foreach ($lines as $l) {
            $l = trim($l);
            if (empty($l)) continue;
            if (strpos($l, ':') !== false) {
                $parts = explode(':', $l, 2);
                if (trim($parts[0]) === $eventName) {
                    $newLines[] = "{$eventName} : {$eventPassword}";
                    $found = true;
                    continue;
                }
            }
            $newLines[] = $l;
        }

        if (!$found) {
            $newLines[] = "{$eventName} : {$eventPassword}";
        }

        $eventsContent = implode("\n", $newLines) . "\n";
        @file_put_contents($eventsFile, $eventsContent);
        // Also keep extensionless events file in sync
        @file_put_contents($deliverDir . '/events', $eventsContent);

        jsonResponse(true, [
            'message' => 'Galerija pripravljena na nalaganje.',
            'eventName' => $eventName
        ]);
        break;

    // ── ACTION: UPLOAD SINGLE PHOTO & THUMBNAIL ──
    case 'upload_file':
        $eventName = isset($_POST['eventName']) ? sanitizeEventName($_POST['eventName']) : '';
        if (empty($eventName)) {
            jsonResponse(false, ['error' => 'Manjka ime dogodka.']);
        }

        $eventDir = $deliverDir . '/' . $eventName;
        if (!is_dir($eventDir)) {
            jsonResponse(false, ['error' => 'Mapa galerije ne obstaja. Zaženite najprej init.']);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, ['error' => 'Napaka pri prenosu originalne fotografije.']);
        }

        $origName = sanitizeFileName($_FILES['file']['name']);
        $origTarget = $eventDir . '/' . $origName;

        // Move full-resolution image
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $origTarget)) {
            jsonResponse(false, ['error' => "Napaka pri shranjevanju: {$origName}"]);
        }
        @chmod($origTarget, 0664);

        // Handle thumbnail: either sent from browser canvas or generated
        $dotIndex = strrpos($origName, '.');
        $base = ($dotIndex !== false) ? substr($origName, 0, $dotIndex) : $origName;
        $thumbName = $base . '_thumbnail.jpg';
        $thumbTarget = $eventDir . '/' . $thumbName;

        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['thumbnail']['tmp_name'], $thumbTarget);
            @chmod($thumbTarget, 0664);
        } else {
            // If browser didn't supply thumbnail, copy original as fallback
            @copy($origTarget, $thumbTarget);
            @chmod($thumbTarget, 0664);
        }

        jsonResponse(true, [
            'filename' => $origName,
            'thumbnail' => $thumbName
        ]);
        break;

    // ── ACTION: FINALIZE MANIFEST.JSON ──
    case 'finalize':
        $eventName = isset($_POST['eventName']) ? sanitizeEventName($_POST['eventName']) : '';
        $filesJson = isset($_POST['files']) ? $_POST['files'] : '[]';
        $files = json_decode($filesJson, true);

        if (empty($eventName)) {
            jsonResponse(false, ['error' => 'Manjka ime dogodka.']);
        }

        $eventDir = $deliverDir . '/' . $eventName;
        if (!is_dir($eventDir)) {
            jsonResponse(false, ['error' => 'Mapa galerije ne obstaja.']);
        }

        if (!is_array($files) || empty($files)) {
            // Scan directory if no file list provided
            $scanned = scandir($eventDir);
            $files = [];
            foreach ($scanned as $f) {
                if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp']) && strpos($f, '_thumbnail') === false) {
                    $files[] = $f;
                }
            }
            sort($files);
        }

        $manifestPath = $eventDir . '/manifest.json';
        file_put_contents($manifestPath, json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($manifestPath, 0664);

        jsonResponse(true, [
            'message' => 'Galerija je bila uspešno zaključena in manifest ustvarjen.',
            'eventName' => $eventName,
            'count' => count($files)
        ]);
        break;

    // ── ACTION: DELETE EVENT ──
    case 'delete_event':
        $eventName = isset($_POST['eventName']) ? sanitizeEventName($_POST['eventName']) : '';
        if (empty($eventName)) {
            jsonResponse(false, ['error' => 'Manjka ime dogodka.']);
        }

        if (file_exists($eventsFile)) {
            $existing = file_get_contents($eventsFile);
            $lines = preg_split('/\r\n|\r|\n/', trim($existing));
            $newLines = [];
            foreach ($lines as $l) {
                $l = trim($l);
                if (empty($l)) continue;
                if (strpos($l, ':') !== false) {
                    $parts = explode(':', $l, 2);
                    if (trim($parts[0]) === $eventName) continue; // skip
                }
                $newLines[] = $l;
            }
            $updated = implode("\n", $newLines) . "\n";
            file_put_contents($eventsFile, $updated);
            @file_put_contents($deliverDir . '/events', $updated);
        }

        // 2. Delete the actual gallery folder and all its images/thumbnails inside deliver/
        $eventDir = $deliverDir . '/' . $eventName;
        $dirDeleted = false;
        if (is_dir($eventDir)) {
            $dirDeleted = deleteDirectoryRecursive($eventDir, $deliverDir);
        }

        jsonResponse(true, [
            'message' => "Galerija '{$eventName}' in vsa njena vsebina sta bili uspešno izbrisani.",
            'dirDeleted' => $dirDeleted
        ]);
        break;

    default:
        jsonResponse(false, ['error' => 'Neznana akcija.'], 400);
}
