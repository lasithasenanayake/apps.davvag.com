<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <meta name="description" content="Sign in to your DAVVAG application workspace">
  <link rel="icon" href="assets/dock/images/favicon.ico" type="image/png">

  <title>Sign in | DAVVAG Dock</title>

  <link href="assets/dock/css/style.default.css" rel="stylesheet">
  <link href="assets/dock/css/dock-signin.css" rel="stylesheet">

  <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
  <!--[if lt IE 9]>
  <script src="js/html5shiv.js"></script>
  <script src="js/respond.min.js"></script>
  <![endif]-->
</head>

<body class="dock-signin">


<main class="dock-signin-shell">
    <section class="dock-signin-intro" aria-labelledby="dock-welcome-title">
        <div class="dock-brand"><span>[</span> DAVVAG <span>]</span></div>
        <div class="dock-signin-copy">
            <p class="dock-eyebrow">Application workspace</p>
            <h1 id="dock-welcome-title">Everything you need, in one secure dock.</h1>
            <p>Open your permitted applications, find tools quickly, and continue your work from one place.</p>
        </div>
        <ul class="dock-feature-list" aria-label="Workspace features">
            <li><i class="fa fa-th-large" aria-hidden="true"></i><span><strong>One workspace</strong>Access your business applications without hunting through links.</span></li>
            <li><i class="fa fa-search" aria-hidden="true"></i><span><strong>Fast app search</strong>Type an app or feature name and launch it immediately.</span></li>
            <li><i class="fa fa-lock" aria-hidden="true"></i><span><strong>Permission aware</strong>Only the applications available to your account are shown.</span></li>
        </ul>
    </section>

    <section class="dock-signin-card" aria-labelledby="signin-title">
        <div class="dock-mobile-brand"><span>[</span> DAVVAG <span>]</span></div>
        <p class="dock-eyebrow"><?php echo htmlspecialchars(defined('DOMAINNAME') ? DOMAINNAME : 'DAVVAG', ENT_QUOTES, 'UTF-8'); ?></p>
        <h2 id="signin-title">Welcome back</h2>
        <p class="dock-signin-hint">Sign in with your workspace account.</p>

        <?php if (isset($_GET["success"]) && $_GET["success"] === "false") { ?>
            <div class="dock-alert" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> The email or password is incorrect. Please try again.</div>
        <?php } ?>

        <form method="post" action="" id="dock-signin-form">
            <div class="dock-field">
                <label for="dock-username">Email address</label>
                <div class="dock-input-wrap"><i class="fa fa-envelope-o" aria-hidden="true"></i><input id="dock-username" type="email" class="form-control" placeholder="you@example.com" name="username" autocomplete="username" inputmode="email" required autofocus></div>
            </div>
            <div class="dock-field">
                <label for="dock-password">Password</label>
                <div class="dock-input-wrap"><i class="fa fa-lock" aria-hidden="true"></i><input id="dock-password" type="password" class="form-control" placeholder="Enter your password" name="password" autocomplete="current-password" required><button type="button" class="dock-password-toggle" id="dock-password-toggle" aria-label="Show password" aria-pressed="false"><i class="fa fa-eye" aria-hidden="true"></i></button></div>
            </div>
            <button type="submit" class="dock-signin-button" id="dock-signin-button"><span>Sign in</span><i class="fa fa-arrow-right" aria-hidden="true"></i></button>
        </form>
        <p class="dock-signin-footer"><i class="fa fa-shield" aria-hidden="true"></i> Your session is protected by DAVVAG authentication.</p>
    </section>
</main>

<script>
(function () {
    var form = document.getElementById('dock-signin-form');
    var submitButton = document.getElementById('dock-signin-button');
    var password = document.getElementById('dock-password');
    var toggle = document.getElementById('dock-password-toggle');
    toggle.addEventListener('click', function () {
        var showing = password.type === 'text';
        password.type = showing ? 'password' : 'text';
        toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
        toggle.querySelector('i').className = showing ? 'fa fa-eye' : 'fa fa-eye-slash';
        password.focus();
    });
    form.addEventListener('submit', function () {
        submitButton.disabled = true;
        submitButton.classList.add('is-loading');
        submitButton.querySelector('span').textContent = 'Signing in...';
    });
}());
</script>

</body>
</html>
