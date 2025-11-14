<?php
include 'products.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'bootstrap.php';  // Flagship initialization

use Flagship\Flagship;
use Flagship\Hit\Event;
use Flagship\Hit\Item;
use Flagship\Hit\Transaction;
use Flagship\Enum\EventCategory;

$visitorId = flagshipVisitorId();

$checkoutFlowPreference = currentCheckoutFlowPreference();
$searchQuery = fetchSearchQueryForCountry('GB');
$context = [
    "company" => "Dyson",
];
if ($searchQuery) {
    $context["query"] = $searchQuery;
}

$visitor = Flagship::newVisitor($visitorId, true)
    ->setContext($context)
    ->build();

// IMPORTANT: fetch flags from Flagship backend
$visitor->fetchFlags();

if (!isset($_GET['id']) || !isset($products[$_GET['id']])) {
  header('Location: index.php');
  exit;
}

$id = (int)$_GET['id'];
$product = $products[$id];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $qty = max(1, (int)($_POST['quantity'] ?? 1));
  if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
  }
  if (isset($_SESSION['cart'][$id])) {
    $_SESSION['cart'][$id] += $qty;
  } else {
    $_SESSION['cart'][$id] = $qty;
  }

  try {
    $addToCartEvent = (new Event(EventCategory::USER_ENGAGEMENT, "add_to_cart"))
        ->setLabel($product['name'])
        ->setValue($qty);
    $visitor->sendHit($addToCartEvent);

    $pseudoOrderId = 'cart_' . time();

    $transactionHit = (new Transaction($pseudoOrderId, "cart_engagement"))
        ->setCurrency("USD")
        ->setItemCount($qty)
        ->setPaymentMethod("creditCard")
        ->setShippingCosts(0)
        ->setTaxes(0)
        ->setTotalRevenue($product['price'] * $qty)
        ->setShippingMethod("instant");
    $visitor->sendHit($transactionHit);

    $itemHit = (new Item($pseudoOrderId, $product['name'], 'sku_' . $id))
        ->setItemCategory("catalog")
        ->setItemPrice($product['price'])
        ->setItemQuantity($qty);
    $visitor->sendHit($itemHit);
  } catch (Exception $e) {
    error_log('Failed to send add_to_cart event: ' . $e->getMessage());
  }

  header('Location: cart.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($product['name']) ?> - Simple PHP Shop</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
  <div class="app-shell">
    <header>
      <div class="nav">
        <a class="brand" href="index.php">Simple PHP Shop</a>
        <div style="display:flex; gap:0.75rem;">
          <a class="pill-link" href="index.php">← All products</a>
          <a class="pill-link" href="cart.php">🛒 Cart</a>
        </div>
      </div>
    </header>

    <main>
      <section class="detail-layout">
        <article class="card detail-card">
          <img src="<?= $product['image'] ?>" alt="<?= htmlspecialchars($product['name']) ?>">
        </article>
        <article class="card detail-card" style="gap:1.5rem;">
          <div>
            <p class="badge">Flagship ready</p>
            <h1><?= htmlspecialchars($product['name']) ?></h1>
            <p class="muted"><?= htmlspecialchars($product['description']) ?></p>
          </div>
          <div>
            <p class="price">$<?= number_format($product['price'], 2) ?></p>
            <p class="muted">Ships in 2 days • Free developer samples</p>
          </div>

          <form method="POST">
            <label for="quantity">Quantity</label>
            <input type="number" id="quantity" name="quantity" value="1" min="1">
            <button class="primary-btn" type="submit">Add to cart</button>
          </form>
        </article>
      </section>
    </main>

    <footer>
      Need API toggles? Wire them up quickly with Flagship.
    </footer>
  </div>
  <?php include 'partials/log-panel.php'; ?>
</body>
</html>
