<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$title = 'Home - MyShop';
include __DIR__ . '/../includes/header.php';
?>

  <section class="hero">
    <h1>Welcome to MyShop</h1>
    <p>Discover the latest flagship devices and top-tier tech gear.</p>
  </section>

  <section class="products">
    <h2>Daily Discover</h2>
    <div class="grid">
      

    </div>
  </section>

<?php include __DIR__ . '/../includes/footer.php'; ?>