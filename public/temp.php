<?php
header('Content-Type: text/html; charset=utf-8');

echo <<<'HTML'
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Argusly</title>
  <style>
    :root {
      --bg: #fffdf6;
      --bg-accent: #fff6d8;
      --card: #ffffff;
      --text-primary: #111827;
      --text: #2b2412;
      --muted: #5b5137;
      --brand: #8c6a00;
      --brand-strong: #6f5200;
      --border: #efe2b5;
      --shadow: 0 14px 34px rgba(74, 56, 9, 0.10);
      --radius: 18px;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, "Segoe UI", Tahoma, Arial, sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at 15% 10%, var(--bg-accent) 0%, transparent 42%),
        radial-gradient(circle at 88% 82%, #fff2c9 0%, transparent 40%),
        var(--bg);
      line-height: 1.6;
      display: grid;
      place-items: center;
      padding: 28px 18px;
    }

    main {
      width: 100%;
      max-width: 760px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 34px 30px;
    }

    .brand-row {
      display: flex;
      align-items: center;
      margin-bottom: 18px;
    }

    .brand-lockup {
      display: inline-flex;
      align-items: center;
      gap: 10px;
    }

    .brand-icon {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: #fef3c7;
      color: #7c2d12;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
    }

    .brand-wordmark {
      margin: 0;
      color: var(--text-primary);
      font-size: 1.125rem;
      font-weight: 600;
      line-height: 1.1;
    }

    .status {
      display: inline-block;
      background: #fff4c8;
      color: var(--brand-strong);
      border: 1px solid #e4cf81;
      border-radius: 999px;
      font-size: 0.88rem;
      font-weight: 700;
      letter-spacing: 0.01em;
      padding: 6px 12px;
      margin-bottom: 14px;
    }

    h1 {
      margin: 0 0 8px;
      color: var(--brand-strong);
      font-size: clamp(1.9rem, 3.4vw, 2.45rem);
      line-height: 1.2;
    }

    .subtitle {
      margin: 0 0 18px;
      color: var(--muted);
      font-size: 1.05rem;
    }

    p {
      margin: 0 0 16px;
    }

    h2 {
      margin: 22px 0 10px;
      color: var(--brand-strong);
      font-size: 1.2rem;
    }

    ul {
      margin: 0 0 20px 20px;
      padding: 0;
    }

    li + li {
      margin-top: 6px;
    }

    .cta {
      margin-top: 16px;
      padding: 18px 18px;
      border-radius: 14px;
      background: #fffae8;
      border: 1px solid #ebdb9f;
    }

    .cta h2 {
      margin-top: 0;
      margin-bottom: 6px;
    }

    a {
      color: var(--brand);
      font-weight: 700;
      text-underline-offset: 2px;
    }

    a:hover {
      color: var(--brand-strong);
    }

    a:focus-visible {
      outline: 3px solid #ffd44d;
      outline-offset: 3px;
      border-radius: 4px;
    }

    footer {
      margin-top: 22px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    @media (max-width: 640px) {
      main {
        padding: 24px 18px;
      }
    }
  </style>
</head>
<body>
  <main>
    <div class="brand-row">
      <div class="brand-lockup" aria-label="Argusly logo">
        <span class="brand-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" role="img" aria-hidden="true">
            <path d="m12.83 2.18 7 4a1 1 0 0 1 0 1.74l-7 4a2 2 0 0 1-1.66 0l-7-4a1 1 0 0 1 0-1.74l7-4a2 2 0 0 1 1.66 0z"></path>
            <path d="m2 12 9.17 5a2 2 0 0 0 1.66 0L22 12"></path>
            <path d="m2 17 9.17 5a2 2 0 0 0 1.66 0L22 17"></path>
          </svg>
        </span>
        <p class="brand-wordmark">Argusly</p>
      </div>
    </div>

    <p class="status">Status: pre launch</p>

    <h1>Argusly</h1>
    <p class="subtitle">De content authority layer voor teams die schaalbaar en gecontroleerd willen publiceren.</p>

    <p>Argusly helpt teams om AI content te genereren, te reviewen en te publiceren met governance, versiebeheer en integraties. We starten met een sterke focus op WordPress en Laravel als eerste integraties.</p>

    <h2>Wat je kunt verwachten</h2>
    <ul>
      <li>AI briefs en drafts met vaste workflows</li>
      <li>Brand voice en kennisbron gestuurd genereren</li>
      <li>Versiebeheer en audit trail</li>
      <li>Integraties met WordPress en Laravel</li>
      <li>Credit based usage voor generaties (drafts, rewrites, images)</li>
    </ul>

    <section class="cta" aria-labelledby="cta-title">
      <h2 id="cta-title">Aanmelden binnenkort</h2>
      <p>We zijn nog in ontwikkeling. Wil je als eerste toegang zodra we live gaan?</p>
      <p><a href="mailto:hello@publishlayer.com">Stuur een e mail</a></p>
      <p>Support: <a href="mailto:support@publishlayer.com">support@publishlayer.com</a></p>
    </section>

    <footer>&copy; Argusly</footer>
  </main>
</body>
</html>
HTML;
