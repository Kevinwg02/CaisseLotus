<?php
// totaux.php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=bar', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Erreur connexion BDD : " . $e->getMessage());
}

// --- Gérer ouverture / fermeture / reset ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Ouvrir un casier
    if ($action === 'ouvrir' && isset($_POST['client_id'])) {
        $pdo->prepare("UPDATE clients SET ouvert = 1 WHERE id = ?")->execute([intval($_POST['client_id'])]);
    }

    // Fermer + réinitialiser un casier
    if ($action === 'fermer' && isset($_POST['client_id'])) {
        $client_id = intval($_POST['client_id']);
        $stmt = $pdo->prepare("
            SELECT SUM(cons.quantite * b.prix) AS total, c.casier
            FROM consommations cons
            JOIN boissons b ON b.id = cons.boisson_id
            JOIN clients c ON c.id = cons.client_id
            WHERE c.id = ?
        ");
        $stmt->execute([$client_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = floatval($result['total'] ?? 0);
        $casier = intval($result['casier'] ?? 0);

        if ($total > 0) {
            $pdo->prepare("INSERT INTO earnings (casier, total) VALUES (?, ?)")->execute([$casier, $total]);
        }

        $pdo->prepare("DELETE FROM consommations WHERE client_id = ?")->execute([$client_id]);
        $pdo->prepare("UPDATE clients SET ouvert = 0 WHERE id = ?")->execute([$client_id]);
    }

    // Réinitialiser le total de la journée
    if ($action === 'reset_day') {
        $pdo->exec("DELETE FROM earnings");
    }

    header("Location: totaux.php");
    exit;
}

// --- Charger les casiers ---
$clients = $pdo->query("SELECT id, casier, ouvert FROM clients ORDER BY casier")->fetchAll(PDO::FETCH_ASSOC);

$casier_data = [];
foreach ($clients as $c) {
    $casier_data[$c['id']] = [
        'casier' => $c['casier'],
        'ouvert' => (bool)$c['ouvert'],
        'boissons' => [],
        'total_casier' => 0.0
    ];
}

$sql = "
    SELECT c.id AS client_id, c.casier, b.nom AS boisson, b.prix AS prix_unitaire,
           SUM(cons.quantite) AS quantite,
           SUM(cons.quantite * b.prix) AS total_prix
    FROM clients c
    LEFT JOIN consommations cons ON cons.client_id = c.id
    LEFT JOIN boissons b ON b.id = cons.boisson_id
    WHERE c.ouvert = 1
    GROUP BY c.id, b.id
    ORDER BY c.casier, b.nom
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    if (!$row['boisson']) continue;
    $id = $row['client_id'];
    $casier_data[$id]['boissons'][] = [
        'nom' => $row['boisson'],
        'quantite' => intval($row['quantite']),
        'prix_unitaire' => floatval($row['prix_unitaire']),
        'total_prix' => floatval($row['total_prix'])
    ];
    $casier_data[$id]['total_casier'] += floatval($row['total_prix']);
}

// --- Total journée ---
$day_total = $pdo->query("SELECT SUM(total) FROM earnings WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?? 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Gestion des Casiers</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="css/totaux.css">
</head>
<body>

<div class="header">
  <h1>Gestion des Casiers</h1>
  <div>
    <button class="btn btn-refresh" onclick="location.reload()">🔄</button>
    <button class="btn btn-refresh" onclick="openModal()">💰</button>
  </div>
</div>

<h2 style="margin-top:30px;">Casiers fermés</h2>
<div class="closed-grid">
<?php foreach($casier_data as $cid=>$data): ?>
  <?php if(!$data['ouvert']): ?>
  <form method="post">
    <input type="hidden" name="client_id" value="<?= $cid ?>">
    <button class="casier-btn" name="action" value="ouvrir"><?= htmlspecialchars($data['casier']) ?></button>
  </form>
  <?php endif; ?>
<?php endforeach; ?>
</div>
<br><br><br>
<div class="grid">
<?php foreach($casier_data as $cid=>$data): ?>
  <?php if($data['ouvert']): ?>
  <div class="casier-card">
    <div class="casier-top">
      <h2>Casier <?= htmlspecialchars($data['casier']) ?></h2>
      <div class="casier-total"><?= number_format($data['total_casier'],2,',',' ') ?> €</div>
    </div>
    <?php if(empty($data['boissons'])): ?>
      <p style="margin-top:15px;text-align:center;color:#aaa;">Aucune consommation</p>
    <?php else: ?>
      <div class="boisson-list">
      <?php foreach($data['boissons'] as $b): ?>
        <div class="boisson-item">
          <div><?= htmlspecialchars($b['nom']) ?></div>
          <small><?= $b['quantite'] ?> × <?= number_format($b['prix_unitaire'],2,',',' ') ?>€ = <?= number_format($b['total_prix'],2,',',' ') ?>€</small>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" style="margin-top:10px;text-align:right;">
      <input type="hidden" name="client_id" value="<?= $cid ?>">
      <button class="btn btn-close" name="action" value="fermer" onclick="return confirm('Fermer et réinitialiser ce casier ?')">Fermer</button>
    </form>
  </div>
  <?php endif; ?>
<?php endforeach; ?>
</div>

<!-- MODAL TOTAL JOURNÉE -->
<div id="modal" class="modal">
  <div class="modal-content">
    <h2>Total de la journée</h2>
    <p style="font-size:1.8em;font-weight:800;"><?= number_format($day_total,2,',',' ') ?> €</p>

    <form method="post" onsubmit="return confirm('Voulez-vous vraiment réinitialiser le total de la journée ?')">
      <button class="reset-btn" name="action" value="reset_day">Réinitialiser</button>
    </form>

    <button class="close-btn" onclick="closeModal()">Fermer</button>
  </div>
</div>

<script>
function openModal(){document.getElementById('modal').style.display='flex';}
function closeModal(){document.getElementById('modal').style.display='none';}
</script>
</body>
</html>
