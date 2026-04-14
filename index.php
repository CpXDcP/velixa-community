<?php
session_start();

// Redirection si déjà connecté (inchangé)
if (isset($_SESSION['username']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: dashboard.php');
    } else {
        header('Location: interface_user.php');
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Velixa - Portail sécurisé</title>
  <link rel="stylesheet" href="style.css">
  <!-- Polices : Inter (UI) + Merriweather italique (slogan, plus lisible) -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Merriweather:ital,wght@1,700;1,800&display=swap" rel="stylesheet">
  <style>
    :root{
      --vx-bg:#0B0C0E;
      --vx-surface:#121416;
      --vx-border:#1f242b;
      --vx-text:#FFFFFF;
      --vx-primary:#3B82F6;   /* inchangé ailleurs */
      --vx-green:#10B981;     /* Vert bouton "connect" */
      --vx-green-strong:#34D399;
      --vx-ring: rgba(16,185,129,.45);
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      min-height:100vh;
      display:flex; align-items:center; justify-content:center;
      background: radial-gradient(1200px 600px at 50% -10%, #14181d 0%, #0B0C0E 55%);
      color:var(--vx-text);
      font-family: Inter, Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
    }
    .welcome{
      text-align:center;
      padding:42px 56px;
      background:linear-gradient(180deg,#121416 0%, #0f1215 100%);
      border:1px solid var(--vx-border);
      border-radius:18px;
      box-shadow:0 20px 60px rgba(0,0,0,.45);
    }
    .vx-logo{
      display:block;
      margin:0 auto 18px auto;
      height:350px;         /* Logo grand comme tu l’aimes */
      width:auto;
      filter: drop-shadow(0 10px 24px rgba(0,0,0,.55));
    }
    @media (max-width:520px){
      .welcome{padding:32px 28px}
      .vx-logo{height:110px}
    }
    .slogan{
      /* Nouvelle police plus lisible */
      font-family: "Merriweather", Georgia, "Times New Roman", serif;
      font-style: italic;
      font-weight: 800;
      font-size: 28px;
      letter-spacing: .2px;
      line-height: 1.2;
      margin: 6px 0 28px 0;
      color:#ffffff;
      text-shadow: 0 1px 0 rgba(0,0,0,.25), 0 10px 26px rgba(0,0,0,.30);
      opacity:.98;
    }
    .login-btn{
      display:inline-block;
      background:var(--vx-green);
      color:#0b1114;
      padding:14px 28px;
      font-weight:800;
      letter-spacing:.3px;
      border:none;
      border-radius:12px;
      text-decoration:none;
      box-shadow:
        0 0 0 0px var(--vx-ring),
        0 10px 26px rgba(16,185,129,.25);
      transition:
        transform .15s ease,
        filter .15s ease,
        box-shadow .15s ease;
    }
    .login-btn:hover,
    .login-btn:focus-visible{
      transform: translateY(-2px);
      filter: brightness(1.06);
      /* Surbrillance plus flagrante (anneau + glow) */
      box-shadow:
        0 0 0 3px var(--vx-ring),
        0 16px 40px rgba(16,185,129,.40),
        0 0 22px rgba(52,211,153,.35) inset;
      outline: none;
    }
  </style>
</head>
<body>
  <main class="welcome">
    <?php if (file_exists('assets/velixa-logo.png')): ?>
      <img src="assets/velixa-logo.png" alt="Velixa" class="vx-logo">
    <?php else: ?>
      <div style="font-weight:800; letter-spacing:.14em; font-size:26px; margin-bottom:14px;">VELIXA</div>
    <?php endif; ?>

    <div class="slogan">Governing AI with confidence</div>

    <a href="login.php" class="login-btn">connect</a>
  </main>
</body>
</html>

 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 

































