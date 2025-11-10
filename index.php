<?php
$pdo = new PDO('mysql:host=localhost;dbname=bar', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Récupération des boissons
$boissons = $pdo->query("SELECT * FROM boissons")->fetchAll(PDO::FETCH_ASSOC);

// Récupération des clients ouverts
$clients = $pdo->query("SELECT id, casier FROM clients WHERE ouvert = 1 ORDER BY casier")->fetchAll(PDO::FETCH_ASSOC);

// Traitement des actions + / -
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['boisson_id'], $_POST['client_id'], $_POST['action'])) {
    $client_id = intval($_POST['client_id']);
    $boisson_id = intval($_POST['boisson_id']);
    $action = $_POST['action'];

    $stmt = $pdo->prepare("SELECT id, quantite FROM consommations WHERE client_id=? AND boisson_id=?");
    $stmt->execute([$client_id, $boisson_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($action === 'add') {
        if ($existing) {
            $pdo->prepare("UPDATE consommations SET quantite = quantite + 1 WHERE id=?")->execute([$existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO consommations (client_id, boisson_id, quantite) VALUES (?, ?, 1)")->execute([$client_id, $boisson_id]);
        }
    }

    if ($action === 'remove' && $existing && $existing['quantite'] > 0) {
        $pdo->prepare("UPDATE consommations SET quantite = quantite - 1 WHERE id=?")->execute([$existing['id']]);
    }

    header("Location: index.php?client_id=$client_id");
    exit;
}

// Casier sélectionné
$selected_client_id = $_GET['client_id'] ?? ($clients[0]['id'] ?? null);

// Récupération des consommations du casier
$consommations = [];
if ($selected_client_id) {
    $stmt = $pdo->prepare("SELECT boisson_id, quantite FROM consommations WHERE client_id=?");
    $stmt->execute([$selected_client_id]);
    $consommations = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Caisse Bar - Interface Tactile</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="css/index.css">
</head>
<body>

<h1>Caisse Bar</h1>

<!-- Sélection de casier tactile -->
<div class="casier-grid">
    <?php foreach($clients as $c): ?>
        <button class="casier-btn <?= $selected_client_id == $c['id'] ? 'selected' : '' ?>"
                onclick="window.location='?client_id=<?= $c['id'] ?>'">
            <?= htmlspecialchars($c['casier']) ?>
        </button>
    <?php endforeach; ?>
</div>

<!-- Liste des boissons -->
<div class="boissons">
    <?php foreach($boissons as $boisson): ?>
        <div class="boisson-card">
            <div><?= htmlspecialchars($boisson['nom']) ?></div>
            <form method="post">
                <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
                <input type="hidden" name="boisson_id" value="<?= $boisson['id'] ?>">
                <button type="submit" name="action" value="remove" class="remove-btn">−</button>
                <button type="submit" name="action" value="add" class="add-btn">+</button>
            </form>
            <div class="counter"><?= $consommations[$boisson['id']] ?? 0 ?></div>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>
