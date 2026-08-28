<?php
// ============================================
// Roblox Revival – Single File, SQLite
// Just save this as index.php and run:
// php -S localhost:8000
// ============================================

$db = new PDO('sqlite:revival.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create tables if they don't exist
$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        robux INTEGER DEFAULT 100,
        created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        price INTEGER NOT NULL,
        description TEXT
    );
    CREATE TABLE IF NOT EXISTS inventory (
        user_id INTEGER,
        item_id INTEGER,
        purchased_at INTEGER,
        FOREIGN KEY(user_id) REFERENCES users(id),
        FOREIGN KEY(item_id) REFERENCES items(id),
        PRIMARY KEY (user_id, item_id)
    );
    CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        token TEXT UNIQUE NOT NULL,
        expires_at INTEGER,
        FOREIGN KEY(user_id) REFERENCES users(id)
    );
");

// Seed items if empty
$count = $db->query("SELECT COUNT(*) FROM items")->fetchColumn();
if ($count == 0) {
    $db->exec("
        INSERT INTO items (name, price, description) VALUES
        ('Classic Sword', 50, 'A shiny blade for adventurers.'),
        ('Builders Hat', 100, 'Show off your building skills.'),
        ('Golden Wings', 200, 'Soar through the skies.')
    ");
}

// Helper functions
function redirect($url) { header('Location: ' . $url); exit; }
function isLoggedIn() {
    if (!isset($_COOKIE['session_token'])) return false;
    global $db;
    $stmt = $db->prepare("SELECT user_id FROM sessions WHERE token = ? AND expires_at > ?");
    $stmt->execute([$_COOKIE['session_token'], time()]);
    return $stmt->fetchColumn() !== false;
}
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    global $db;
    $stmt = $db->prepare("SELECT user_id FROM sessions WHERE token = ?");
    $stmt->execute([$_COOKIE['session_token']]);
    $userId = $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT id, username, robux FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function login($userId) {
    $token = bin2hex(random_bytes(32));
    $expires = time() + 86400 * 7;
    global $db;
    $stmt = $db->prepare("INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $token, $expires]);
    setcookie('session_token', $token, $expires, '/', '', false, true);
}
function logout() {
    if (isset($_COOKIE['session_token'])) {
        global $db;
        $stmt = $db->prepare("DELETE FROM sessions WHERE token = ?");
        $stmt->execute([$_COOKIE['session_token']]);
    }
    setcookie('session_token', '', time() - 3600, '/');
}

$user = getCurrentUser();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirm = $_POST['password_confirm'];
        if ($password !== $confirm) $message = "Passwords don't match.";
        elseif (strlen($username) < 3 || strlen($password) < 6) $message = "Username (min 3) and password (min 6) required.";
        else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)");
                $stmt->execute([$username, $hash, time()]);
                login($db->lastInsertId());
                redirect($_SERVER['REQUEST_URI']);
            } catch (PDOException $e) {
                $message = "Username already taken.";
            }
        }
    }
    if ($action === 'login') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $stmt = $db->prepare("SELECT id, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userData && password_verify($password, $userData['password_hash'])) {
            login($userData['id']);
            redirect($_SERVER['REQUEST_URI']);
        } else {
            $message = "Invalid username or password.";
        }
    }
    if ($action === 'buy' && $user) {
        $itemId = intval($_POST['item_id']);
        $stmt = $db->prepare("SELECT price FROM items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item && $user['robux'] >= $item['price']) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("UPDATE users SET robux = robux - ? WHERE id = ?");
                $stmt->execute([$item['price'], $user['id']]);
                $stmt = $db->prepare("INSERT OR IGNORE INTO inventory (user_id, item_id, purchased_at) VALUES (?, ?, ?)");
                $stmt->execute([$user['id'], $itemId, time()]);
                $db->commit();
                $message = "Purchased!";
            } catch (Exception $e) {
                $db->rollBack();
                $message = "Purchase failed.";
            }
            $user = getCurrentUser();
        } else {
            $message = "Not enough Robux or item not found.";
        }
    }
    if ($action === 'logout') {
        logout();
        redirect($_SERVER['REQUEST_URI']);
    }
}

$items = $db->query("SELECT * FROM items")->fetchAll(PDO::FETCH_ASSOC);
if ($user) {
    $stmt = $db->prepare("SELECT i.* FROM inventory inv JOIN items i ON inv.item_id = i.id WHERE inv.user_id = ?");
    $stmt->execute([$user['id']]);
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $inventory = [];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Roblox Revival</title>
    <style>
        body { font-family: Arial, sans-serif; background: #222; color: #eee; padding: 20px; }
        .container { max-width: 900px; margin: auto; background: #333; padding: 20px; border-radius: 8px; }
        h1, h2 { color: #ffd700; }
        .flash { background: #444; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .auth-form { display: inline-block; margin-right: 20px; vertical-align: top; }
        .auth-form input { display: block; margin: 5px 0; padding: 6px; width: 200px; }
        .auth-form button { padding: 6px 16px; background: #ffd700; border: none; border-radius: 4px; cursor: pointer; }
        .items { display: flex; flex-wrap: wrap; gap: 20px; }
        .item { background: #444; padding: 15px; border-radius: 8px; width: 180px; }
        .item .price { color: #ffd700; }
        .btn { background: #ffd700; color: #222; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #555; padding-bottom: 10px; }
        .robux { color: #ffd700; font-weight: bold; }
        .logout { background: #e74c3c; color: white; padding: 4px 12px; border-radius: 4px; text-decoration: none; }
        .inventory { margin-top: 30px; }
        .inventory-item { display: inline-block; margin: 5px 10px; background: #555; padding: 6px 12px; border-radius: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Roblox Revival</h1>
        <?php if ($user): ?>
            <div>
                <span class="robux">🪙 <?= $user['robux'] ?></span>
                <span style="margin-left:15px;">Welcome, <?= htmlspecialchars($user['username']) ?></span>
                <a href="?logout=1" class="logout" style="margin-left:15px;">Logout</a>
            </div>
        <?php else: ?>
            <span>Not logged in</span>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="flash"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!$user): ?>
        <div style="margin:20px 0;">
            <div class="auth-form">
                <h3>Login</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <input type="text" name="username" placeholder="Username" required>
                    <input type="password" name="password" placeholder="Password" required>
                    <button type="submit">Login</button>
                </form>
            </div>
            <div class="auth-form">
                <h3>Register</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <input type="text" name="username" placeholder="Username" required>
                    <input type="password" name="password" placeholder="Password (min 6)" required>
                    <input type="password" name="password_confirm" placeholder="Confirm Password" required>
                    <button type="submit">Register</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <h2>Catalog</h2>
        <div class="items">
            <?php foreach ($items as $item): ?>
                <div class="item">
                    <h3><?= htmlspecialchars($item['name']) ?></h3>
                    <p><?= htmlspecialchars($item['description']) ?></p>
                    <p class="price">🪙 <?= $item['price'] ?></p>
                    <form method="POST">
                        <input type="hidden" name="action" value="buy">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <button type="submit" class="btn">Buy</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="inventory">
            <h2>Your Inventory</h2>
            <?php if (empty($inventory)): ?>
                <p>No items yet. Buy something!</p>
            <?php else: ?>
                <?php foreach ($inventory as $item): ?>
                    <span class="inventory-item"><?= htmlspecialchars($item['name']) ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (isset($_GET['logout'])): ?>
            <?php logout(); redirect($_SERVER['REQUEST_URI']); ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
