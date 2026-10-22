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
        :root {
            color-scheme: light;
            --brand: #588D01;
            /* primary green — same as portal */
            --brand-deep: #3B5E01;
            /* darker green for hover/accents */
            --brand-soft: #EAF2E1;
            /* light green for tinted panels */
            --ink: #1c2b20;
            --muted: #526357;
            --paper: #f6f8f5;
            --line: #e1e8df;
            --gold: #FFB800;
            /* warm accent — matches popup badge */
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            color: var(--ink);
            background: var(--paper);
            font: 16px/1.65 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        a {
            color: inherit;
        }

        .wrap {
            width: min(1080px, calc(100% - 36px));
            margin-inline: auto;
        }

        .skip {
            position: absolute;
            left: -9999px;
            top: 8px;
            padding: 10px 14px;
            background: #fff;
            z-index: 3;
        }

        .skip:focus {
            left: 8px;
        }

        /* ─────────── HEADER ─────────── */
        header {
            background: #fff;
            border-bottom: 1px solid var(--line);
            box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
        }

        .nav {
            min-height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: var(--brand-deep);
            text-decoration: none;
            font-weight: 800;
            letter-spacing: -.015em;
            font-size: 1.02rem;
        }

        .brand::before {
            content: "";
            display: inline-block;
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--brand), var(--brand-deep));
            box-shadow: 0 3px 8px rgba(88, 141, 1, .28);
        }

        .nav-cta {
            color: var(--brand);
            font-weight: 700;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 8px;
            transition: background .18s ease, color .18s ease;
        }

        .nav-cta:hover {
            background: var(--brand-soft);
            color: var(--brand-deep);
        }

        /* ─────────── HERO ─────────── */
        .hero {
            padding: clamp(56px, 9vw, 104px) 0 62px;
            position: relative;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--brand-deep);
            background: var(--brand-soft);
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .74rem;
            font-weight: 800;
            padding: 6px 12px;
            border-radius: 999px;
        }

        .eyebrow::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--brand);
        }

        h1 {
            max-width: 780px;
            margin: 18px 0 20px;
            color: var(--brand-deep);
            font-size: clamp(2.25rem, 5vw, 3.9rem);
            line-height: 1.08;
            letter-spacing: -.045em;
            font-weight: 800;
        }

        .lead {
            max-width: 760px;
            margin: 0;
            color: var(--muted);
            font-size: clamp(1.08rem, 2vw, 1.24rem);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 30px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 13px 22px;
            border-radius: 10px;
            background: var(--brand);
            color: #fff;
            text-decoration: none;
            font-weight: 750;
            letter-spacing: .005em;
            box-shadow: 0 6px 16px rgba(88, 141, 1, .28);
            transition: transform .16s ease, background .18s ease, box-shadow .18s ease;
        }

        .button:hover {
            background: var(--brand-deep);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(88, 141, 1, .35);
        }

        .button::after {
            content: "→";
            font-weight: 800;
            transition: transform .18s ease;
        }

        .button:hover::after {
            transform: translateX(3px);
        }

        .button.secondary {
            background: #fff;
            color: var(--brand-deep);
            border: 1.5px solid #c8d6c5;
            box-shadow: none;
        }

        .button.secondary:hover {
            background: var(--brand-soft);
            border-color: var(--brand);
            color: var(--brand-deep);
        }

        .button.secondary::after {
            content: "";
        }

        /* ─────────── SECTIONS ─────────── */
        section {
            padding: 24px 0 58px;
        }

        h2 {
            color: var(--brand-deep);
            font-size: clamp(1.5rem, 3vw, 1.95rem);
            line-height: 1.2;
            letter-spacing: -.02em;
            font-weight: 800;
            margin: 0 0 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        h2::before {
            content: "";
            display: inline-block;
            width: 6px;
            height: 26px;
            border-radius: 4px;
            background: linear-gradient(180deg, var(--brand), var(--brand-deep));
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
            gap: 18px;
        }

        .card {
            padding: 24px 24px 26px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            position: relative;
            overflow: hidden;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }

        .card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--brand), var(--gold));
            opacity: 0;
            transition: opacity .25s ease;
        }

        .card:hover {
            transform: translateY(-3px);
            border-color: #c8d6c5;
            box-shadow: 0 12px 28px rgba(23, 72, 23, .08);
        }

        .card:hover::before {
            opacity: 1;
        }

        .card h3 {
            margin: 0 0 10px;
            color: var(--brand-deep);
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -.01em;
        }

        .card p {
            margin: 0;
            color: var(--muted);
        }

        /* ─────────── HOW-TO PANEL ─────────── */
        .how {
            padding: 26px 30px;
            border-left: 5px solid var(--brand);
            border-radius: 0 14px 14px 0;
            background: var(--brand-soft);
            display: grid;
            gap: 10px;
        }

        .how p {
            margin: 0;
            color: var(--ink);
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .how p::before {
            content: "✓";
            flex: none;
            display: inline-grid;
            place-items: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--brand);
            color: #fff;
            font-size: .75rem;
            font-weight: 900;
            margin-top: 2px;
        }

        .how p strong {
            color: var(--brand-deep);
            margin-right: 4px;
        }

        /* ─────────── FOOTER ─────────── */
        footer {
            margin-top: 28px;
            padding: 26px 0;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: .93rem;
            background: #fff;
        }

        footer a {
            color: var(--brand);
            font-weight: 700;
            text-decoration: none;
        }

        footer a:hover {
            color: var(--brand-deep);
            text-decoration: underline;
        }

        /* ─────────── RESPONSIVE ─────────── */
        @media (max-width: 560px) {
            .nav {
                align-items: flex-start;
                flex-direction: column;
                justify-content: center;
                padding-block: 14px;
            }

            .hero {
                padding-top: 52px;
            }

            .actions {
                flex-direction: column;
                align-items: stretch;
            }

            .button {
                justify-content: center;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                transition: none !important;
                animation: none !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>

<body>
    <a class="skip" href="#main">Skip to content</a>
    <header>
        <div class="wrap nav">
            <a class="brand" href="/about.php">Ethiopian Airlines CourseTrack</a>
            <a class="nav-cta" href="/" aria-label="Open the CourseTrack employee portal">Open employee portal →</a>
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