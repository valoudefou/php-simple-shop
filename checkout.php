<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'bootstrap.php';  // Flagship initialization

use Flagship\Flagship;
use Flagship\Hit\Transaction;
use Flagship\Hit\Item;

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

$checkoutFlowFlag = $visitor->getFlag("checkout_flow", $checkoutFlowPreference);
$checkoutFlow = (int)$checkoutFlowFlag->getValue($checkoutFlowPreference);
if ($checkoutFlow === 1) {
    header('Location: cart.php');
    exit;
}

$mainHeadingFlag = $visitor->getFlag("main_heading", "Welcome to Simple PHP Shop");

include 'products.php';

$cart = $_SESSION['cart'] ?? [];
$lastOrder = $_SESSION['last_order'] ?? null;
$orderComplete = false;
$cartItems = [];
$total = 0;
$customerData = [];
$transactionData = null;

if (isset($_GET['status']) && $_GET['status'] === 'confirmed' && $lastOrder) {
    $orderComplete = true;
    $cartItems = $lastOrder['items'];
    $total = $lastOrder['total'];
    $customerData = $lastOrder['customer'];
    unset($_SESSION['last_order']);
} else {
    if (empty($cart)) {
        header('Location: cart.php');
        exit;
    }

    foreach ($cart as $id => $qty) {
        $product = $products[$id];
        $subtotal = $product['price'] * $qty;
        $total += $subtotal;
        $cartItems[] = [
            'id' => $id,
            'name' => $product['name'],
            'quantity' => $qty,
            'price' => $product['price'],
            'subtotal' => $subtotal,
        ];
    }
}

$formData = [
    'first_name' => 'Mike',
    'last_name' => 'Bee',
    'address' => '3 Waterhouse Square',
    'city' => 'London',
    'postal_code' => 'EC1N 2SW',
    'card_name' => 'MIKE BEE',
    'card_number' => '4242 4242 4242 4242',
    'expiry' => '12/2029',
    'cvv' => '123',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($formData as $key => $default) {
        $value = trim($_POST[$key] ?? '');
        $formData[$key] = $value;
        if ($value === '') {
            $label = ucwords(str_replace('_', ' ', $key));
            $errors[$key] = "{$label} is required.";
        }
    }

    if (empty($errors)) {
        $transactionData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'session_id' => session_id(),
            'items' => $cartItems,
            'total' => $total
        ];

        appendTransactionLog($transactionData);

        $transactionId = 'order_' . time();
        $transaction = (new Transaction($transactionId, "purchase"))
            ->setCurrency("USD")
            ->setItemCount(count($cartItems))
            ->setPaymentMethod("creditCard")
            ->setShippingCosts(0)
            ->setTaxes(0)
            ->setTotalRevenue($total)
            ->setShippingMethod("standard");

        try {
            $visitor->sendHit($transaction);

            foreach ($cartItems as $item) {
                $itemHit = (new Item($transactionId, $item['name'], $item['id']))
                    ->setItemPrice($item['price'])
                    ->setItemQuantity($item['quantity']);
                $visitor->sendHit($itemHit);
            }
        } catch (Exception $e) {
            error_log("Error sending transaction hits to Flagship: " . $e->getMessage());
        }

        unset($_SESSION['cart']);
        $_SESSION['last_order'] = [
            'items' => $cartItems,
            'total' => $total,
            'customer' => $formData,
        ];

        header('Location: checkout.php?status=confirmed');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Confirmation - Simple PHP Shop</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
  <div class="app-shell">
    <header>
      <div class="nav">
        <a class="brand" href="index.php">Simple PHP Shop</a>
        <a class="pill-link" href="index.php">Browse more</a>
      </div>
    </header>

    <main>
      <?php if (!$orderComplete): ?>
        <section class="hero">
          <h1>Checkout</h1>
          <p>
            Fill in your details and confirm payment. Card info comes pre-filled for quick testing,
            but every field must be completed before placing the order.
          </p>
        </section>

        <section class="grid-two">
          <form class="card" style="padding:2rem; gap:1rem;" method="POST" novalidate>
            <h2>Customer information</h2>
            <div class="grid-two">
              <?php
                $fields = [
                  ['first_name', 'First name'],
                  ['last_name', 'Last name'],
                ];
                foreach ($fields as [$field, $label]):
              ?>
                <div class="field-group">
                  <label for="<?= $field ?>"><?= $label ?></label>
                  <input type="text" id="<?= $field ?>" name="<?= $field ?>" value="<?= htmlspecialchars($formData[$field]) ?>" required>
                  <?php if (isset($errors[$field])): ?>
                    <span class="error"><?= htmlspecialchars($errors[$field]) ?></span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="field-group">
              <label for="address">Address</label>
              <input type="text" id="address" name="address" value="<?= htmlspecialchars($formData['address']) ?>" required>
              <?php if (isset($errors['address'])): ?>
                <span class="error"><?= htmlspecialchars($errors['address']) ?></span>
              <?php endif; ?>
            </div>

            <div class="grid-two">
              <div class="field-group">
                <label for="city">City</label>
                <input type="text" id="city" name="city" value="<?= htmlspecialchars($formData['city']) ?>" required>
                <?php if (isset($errors['city'])): ?>
                  <span class="error"><?= htmlspecialchars($errors['city']) ?></span>
                <?php endif; ?>
              </div>
              <div class="field-group">
                <label for="postal_code">Postal code</label>
                <input type="text" id="postal_code" name="postal_code" value="<?= htmlspecialchars($formData['postal_code']) ?>" required>
                <?php if (isset($errors['postal_code'])): ?>
                  <span class="error"><?= htmlspecialchars($errors['postal_code']) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <h2>Payment details</h2>
            <div class="field-group">
              <label for="card_name">Name on card</label>
              <input type="text" id="card_name" name="card_name" value="<?= htmlspecialchars($formData['card_name']) ?>" required>
              <?php if (isset($errors['card_name'])): ?>
                <span class="error"><?= htmlspecialchars($errors['card_name']) ?></span>
              <?php endif; ?>
            </div>

            <div class="field-group">
              <label for="card_number">Card number</label>
              <input type="tel" id="card_number" name="card_number" value="<?= htmlspecialchars($formData['card_number']) ?>" required>
              <?php if (isset($errors['card_number'])): ?>
                <span class="error"><?= htmlspecialchars($errors['card_number']) ?></span>
              <?php endif; ?>
            </div>

            <div class="inline-fields">
              <div class="field-group">
                <label for="expiry">Expiry</label>
                <input type="text" id="expiry" name="expiry" placeholder="MM/YY" value="<?= htmlspecialchars($formData['expiry']) ?>" required>
                <?php if (isset($errors['expiry'])): ?>
                  <span class="error"><?= htmlspecialchars($errors['expiry']) ?></span>
                <?php endif; ?>
              </div>
              <div class="field-group">
                <label for="cvv">CVV</label>
                <input type="text" id="cvv" name="cvv" value="<?= htmlspecialchars($formData['cvv']) ?>" required>
                <?php if (isset($errors['cvv'])): ?>
                  <span class="error"><?= htmlspecialchars($errors['cvv']) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <button class="primary-btn" type="submit">Place order</button>
          </form>

          <section class="card" style="padding:2rem; gap:1rem;">
            <h2>Order summary</h2>
            <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0.75rem;">
              <?php foreach ($cartItems as $item): ?>
                <li style="display:flex; justify-content:space-between;">
                  <span><?= htmlspecialchars($item['name']) ?> × <?= $item['quantity'] ?></span>
                  <strong>$<?= number_format($item['subtotal'], 2) ?></strong>
                </li>
              <?php endforeach; ?>
            </ul>
            <div style="display:flex; justify-content:space-between; border-top:1px solid var(--border); padding-top:1rem;">
              <span>Total due</span>
              <strong>$<?= number_format($total, 2) ?></strong>
            </div>
          </section>
        </section>
      <?php else: ?>
        <section class="hero">
          <h1>Order confirmed</h1>
          <p><?= htmlspecialchars($mainHeadingFlag->getValue("Thanks for exploring the Simple PHP Shop.")) ?></p>
        </section>

        <section class="grid-two">
          <article class="card" style="padding:2rem; gap:1.5rem;">
            <h2>Order summary</h2>
            <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:0.75rem;">
              <?php foreach ($cartItems as $item): ?>
                <li style="display:flex; justify-content:space-between; gap:1rem;">
                  <span><?= htmlspecialchars($item['name']) ?> × <?= $item['quantity'] ?></span>
                  <strong>$<?= number_format($item['subtotal'], 2) ?></strong>
                </li>
              <?php endforeach; ?>
            </ul>
            <div style="display:flex; justify-content:space-between; border-top:1px solid var(--border); padding-top:1rem;">
              <span>Total paid</span>
              <strong>$<?= number_format($total, 2) ?></strong>
            </div>
            <p class="muted">
              A sandbox receipt has been logged locally in
              <code><?= htmlspecialchars(transactionLogPath()) ?></code>.
            </p>
            <div style="display:flex; gap:1rem;">
              <a class="primary-btn" href="index.php">Back to home</a>
            </div>
          </article>

          <article class="card" style="padding:2rem; gap:1rem;">
            <h2>Shipping & payment</h2>
            <p><?= htmlspecialchars($customerData['first_name'] ?? '') ?> <?= htmlspecialchars($customerData['last_name'] ?? '') ?></p>
            <p>
              <?= htmlspecialchars($customerData['address'] ?? '') ?><br>
              <?= htmlspecialchars($customerData['city'] ?? '') ?>, <?= htmlspecialchars($customerData['postal_code'] ?? '') ?>
            </p>
            <?php
              $digits = preg_replace('/\D/', '', $customerData['card_number'] ?? '');
              $maskedCard = $digits ? '•••• ' . substr($digits, -4) : '';
            ?>
            <p class="muted">Charged to <?= htmlspecialchars($maskedCard) ?></p>
          </article>
        </section>
      <?php endif; ?>
    </main>

    <footer>
      Transactions sync to Flagship for experimentation telemetry. Happy building!
    </footer>
  </div>
  <?php include 'partials/log-panel.php'; ?>
</body>
</html>
