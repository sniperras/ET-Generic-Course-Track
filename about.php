<?php
$isPublicLandingRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
if (!$isPublicLandingRequest) {
    header('X-Robots-Tag: noindex, nofollow, nosnippet', true);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ethiopian Airlines CourseTrack | Employee Training Status</title>
    <meta name="description" content="Use Ethiopian Airlines CourseTrack to check recorded employee course completion, review training expiry dates, and follow up on submitted course complaints.">
    <meta name="robots" content="<?= $isPublicLandingRequest ? 'index, follow' : 'noindex, nofollow, nosnippet' ?>">
    <link rel="canonical" href="https://coursetrack.etairtv.com/about.php">
    <meta property="og:title" content="Ethiopian Airlines CourseTrack | Employee Training Status">
    <meta property="og:description" content="Use Ethiopian Airlines CourseTrack to check recorded employee course completion, review training expiry dates, and follow up on submitted course complaints.">
    <meta property="og:url" content="https://coursetrack.etairtv.com/about.php">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_ET">
    <meta property="og:site_name" content="Ethiopian Airlines CourseTrack">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Ethiopian Airlines CourseTrack | Employee Training Status">
    <meta name="twitter:description" content="Use Ethiopian Airlines CourseTrack to check recorded employee course completion, review training expiry dates, and follow up on submitted course complaints.">
    <meta name="theme-color" content="#588D01">
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      "name": "Ethiopian Airlines CourseTrack | Employee Training Status",
      "url": "https://coursetrack.etairtv.com/about.php",
      "isPartOf": {
        "@type": "WebSite",
        "name": "Ethiopian Airlines CourseTrack",
        "url": "https://coursetrack.etairtv.com/"
      }
    }
    </script>
    <style>
      :root { color-scheme: light; --green: #286d21; --deep: #174817; --ink: #1c2b20; --muted: #526357; --paper: #f6f8f5; }
      * { box-sizing: border-box; }
      body { margin: 0; color: var(--ink); background: var(--paper); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
      a { color: inherit; }
      .wrap { width: min(1080px, calc(100% - 36px)); margin-inline: auto; }
      .skip { position: absolute; left: -9999px; top: 8px; padding: 10px 14px; background: #fff; z-index: 3; }
      .skip:focus { left: 8px; }
      header { background: #fff; border-bottom: 1px solid #e1e8df; }
      .nav { min-height: 72px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
      .brand { color: var(--deep); text-decoration: none; font-weight: 800; letter-spacing: -.02em; }
      .nav a:last-child { color: var(--green); font-weight: 700; }
      .hero { padding: clamp(52px, 9vw, 100px) 0 58px; }
      .eyebrow { color: var(--green); text-transform: uppercase; letter-spacing: .12em; font-size: .78rem; font-weight: 800; }
      h1 { max-width: 780px; margin: 12px 0 18px; color: var(--deep); font-size: clamp(2.25rem, 5vw, 4rem); line-height: 1.08; letter-spacing: -.045em; }
      .lead { max-width: 760px; margin: 0; color: var(--muted); font-size: clamp(1.08rem, 2vw, 1.28rem); }
      .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
      .button { display: inline-block; padding: 12px 18px; border-radius: 9px; background: var(--green); color: white; text-decoration: none; font-weight: 750; }
      .button.secondary { background: white; color: var(--deep); border: 1px solid #c8d6c5; }
      section { padding: 28px 0 54px; }
      h2 { color: var(--deep); font-size: clamp(1.5rem, 3vw, 2rem); line-height: 1.2; }
      .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 18px; }
      .card { padding: 24px; border: 1px solid #e1e8df; border-radius: 14px; background: #fff; }
      .card h3 { margin: 0 0 8px; color: var(--deep); font-size: 1.12rem; }
      .card p { margin: 0; color: var(--muted); }
      .how { padding: 22px 26px; border-left: 4px solid var(--green); border-radius: 0 12px 12px 0; background: #eaf2e8; }
      footer { margin-top: 28px; padding: 24px 0; border-top: 1px solid #e1e8df; color: var(--muted); font-size: .93rem; }
      @media (max-width: 560px) { .nav { align-items: flex-start; flex-direction: column; justify-content: center; padding-block: 14px; } .hero { padding-top: 52px; } }
    </style>
</head>
<body>
  <a class="skip" href="#main">Skip to content</a>
  <header>
    <div class="wrap nav">
      <a class="brand" href="/about.php">Ethiopian Airlines CourseTrack</a>
      <a href="/" aria-label="Open the CourseTrack employee portal">Open employee portal</a>
    </div>
  </header>
  <main id="main" class="wrap">
    <div class="hero">
      <p class="eyebrow">Employee training and course tracking</p>
      <h1>Ethiopian Airlines employee course tracking</h1>
      <p class="lead">CourseTrack is a staff portal for checking recorded course completion, training validity dates, and updates on submitted training complaints.</p>
      <div class="actions">
        <a class="button" href="/">Open CourseTrack</a>
        <a class="button secondary" href="/#complaint-status">Check a complaint update</a>
      </div>
    </div>
    <section aria-labelledby="features-title">
      <h2 id="features-title">Training status and course follow-up</h2>
      <div class="grid">
        <article class="card">
          <h3>Check course completion</h3>
          <p>Use the employee status lookup to review recorded course completion and validity dates.</p>
        </article>
        <article class="card">
          <h3>Follow up on a course complaint</h3>
          <p>Use the complaint reference you received to check the current status of a submitted training complaint.</p>
        </article>
        <article class="card">
          <h3>One place for training status</h3>
          <p>CourseTrack brings course-status checks and complaint follow-up together in a single employee portal.</p>
        </article>
      </div>
    </section>
    <section aria-labelledby="how-title">
      <h2 id="how-title">How to use CourseTrack</h2>
      <div class="how">
        <p><strong>Open the portal</strong> and choose the matching status form.</p>
        <p><strong>Enter the relevant reference</strong> in the employee or complaint lookup.</p>
        <p><strong>Review the result</strong> shown by CourseTrack.</p>
      </div>
    </section>
  </main>
  <footer>
    <div class="wrap">Ethiopian Airlines CourseTrack · <a href="/">Go to the employee portal</a></div>
  </footer>
</body>
</html>
