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

include 'products.php';

$cart = $_SESSION['cart'] ?? [];

if (isset($_GET['remove'])) {
  $removeId = (int)$_GET['remove'];
  if (isset($cart[$removeId])) {
    unset($cart[$removeId]);
    $_SESSION['cart'] = $cart;
  }
  header('Location: cart.php');
  exit;
}

$lastOrder = $_SESSION['last_order'] ?? null;
$cartItems = [];
$total = 0;
$orderComplete = false;
$customerData = [];
$formDefaults = [
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
$formData = $formDefaults;
$errors = [];

if ($checkoutFlow === 1) {
    if (isset($_GET['status']) && $_GET['status'] === 'confirmed' && $lastOrder) {
        $orderComplete = true;
        $cartItems = $lastOrder['items'];
        $total = $lastOrder['total'];
        $customerData = $lastOrder['customer'];
        unset($_SESSION['last_order']);
    } else {
        foreach ($cart as $id => $qty) {
            if (!isset($products[$id])) {
                continue;
            }
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

    if (!$orderComplete && $_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach (array_keys($formDefaults) as $key) {
            $value = trim($_POST[$key] ?? '');
            $formData[$key] = $value;
            if ($value === '') {
                $label = ucwords(str_replace('_', ' ', $key));
                $errors[$key] = "{$label} is required.";
            }
        }

        if (empty($cartItems)) {
            $errors['cart'] = "Your cart is empty.";
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

            header('Location: cart.php?status=confirmed');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Shopping Cart - Simple PHP Shop</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
  <div class="app-shell">
    <header>
      <div class="nav">
        <a class="brand" href="index.php">Simple PHP Shop</a>
        <div style="display:flex; gap:0.75rem;">
          <a class="pill-link" href="index.php">← Continue shopping</a>
          <span class="pill-link">🧺 Items: <?= array_sum($cart) ?></span>
        </div>
      </div>
    </header>

    <main>
      <?php if ($orderComplete): ?>
        <section class="hero">
          <h1>Order confirmed</h1>
          <p>Your integrated checkout flow worked flawlessly. Enjoy your items!</p>
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
              <a class="ghost-btn" href="index.php">Browse products</a>
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
      <?php elseif ($checkoutFlow === 1): ?>
          <?php if (empty($cartItems)): ?>
            <section class="empty-state">
              <p>Your cart is empty. Add an item to test the integrated checkout experience.</p>
              <a class="primary-btn" href="index.php">Browse products</a>
            </section>
          <?php else: ?>
            <section class="hero">
              <h1>Cart & checkout</h1>
              <p>Review your cart and complete payment in one streamlined step powered by Flagship.</p>
            </section>
            <section class="grid-two">
              <article class="card" style="padding:2rem; gap:1.5rem;">
                <h2>Cart overview</h2>
                <div style="overflow-x:auto;">
                  <table aria-label="Cart items">
                    <thead>
                      <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Unit price</th>
                        <th>Subtotal</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($cartItems as $item): ?>
                        <tr>
                          <td><?= htmlspecialchars($item['name']) ?></td>
                          <td><?= $item['quantity'] ?></td>
                          <td>$<?= number_format($item['price'], 2) ?></td>
                          <td>$<?= number_format($item['subtotal'], 2) ?></td>
                          <td>
                            <a class="ghost-btn" href="cart.php?remove=<?= $item['id'] ?>" onclick="return confirm('Remove this item?')">Remove</a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="table-total">
                  <strong>Total: $<?= number_format($total, 2) ?></strong>
                </div>
                <?php if (isset($errors['cart'])): ?>
                  <span class="error"><?= htmlspecialchars($errors['cart']) ?></span>
                <?php endif; ?>
              </article>

              <form class="card" style="padding:2rem; gap:1rem;" method="POST" novalidate>
                <h2>Checkout</h2>
                <div class="grid-two">
                  <?php
                    $nameFields = [
                      ['first_name', 'First name'],
                      ['last_name', 'Last name'],
                    ];
                    foreach ($nameFields as [$field, $label]):
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

                <h3>Payment details</h3>
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
            </section>
          <?php endif; ?>
      <?php else: ?>
        <h1>Your cart</h1>
        <?php if (empty($cart)): ?>
          <section class="empty-state">
            <p>Nothing here yet. Explore the catalog and flag your favorites.</p>
            <a class="primary-btn" href="index.php">Browse products</a>
          </section>
        <?php else: ?>
          <section class="card" style="padding:2rem; gap:1.5rem;">
            <div style="overflow-x:auto;">
              <table aria-label="Cart items">
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Unit price</th>
                    <th>Subtotal</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $total = 0;
                  foreach ($cart as $id => $qty):
                    $product = $products[$id];
                    $subtotal = $product['price'] * $qty;
                    $total += $subtotal;
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($product['name']) ?></td>
                    <td><?= $qty ?></td>
                    <td>$<?= number_format($product['price'], 2) ?></td>
                    <td>$<?= number_format($subtotal, 2) ?></td>
                    <td>
                      <a class="ghost-btn" href="cart.php?remove=<?= $id ?>" onclick="return confirm('Remove this item?')">Remove</a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="table-total">
              <strong>Total: $<?= number_format($total, 2) ?></strong>
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:1rem; justify-content:flex-end;">
              <a class="ghost-btn" href="index.php">Continue shopping</a>
              <a class="primary-btn" href="checkout.php">Go to checkout</a>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    </main>

    <footer>
      Cart data lives in PHP sessions — perfect for experimenting with server-side flags.
    </footer>
  </div>
  <?php include 'partials/log-panel.php'; ?>
</body>
</html>
