<?php

include 'products.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'bootstrap.php';  // Flagship initialization

use Flagship\Flagship;

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Simple PHP Shop - Home</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
  <div class="app-shell">
    <header>
      <div class="nav">
        <a class="brand" href="index.php">Simple PHP Shop</a>
        <a class="pill-link" href="cart.php">🛒 Cart</a>
      </div>
    </header>

    <main>
      <section class="hero">
        <p class="badge">Developer Preview</p>
        <h1>Welcome to Simple PHP Shop</h1>
        <p>
          Minimal, fast, and Flagship-ready. Explore a curated selection of products
          while experimenting with feature flags in a no-fuss environment.
        </p>
      </section>

      <section class="product-grid" aria-label="Product catalog">
        <?php foreach ($products as $id => $product): ?>
          <article class="card product-card">
            <a href="product.php?id=<?= $id ?>">
              <img src="<?= $product['image'] ?>" alt="<?= htmlspecialchars($product['name']) ?>">
            </a>
            <div>
              <h2><?= htmlspecialchars($product['name']) ?></h2>
              <p class="muted"><?= htmlspecialchars($product['description']) ?></p>
            </div>
            <div class="price">$<?= number_format($product['price'], 2) ?></div>
            <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
              <a class="primary-btn" href="product.php?id=<?= $id ?>">View details</a>
              <a class="ghost-btn" href="cart.php">Go to cart</a>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    </main>

    <footer>
      Built with PHP + Flagship SDK • Perfect for quick demos and experimentation.
    </footer>
  </div>
  <?php include 'partials/log-panel.php'; ?>
</body>
</html>
