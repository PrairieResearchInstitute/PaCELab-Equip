<?php
/**
 * functions.php — Shared helpers, page chrome, and the small pieces of
 * business logic that more than one screen needs.
 *
 * Every page in the application requires this one file, which pulls in the
 * database handle and the identity functions behind it.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

date_default_timezone_set('America/Chicago');

/**
 * Nothing gets to hand a stack trace to a browser.
 *
 * A campus host with display_errors left on would otherwise print the file
 * paths, the query, and whatever was bound to it onto the page. The detail goes
 * to the server error log, where the web person can read it; the visitor gets a
 * sentence. PRI Facilities does the same thing in its lib/fail.php.
 */
set_exception_handler(function (Throwable $e): void {
    error_log('Lab equipment system: ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (headers_sent()) {
        echo '<p style="font:15px system-ui;margin:1rem">Something went wrong and this page stopped part way. '
           . 'Nothing was saved. Reload, and tell the web person if it happens again.</p>';
        exit;
    }

    http_response_code(500);
    header('Retry-After: 30');

    // The calendar and anything else expecting JSON should get JSON back.
    $wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'api.php';

    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'The server hit an error and did not save anything. Try again.']);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Something went wrong</title>'
       . '<style>body{font:16px/1.5 Georgia,serif;margin:3rem auto;max-width:34rem;padding:0 1rem;color:#1a1a1a}'
       . 'h1{font-size:1.4rem}p{color:#5b5750}</style></head><body>'
       . '<h1>Something went wrong</h1>'
       . '<p>The page stopped before it finished, and nothing was saved. Try again in a moment.</p>'
       . '<p>If it keeps happening, tell the web person: the detail is in the server error log, '
       . 'with the time stamp of this attempt.</p>'
       . '</body></html>';
    exit;
});

// The session starts here, before any page has emitted a byte. Starting it
// lazily instead means the first call that needs it — a CSRF token inside a
// form, say — can land after the headers have gone out, and PHP refuses.
session_boot();

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

/** Escape for HTML. Every echoed value passes through this. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a charge amount. */
function money($amount): string
{
    return '$' . number_format((float) $amount, 2);
}

/** Format an ISO date for reading. */
function pretty_date(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    return $ts ? date('M j, Y', $ts) : $iso;
}

/** Format an ISO timestamp for reading. */
function pretty_datetime(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    return $ts ? date('M j, Y g:i a', $ts) : $iso;
}

/** Send the browser elsewhere and stop. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/**
 * A value from the settings table. Laboratory name, page titles, and the
 * instruction text on each screen live here so wording changes need no file edit.
 */
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all('SELECT "key", value FROM settings') as $row) {
                $cache[$row['key']] = (string) $row['value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

/** Write a settings value. */
function set_setting(string $key, string $value): void
{
    // INSERT OR REPLACE rather than an upsert clause, because upsert needs
    // SQLite 3.24 and campus hosts are not always current.
    if (db_driver() === 'pgsql') {
        db_run(
            'INSERT INTO settings ("key", value) VALUES (?, ?)
             ON CONFLICT ("key") DO UPDATE SET value = EXCLUDED.value',
            [$key, $value]
        );
        return;
    }
    db_run('INSERT OR REPLACE INTO settings ("key", value) VALUES (?, ?)', [$key, $value]);
}

// ---------------------------------------------------------------------------
// Cross-site request forgery
// ---------------------------------------------------------------------------

/** The session CSRF token, created on first use. */
function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** A hidden input carrying the token. Every state-changing form includes this. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

/** True when the request carries a valid token. */
function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/** Refuse the request unless it carries a valid token. */
function csrf_require(): void
{
    if (!csrf_valid()) {
        http_response_code(400);
        exit('This form expired or was submitted from another site. Go back, reload the page, and try again.');
    }
}

// ---------------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------------

/** Queue a message for the next page. $type is notice, success, or error. */
function flash(string $message, string $type = 'success'): void
{
    session_boot();
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

/** Print and clear queued messages. */
function flash_render(): void
{
    session_boot();
    $queued = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    foreach ($queued as $item) {
        echo '<div class="flash flash-' . h($item['type']) . '">' . h($item['message']) . '</div>';
    }
}

// ---------------------------------------------------------------------------
// Page chrome
// ---------------------------------------------------------------------------

/** '' at the application root, '../' inside admin/. */
function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $dir  = realpath(dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
        $root = realpath(APP_ROOT);
        $base = ($dir && $root && $dir !== $root) ? '../' : '';
    }
    return $base;
}

/** Send anyone arriving before installation to the installer. */
function require_installed(): void
{
    if (!db_installed()) {
        redirect(base_url() . 'install.php');
    }

    // Bring an older database up to the current shape. Cheap, idempotent, and
    // the alternative is a column that only exists on fresh installations.
    require_once __DIR__ . '/schema.php';
    schema_migrate();
}

/**
 * The application's own screens, in navigation order. Home first, because a
 * person who has finished a task needs somewhere obvious to land.
 */
function site_pages(): array
{
    return [
        'home'     => ['Home',           'home.php'],
        'entry'    => ['Use entry',      'index.php'],
        'schedule' => ['Calendar',       'schedule.php'],
        'report'   => ['Billing report', 'report.php'],
        'admin'    => ['Administration', 'admin/index.php'],
    ];
}

/**
 * Where we are, as one of the hrefs in site_pages(), or '' for anything else.
 *
 * The distinction that matters is page, not section: the administrative
 * overview is one of nine screens behind it, so standing on Equipment the
 * useful thing a menu can offer is the way back to the overview. Only the exact
 * page you are looking at is worth leaving out.
 */
/**
 * Where to send somebody once they have said who they are.
 *
 * Only a page of this application, never an address somewhere else: a crafted
 * link should not be able to bounce anybody off campus the moment they type
 * their name. Same reasoning as safe_next() in admin/login.php, for the pages
 * outside the administrative panel.
 *
 * The fallback is the laboratory chooser, because that is the landing page:
 * which laboratory you are in decides what every other screen shows.
 */
function safe_return_to(?string $raw, string $fallback = 'home.php'): string
{
    $raw = (string) $raw;
    if ($raw === '' || preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $raw)) {
        return $fallback;
    }

    $file = basename((string) (parse_url($raw, PHP_URL_PATH) ?: ''));
    if (!in_array($file, ['home.php', 'index.php', 'lab.php', 'schedule.php', 'report.php'], true)) {
        return $fallback;
    }

    $query = parse_url($raw, PHP_URL_QUERY);
    return $file . ($query ? '?' . $query : '');
}

function current_page_href(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $file   = basename($script);
    $folder = basename(dirname($script));

    return $folder === 'admin' ? 'admin/' . $file : $file;
}

/**
 * Open the page.
 *
 * The chrome is the approved Illinois web theme: the toolkit stylesheet and
 * module script, then <ilw-header> and <ilw-footer> web components, matching
 * what the PRI flagship site serves. The application's own navigation goes in
 * the header's navigation slot; everything below the header is this
 * application's own markup, styled by assets/style.css.
 *
 * $options accepts:
 *   nav        — which navigation item to mark current
 *   scripts    — script paths relative to the application root
 *   bodyClass  — extra class on <body>
 *   mainClass  — class on <main>
 *   chromeless — omit the navigation (the installer and the sign-in page)
 *   breadcrumb — [['label', 'href'], ['label', null]] trail under the header
 */
function page_header(string $title, array $options = []): void
{
    $base = base_url();
    $lab  = current_lab_name();
    $nav  = $options['nav'] ?? '';

    // The browser tab wants to say two things: which screen, and which
    // laboratory. The laboratory dashboard is titled after the laboratory, so
    // saying it twice reads as a mistake; there, and anywhere without a
    // laboratory yet, the application's own name is the more useful second half.
    $qualifier = ($lab !== '' && $lab !== $title) ? $lab : setting('lab_name', 'Shared Laboratory Equipment');
    $tabTitle  = $qualifier !== '' && $qualifier !== $title
        ? $title . ' · ' . $qualifier
        : $title;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title><?= h($tabTitle) ?></title>

<!-- The approved Illinois web theme. Both files come from Illinois-run hosts.
     See the README: this is the one outside dependency in the application, and
     the campus network reaches it. -->
<link rel="stylesheet" href="https://cdn.toolkit.illinois.edu/3/toolkit.css">
<script type="module" src="https://cdn.toolkit.illinois.edu/3/toolkit.js"></script>
<link rel="stylesheet" href="https://use.typekit.net/etc1rtr.css">

<link rel="stylesheet" href="<?= h($base) ?>assets/style.css">
</head>
<body class="<?= h($options['bodyClass'] ?? '') ?>">

<?php if (empty($options['chromeless'])): ?>
<header>
  <ilw-header>
    <!-- The banner INHS serves uses the primary-unit slot to name the body the
         site belongs to. On inhs.illinois.edu that slot holds the institute,
         because the survey is the site. Here the application is the site, so
         the slot holds the survey: the banner reads INHS, and the site name
         still says which application you are in. -->
    <a slot="primary-unit" href="<?= h(setting('unit_url', 'https://inhs.illinois.edu')) ?>" target="_blank"
       aria-label="<?= h(setting('unit_name', 'Illinois Natural History Survey')) ?>, opens a new window"><?= h(setting('unit_name', 'Illinois Natural History Survey')) ?></a>

    <a slot="site-name" href="<?= h($base) ?>home.php"><?= h(setting('app_name', 'Shared Equipment')) ?></a>

    <nav slot="links" class="il-links" aria-label="Utility">
      <ul>
<?php if (identity_is_self_declared() && have_user_name()): ?>
        <li><?= h(current_user_name()) ?></li>
        <li><a href="<?= h($base) ?>index.php?switch_user=1">Not you?</a></li>
<?php elseif (!identity_is_self_declared() && have_user_name()): ?>
        <li><?= h(current_user_name()) ?></li>
<?php endif; ?>
<?php if (is_admin()): ?>
        <li><a href="<?= h($base) ?>admin/index.php">Signed in as administrator</a></li>
<?php endif; ?>
      </ul>
    </nav>

<?php
    // The menu offers where you are not, judged one page at a time. Standing on
    // an administrative screen it still offers Administration, because that is
    // the overview holding the other eight and you will want it back.
    //
    // The home screen is the exception that has no menu at all: its cards are
    // the way in to everything, and a menu saying the same thing beside them was
    // the duplication worth removing.
    $here = current_page_href();
    $menu = [];
    foreach (site_pages() as $key => [$label, $href]) {
        if ($href !== $here) {
            $menu[$key] = [$label, $href];
        }
    }
?>
<?php if ($here !== 'home.php' && $menu): ?>
    <ilw-header-menu slot="navigation">
      <ul>
<?php foreach ($menu as [$label, $href]): ?>
        <li><a href="<?= h($base . $href) ?>"><?= h($label) ?></a></li>
<?php endforeach; ?>
      </ul>
    </ilw-header-menu>
<?php endif; ?>
  </ilw-header>
</header>

<?php
  // Which laboratory you are in, on every screen except the one whose whole
  // job is choosing it. A plain form, so it works without script; app.js
  // submits it on change to save the extra click.
  $availableLabs = ($here === 'home.php') ? [] : labs_for_person();
  $shownLab      = $availableLabs ? current_lab() : null;
?>
<?php if ($shownLab): ?>
<div class="lab-bar">
  <div class="lab-bar-inner">
    <form method="post" action="<?= h($base) ?>index.php" class="lab-picker">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="switch_lab">
      <input type="hidden" name="return_to" value="<?= h($here) ?>">
      <label for="labPicker">Laboratory</label>
      <select id="labPicker" name="lab_id" data-role="lab-picker">
<?php foreach ($availableLabs as $option): ?>
        <option value="<?= (int) $option['lab_id'] ?>" <?= (int) $option['lab_id'] === (int) $shownLab['lab_id'] ? 'selected' : '' ?>>
          <?= h($option['name']) ?>
        </option>
<?php endforeach; ?>
<?php if (is_admin()): ?>
        <option value="new">Add a laboratory&hellip;</option>
<?php endif; ?>
        <option value="choose">All laboratories&hellip;</option>
      </select>
      <button type="submit" class="button button-secondary button-small">Go</button>
    </form>

    <a class="lab-dashboard-link" href="<?= h($base) ?>lab.php">Overview of <?= h($shownLab['name']) ?></a>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($options['breadcrumb'])): ?>
<nav class="crumbs" aria-label="Breadcrumb">
  <ol>
<?php foreach ($options['breadcrumb'] as [$label, $href]): ?>
    <li><?= $href ? '<a href="' . h($href) . '">' . h($label) . '</a>' : h($label) ?></li>
<?php endforeach; ?>
  </ol>
</nav>
<?php endif; ?>
<?php endif; ?>

<main class="<?= h($options['mainClass'] ?? 'page') ?>">
<?php
    flash_render();
}

/**
 * Close the page with the approved PRI footer.
 *
 * This is the flagship site's footer with the public-website columns removed:
 * an internal billing tool has no use for the publications and collaborators
 * menus or the social icons. The institute identity, the address block, and
 * the five surveys are kept as they are served on prairie.illinois.edu.
 */
function page_footer(array $options = []): void
{
    $base = base_url();
    ?>
</main>
<?php if (empty($options['chromeless'])): ?>
<footer>
  <ilw-footer source="Illinois_App">
    <!-- Identity, address, and the first column as inhs.illinois.edu serves
         them, down to the Oak Street address and the survey's own telephone
         number rather than the institute's. -->
    <a slot="primary-unit" href="https://www.prairie.illinois.edu/" target="_blank"
       aria-label="Prairie Research Institute, opens a new window">Prairie Research Institute</a>

    <p slot="site-name"><a href="<?= h(setting('unit_url', 'https://inhs.illinois.edu')) ?>"><?= h(setting('unit_name', 'Illinois Natural History Survey')) ?></a></p>

    <address slot="address">
      <p>1816 South Oak Street<br>MC-652<br>Champaign, IL 61820</p>
      <p>Email: <a href="mailto:info@inhs.illinois.edu">info@inhs.illinois.edu</a></p>
      <p>Phone: <a href="tel:+12173336880">217-333-6880</a></p>
      <p><a class="small" href="https://staff.prairie.illinois.edu/" target="_blank"
            aria-label="Staff login, opens a new window">Staff login</a></p>
    </address>

    <ilw-columns gap="3em">
      <nav class="ilw-footer-menu" aria-labelledby="footer-menu-collaborators">
        <h2 id="footer-menu-collaborators">Find Collaborators</h2>
        <ul>
          <li><a href="https://experts.illinois.edu/en/organisations/illinois-natural-history-survey" target="_blank">Illinois Experts</a></li>
          <li><a href="https://www.ideals.illinois.edu/units/81" target="_blank">INHS on IDEALS</a></li>
          <li><a href="https://www.zotero.org/groups/469262/prairie_research_institute_staff_bibliography/tags/INHS/library" target="_blank">INHS on Zotero</a></li>
        </ul>
      </nav>

      <nav class="ilw-footer-menu" aria-labelledby="footer-menu-app">
        <h2 id="footer-menu-app"><?= h(setting('app_name', 'Shared Equipment')) ?></h2>
        <ul>
<?php foreach (site_pages() as [$label, $href]): ?>
          <li><a href="<?= h($base . $href) ?>"><?= h($label) ?></a></li>
<?php endforeach; ?>
        </ul>
        <p><?= h(setting('footer_note', 'Illinois Natural History Survey')) ?></p>
      </nav>
    </ilw-columns>
  </ilw-footer>
</footer>
<?php endif; ?>
<?php foreach (($options['scripts'] ?? []) as $script): ?>
<script src="<?= h($base . $script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** The administrative panel's own navigation. */
function admin_pages(): array
{
    return [
        'index'        => ['Overview',      'index.php'],
        'labs'         => ['Laboratories',  'labs.php'],
        'equipment'    => ['Equipment',     'equipment.php'],
        'grants'       => ['Grants',        'grants.php'],
        'units'        => ['Units',         'units.php'],
        'records'      => ['Usage records', 'records.php'],
        'costs'        => ['Running costs','costs.php'],
        'reservations' => ['Reservations',  'reservations.php'],
        'batches'      => ['Export history','batches.php'],
        'emails'       => ['Message log',   'emails.php'],
        'settings'     => ['Interface text','settings.php'],
        'users'        => ['Administrators','users.php'],
        'studio'       => ['Studio',         'studio.php'],
    ];
}

/**
 * Open an administrative page.
 *
 * The overview lists the sections as cards, so repeating them as a row of tabs
 * on every screen said the same thing twice. A sub-page gets a breadcrumb back
 * to the overview instead, which is the part that was actually missing.
 */
function admin_header(string $current, string $title, array $options = []): void
{
    $crumbs = [['Home', base_url() . 'home.php']];

    if ($current === 'index') {
        $crumbs[] = ['Administration', null];
    } else {
        $crumbs[] = ['Administration', 'index.php'];
        $crumbs[] = [admin_pages()[$current][0] ?? $title, null];
    }

    page_header($title, $options + ['nav' => 'admin', 'breadcrumb' => $crumbs]);
}

// ---------------------------------------------------------------------------
// Accordions
//
// Plain <details> and <summary>. The browser already knows how to open and
// close them, keyboards and screen readers already understand them, and they
// work with the script switched off — none of which is true of a div and a
// click handler. app.js only adds one thing: remembering what you left open.
// ---------------------------------------------------------------------------

/**
 * Open a collapsible section.
 *
 * $id is a stable name used to remember whether this section was left open.
 * $meta is the small print on the right of the summary line: a count, a total.
 */
function accordion_open(string $id, string $title, array $options = []): void
{
    $open  = !empty($options['open']);
    $meta  = (string) ($options['meta'] ?? '');
    $tone  = (string) ($options['tone'] ?? '');
    ?>
    <details class="accordion<?= $tone !== '' ? ' accordion-' . h($tone) : '' ?>"
             data-accordion="<?= h($id) ?>"<?= $open ? ' open' : '' ?>>
      <summary>
        <span class="accordion-title"><?= h($title) ?></span>
        <?php if ($meta !== ''): ?><span class="accordion-meta"><?= h($meta) ?></span><?php endif; ?>
      </summary>
      <div class="accordion-body">
    <?php
}

/** Close a collapsible section. */
function accordion_close(): void
{
    echo '</div></details>';
}

// ---------------------------------------------------------------------------
// The record of what was sent
//
// The application composes messages and hands them to Outlook, so it cannot
// know for certain that one was sent — only that it was written and that the
// mail client was opened with it. Both of those are worth keeping: months
// later, "was the equipment person told the GC-MS was down?" has an answer,
// and the exact words are there to read.
// ---------------------------------------------------------------------------

/** Record a composed message. Returns its id. */
function log_email(array $message): int
{
    db_run(
        'INSERT INTO email_log (lab_id, created_at, created_by, purpose, to_address, cc_address,
                                subject, body, equipment_id, status)
         VALUES (:lab_id, :created_at, :created_by, :purpose, :to_address, :cc_address,
                 :subject, :body, :equipment_id, :status)',
        [
            'lab_id'       => $message['lab_id'] ?? current_lab_id(),
            'created_at'   => date('Y-m-d H:i:s'),
            'created_by'   => $message['created_by'] ?? admin_display_name(),
            'purpose'      => $message['purpose'] ?? '',
            'to_address'   => $message['to'] ?? '',
            'cc_address'   => $message['cc'] ?? '',
            'subject'      => $message['subject'] ?? '',
            'body'         => $message['body'] ?? '',
            'equipment_id' => $message['equipment_id'] ?? null,
            'status'       => ($message['to'] ?? '') === '' ? 'no_recipient' : 'composed',
        ]
    );
    return (int) db()->lastInsertId();
}

/** Note that the mail client was actually opened with a message. */
function mark_email_opened(int $emailId): bool
{
    $row = db_one('SELECT * FROM email_log WHERE email_id = ?', [$emailId]);
    if (!$row || $row['status'] === 'opened') {
        return (bool) $row;
    }
    db_run('UPDATE email_log SET status = ?, opened_at = ? WHERE email_id = ?',
        ['opened', date('Y-m-d H:i:s'), $emailId]);
    return true;
}

/** How a logged message reads on screen. */
function email_status_label(string $status): string
{
    $map = [
        'composed'     => 'Written, not opened',
        'opened'       => 'Opened in the mail client',
        'no_recipient' => 'Not sent: no address set',
    ];
    return $map[$status] ?? $status;
}

// ---------------------------------------------------------------------------
// Domain helpers
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Handing a message to the mail client
//
// The application sends no mail itself. It has no mail server to talk to, no
// daemon to run, and nothing that would keep trying in the background — all of
// which the deployment rules forbid. What it does instead is compose the
// message and hand it to whatever mail client the person already uses, which on
// a campus desktop is Outlook. They see it before it goes, which is the right
// arrangement anyway: a person should read a message sent in their name.
// ---------------------------------------------------------------------------

/**
 * A mailto: URL. Line breaks are CRLF because that is what Outlook expects in
 * a body; a bare newline collapses the message onto one line.
 */
function mailto_link(string $to, string $subject, array $bodyLines, string $cc = ''): string
{
    $query = ['subject' => $subject, 'body' => implode("\r\n", $bodyLines)];
    if ($cc !== '') {
        $query['cc'] = $cc;
    }
    // RFC 3986 encoding throughout: rawurlencode, so a space is %20 rather than
    // a plus sign, which some mail clients paste in literally.
    $parts = [];
    foreach ($query as $key => $value) {
        $parts[] = $key . '=' . rawurlencode($value);
    }
    return 'mailto:' . rawurlencode($to) . '?' . implode('&', $parts);
}

/**
 * The message that goes to the equipment person when an instrument is retired
 * or goes out of service. Composed once, at the moment it happens, so what gets
 * logged is exactly what gets opened.
 *
 * $kind is 'retired' or 'down'.
 */
function compose_equipment_email(array $item, string $kind): array
{
    $contact = equipment_contact(lab_by_id($item['lab_id']));
    $id      = (int) $item['equipment_id'];

    $charges = (int) db_value(
        'SELECT COUNT(*) FROM usage_records WHERE equipment_id = ? AND voided = 0', [$id]);
    $lastUsed = db_value(
        'SELECT MAX(use_date) FROM usage_records WHERE equipment_id = ? AND voided = 0', [$id]);
    $upcoming = (int) db_value(
        'SELECT COUNT(*) FROM reservations WHERE equipment_id = ? AND end_datetime >= ?',
        [$id, date('Y-m-d H:i:s')]);

    $labRow = lab_by_id($item['lab_id']);
    $lab    = $labRow ? $labRow['name'] : 'the shared equipment laboratory';

    $subject = ($kind === 'down' ? 'Out of service: ' : 'Retired: ')
        . $item['name'] . ($item['property_tag'] ? ' (' . $item['property_tag'] . ')' : '');

    $body = [];
    $body[] = ($contact['name'] !== '' ? $contact['name'] . ',' : 'Hello,');
    $body[] = '';
    $body[] = $kind === 'down'
        ? $item['name'] . ' has been marked out of service in ' . $lab . '.'
        : $item['name'] . ' has been retired in ' . $lab . '. It no longer appears in the booking or charging lists.';
    $body[] = '';

    if (trim((string) $item['status_note']) !== '') {
        $body[] = 'Reason given: ' . $item['status_note'];
        $body[] = '';
    }

    $body[] = 'Instrument details';
    $body[] = '  University inventory number: ' . ($item['property_tag'] ?: 'none recorded');
    $body[] = '  Make and model: ' . (trim($item['manufacturer'] . ' ' . $item['model']) ?: 'not recorded');
    $body[] = '  Location: ' . ($item['location'] ?: 'not recorded');
    $body[] = '  Responsible person: ' . ($item['contact_person'] ?: 'not recorded');
    $body[] = '  Rate at this point: ' . money($item['rate']) . ' ' . $item['rate_unit'];
    $body[] = '  Receiving account: ' . ($item['receiving_subaccount'] ?: 'not recorded');
    $body[] = '';
    $body[] = 'Use to date';
    $body[] = '  Charges recorded: ' . $charges;
    $body[] = '  Last run: ' . ($lastUsed ? pretty_date((string) $lastUsed) : 'never used');

    $spent = equipment_cost_total($id, 365);
    if ($spent > 0) {
        $body[] = '  Spent on it in the last year: ' . money($spent);
    }

    if ($upcoming > 0) {
        $body[] = '';
        $body[] = 'NOTE: ' . $upcoming . ' booking' . ($upcoming === 1 ? '' : 's')
            . ' still stand' . ($upcoming === 1 ? 's' : '') . ' on this instrument. '
            . 'Whoever holds that time has not been told.';
    }

    $body[] = '';
    $body[] = $kind === 'down'
        ? 'Nothing has been deleted. The instrument keeps its rate, its account, and its charge history, and it can be put back in service from the administrative panel.'
        : 'Nothing has been deleted. Every past charge is intact and still appears on the billing report.';
    $body[] = '';
    $body[] = 'Recorded by ' . admin_display_name() . ' on ' . date('F j, Y \a\t g:i a') . '.';

    return [
        'lab_id'       => (int) $item['lab_id'],
        'purpose'      => 'equipment_' . $kind,
        'to'           => $contact['email'],
        'cc'           => $contact['cc'],
        'subject'      => $subject,
        'body'         => implode("\r\n", $body),
        'equipment_id' => $id,
    ];
}

/**
 * The laboratory group: everybody who books and charges instruments.
 * Belongs to the laboratory, not to the installation — each has its own people.
 */
function lab_group(?array $lab = null): array
{
    $lab = $lab ?? current_lab();
    return [
        'name'  => $lab && $lab['lab_group_name'] !== '' ? $lab['lab_group_name'] : 'the laboratory group',
        'email' => $lab ? (string) $lab['lab_group_email'] : '',
    ];
}

/**
 * The message that goes to the whole laboratory when an instrument goes down.
 *
 * A different audience from the equipment person, so a different message. They
 * do not need the purchase history; they need to know their afternoon has just
 * changed. It names whoever is holding time on the instrument, because those
 * are the people the news actually lands on.
 */
function compose_lab_group_email(array $item, string $kind): array
{
    $labRow = lab_by_id($item['lab_id']);
    $group  = lab_group($labRow);
    $id     = (int) $item['equipment_id'];
    $lab    = $labRow ? $labRow['name'] : 'the shared equipment laboratory';

    $affected = db_all(
        'SELECT * FROM reservations WHERE equipment_id = ? AND end_datetime >= ?
          ORDER BY start_datetime LIMIT 25',
        [$id, date('Y-m-d H:i:s')]
    );

    // Alternatives from this laboratory only. Pointing somebody at an
    // instrument in a laboratory they cannot book is worse than saying nothing.
    $alternatives = db_all(
        "SELECT name, location, rate, rate_unit FROM equipment
          WHERE lab_id = ? AND active = 1 AND status = 'available'
            AND equip_class = ? AND equipment_id <> ?
          ORDER BY lower(name) LIMIT 5",
        [(int) $item['lab_id'], $item['equip_class'], $id]
    );

    $subject = ($kind === 'down' ? 'Out of service: ' : 'Retired: ') . $item['name']
        . ' — ' . $lab;

    $body = [];
    $body[] = 'All,';
    $body[] = '';
    $body[] = $kind === 'down'
        ? $item['name'] . ' in ' . $lab . ' is out of service and cannot be booked or charged until it is back.'
        : $item['name'] . ' in ' . $lab . ' has been retired and is no longer available.';

    if (trim((string) $item['status_note']) !== '') {
        $body[] = '';
        $body[] = 'What is wrong: ' . $item['status_note'];
    }

    if ($item['location']) {
        $body[] = '';
        $body[] = 'Location: ' . $item['location'];
    }

    if ($affected) {
        $body[] = '';
        $body[] = 'Bookings affected';
        foreach ($affected as $booking) {
            $body[] = '  ' . date('D j M, g:i a', strtotime($booking['start_datetime']))
                . ' to ' . date('g:i a', strtotime($booking['end_datetime']))
                . '  —  ' . ($booking['reserved_by'] ?: 'unnamed')
                . ($booking['purpose'] ? ' (' . $booking['purpose'] . ')' : '');
        }
        $body[] = '';
        $body[] = 'Those bookings have NOT been cancelled. If one is yours, please move or cancel it.';
    } else {
        $body[] = '';
        $body[] = 'Nobody currently holds time on it.';
    }

    if ($alternatives) {
        $body[] = '';
        $body[] = 'Other instruments of the same kind';
        foreach ($alternatives as $alt) {
            $body[] = '  ' . $alt['name']
                . ($alt['location'] ? ', ' . $alt['location'] : '')
                . '  —  ' . money($alt['rate']) . ' ' . $alt['rate_unit'];
        }
    }

    $body[] = '';
    $body[] = 'Anything already recorded against ' . $item['name'] . ' is unaffected and will still be billed as normal.';
    $body[] = '';
    $body[] = admin_display_name();

    return [
        'lab_id'       => (int) $item['lab_id'],
        'purpose'      => 'labgroup_' . $kind,
        'to'           => $group['email'],
        'cc'           => '',
        'subject'      => $subject,
        'body'         => implode("\r\n", $body),
        'equipment_id' => $id,
    ];
}

/** The person a laboratory tells when one of its instruments is retired. */
function equipment_contact(?array $lab = null): array
{
    $lab = $lab ?? current_lab();
    return [
        'name'  => $lab ? (string) $lab['equipment_contact_name'] : '',
        'email' => $lab ? (string) $lab['equipment_contact_email'] : '',
        'cc'    => $lab ? (string) $lab['equipment_contact_cc'] : '',
    ];
}

// ---------------------------------------------------------------------------
// Laboratories
//
// The application serves several laboratories from one installation. A
// laboratory owns its instruments, its grants, its bookings, its charges and
// its costs; nothing crosses between them. Which laboratory you are looking at
// is a session choice, exactly as identity is, and every query that touches
// owned data is scoped by it.
//
// Membership is keyed on person_key — the same string current_user_name()
// returns, folded to lower case. Today that is a typed last name. After the
// NetID swap it is a NetID, and this code does not change.
// ---------------------------------------------------------------------------

/** Fold an identity to the form lab_members is keyed on. */
function person_key(?string $name): string
{
    return strtolower(trim((string) $name));
}

/** Every laboratory, or only the ones still running. */
function labs(bool $activeOnly = true): array
{
    return db_all(
        'SELECT * FROM labs' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY lower(name)'
    );
}

/** One laboratory by id, or null. */
function lab_by_id($labId): ?array
{
    return $labId ? db_one('SELECT * FROM labs WHERE lab_id = ?', [(int) $labId]) : null;
}

/**
 * The laboratories this person may work in.
 *
 * An administrator sees them all, because managing them is the job. Everybody
 * else sees what an administrator has assigned them, and nothing otherwise —
 * an empty list is a real answer, and the screens say so rather than showing an
 * empty laboratory.
 */
function labs_for_person(?string $name = null): array
{
    if (is_admin()) {
        return labs();
    }

    $key = person_key($name ?? current_user_name());
    if ($key === '') {
        return [];
    }

    return db_all(
        'SELECT l.* FROM labs l
           JOIN lab_members m ON m.lab_id = l.lab_id
          WHERE l.active = 1 AND m.person_key = ?
          ORDER BY lower(l.name)',
        [$key]
    );
}

/** True when this person may work in this laboratory. */
function may_use_lab(int $labId, ?string $name = null): bool
{
    foreach (labs_for_person($name) as $lab) {
        if ((int) $lab['lab_id'] === $labId) {
            return true;
        }
    }
    return false;
}

/** Remember which laboratory is being looked at. */
function set_current_lab(int $labId): void
{
    session_boot();
    $_SESSION['lab_id'] = $labId;
}

/**
 * The laboratory in view, or null when none has been settled on.
 *
 * Picks one by itself when the choice is obvious — a person in exactly one
 * laboratory should never be asked which — and refuses to keep a selection the
 * person is no longer entitled to.
 */
function current_lab(): ?array
{
    session_boot();
    $available = labs_for_person();

    if (!$available) {
        return null;
    }

    $chosen = (int) ($_SESSION['lab_id'] ?? 0);
    foreach ($available as $lab) {
        if ((int) $lab['lab_id'] === $chosen) {
            return $lab;
        }
    }

    // Either nothing chosen yet, or the choice is no longer theirs to make.
    $first = $available[0];
    set_current_lab((int) $first['lab_id']);
    return $first;
}

/** The id of the laboratory in view, or 0. */
function current_lab_id(): int
{
    $lab = current_lab();
    return $lab ? (int) $lab['lab_id'] : 0;
}

/** The laboratory's own name, for headings and messages. */
function current_lab_name(): string
{
    $lab = current_lab();
    return $lab ? (string) $lab['name'] : '';
}

/**
 * Stop a screen that needs a laboratory when there is not one.
 *
 * Two different dead ends, and they need different sentences: nobody has told
 * us who you are, or nobody has put you in a laboratory yet.
 */
function require_lab(): array
{
    $lab = current_lab();
    if ($lab) {
        return $lab;
    }

    $base = base_url();

    if (identity_is_self_declared() && !have_user_name()) {
        redirect($base . 'index.php?next=' . rawurlencode(current_page_href()));
    }

    page_header('No laboratory', ['nav' => '', 'mainClass' => 'page narrow']);
    echo '<h1>You are not in a laboratory yet</h1>';
    echo '<p class="lede">'
       . h(current_user_name() ?: 'You')
       . ' has not been added to any laboratory, so there are no instruments to show.</p>';
    echo '<p>An administrator adds people to a laboratory under Administration, '
       . 'Laboratories. Ask whoever looks after yours.</p>';
    echo '<p><a class="button button-secondary" href="' . h($base) . 'index.php?switch_user=1">'
       . 'Use a different name</a></p>';
    page_footer();
    exit;
}

// ---------------------------------------------------------------------------
// Whether an instrument can be used
//
// Four states, and only two questions really matter: can somebody book time on
// it, and can somebody charge a run to it. An instrument that is down fails
// both while keeping everything else — its rate, its account, its history.
// Service due is a warning, not a bar: work continues, somebody just needs to
// arrange the service.
// ---------------------------------------------------------------------------

/** True when time can still be booked and a run still charged. */
function equipment_is_usable(array $item): bool
{
    return in_array($item['status'] ?? 'available', ['available', 'service_due'], true);
}

/** True when an instrument is out of service. */
function equipment_is_down(array $item): bool
{
    return ($item['status'] ?? '') === 'down';
}

/**
 * Why an instrument cannot be used, as a sentence, or null when it can.
 * The same wording appears wherever the refusal happens.
 */
function equipment_unusable_reason(array $item): ?string
{
    if (equipment_is_usable($item)) {
        return null;
    }
    if (($item['status'] ?? '') === 'retired') {
        return $item['name'] . ' has been retired and cannot take new work.';
    }

    $reason = trim((string) ($item['status_note'] ?? ''));
    $since  = $item['status_since'] ? ' since ' . pretty_date($item['status_since']) : '';

    return $item['name'] . ' is down' . $since . '.'
        . ($reason !== '' ? ' ' . $reason : '')
        . ' Ask an administrator when it is expected back.';
}

// ---------------------------------------------------------------------------
// How hard an instrument is working
//
// Booked hours over the hours it could have been booked. The denominator is a
// setting rather than the twenty-four hours the calendar draws, because an
// instrument nobody can reach at three in the morning is not idle at three in
// the morning. Fifty hours is a working week with a little room either side.
// ---------------------------------------------------------------------------

/** Booked hours, charged runs, and the resulting percentage, over a window. */
function equipment_utilisation(int $equipmentId, int $days = 0): array
{
    $days   = $days > 0 ? $days : max(7, (int) setting('utilisation_window_days', '28'));
    $from   = date('Y-m-d 00:00:00', strtotime('-' . $days . ' days'));
    $to     = date('Y-m-d H:i:s');

    // Clip each booking to the window, so one long booking that started before
    // it does not count time outside it.
    $bookedSeconds = 0.0;
    $rows = db_all(
        'SELECT start_datetime, end_datetime FROM reservations
          WHERE equipment_id = :eq AND start_datetime < :to AND end_datetime > :from',
        ['eq' => $equipmentId, 'from' => $from, 'to' => $to]
    );
    foreach ($rows as $row) {
        $start = max(strtotime($row['start_datetime']), strtotime($from));
        $end   = min(strtotime($row['end_datetime']), strtotime($to));
        if ($end > $start) {
            $bookedSeconds += ($end - $start);
        }
    }

    $hoursPerWeek = max(1.0, (float) setting('utilisation_hours_per_week', '50'));
    $capacity     = $hoursPerWeek * ($days / 7);
    $booked       = $bookedSeconds / 3600;

    $runs = (int) db_value(
        'SELECT COUNT(*) FROM usage_records WHERE equipment_id = ? AND voided = 0 AND use_date >= ?',
        [$equipmentId, date('Y-m-d', strtotime('-' . $days . ' days'))]
    );

    return [
        'days'     => $days,
        'booked'   => round($booked, 1),
        'capacity' => round($capacity, 1),
        'percent'  => $capacity > 0 ? (int) round($booked / $capacity * 100) : 0,
        'runs'     => $runs,
        'bookings' => count($rows),
    ];
}

// ---------------------------------------------------------------------------
// What is costing money, and what is earning it
// ---------------------------------------------------------------------------

/** Everything spent on an instrument, optionally within a window. */
function equipment_cost_total(int $equipmentId, ?int $days = null): float
{
    $sql    = 'SELECT COALESCE(SUM(amount), 0) FROM equipment_costs WHERE equipment_id = ?';
    $params = [$equipmentId];
    if ($days !== null) {
        $sql .= ' AND cost_date >= ?';
        $params[] = date('Y-m-d', strtotime('-' . $days . ' days'));
    }
    return (float) db_value($sql, $params);
}

/** Everything charged out on an instrument, voided runs excluded. */
function equipment_revenue_total(int $equipmentId, ?int $days = null): float
{
    $sql    = 'SELECT COALESCE(SUM(total_charge), 0) FROM usage_records WHERE equipment_id = ? AND voided = 0';
    $params = [$equipmentId];
    if ($days !== null) {
        $sql .= ' AND use_date >= ?';
        $params[] = date('Y-m-d', strtotime('-' . $days . ' days'));
    }
    return (float) db_value($sql, $params);
}

// ---------------------------------------------------------------------------
// The alert list
//
// One list, ranked, so the thing that matters most is read first. A broken
// instrument outranks an expiring warranty, which outranks an instrument
// nobody has booked. Every alert carries a rank, a tone, and somewhere to go.
// ---------------------------------------------------------------------------

const ALERT_DOWN            = 10;
const ALERT_SERVICE_OVERDUE = 20;
const ALERT_WARRANTY_GONE   = 30;
const ALERT_SERVICE_SOON    = 40;
const ALERT_WARRANTY_SOON   = 50;
const ALERT_OVERSUBSCRIBED  = 60;
const ALERT_LOSING_MONEY    = 70;
const ALERT_IDLE            = 80;

/**
 * Everything worth somebody's attention, most urgent first.
 *
 * $base is the URL prefix for links, because this is rendered from both the
 * application root and from inside admin/.
 */
function equipment_alerts(string $base = ''): array
{
    $alerts   = [];
    $today    = date('Y-m-d');
    $highPct  = max(1, (int) setting('utilisation_high_pct', '75'));
    $lowPct   = max(0, (int) setting('utilisation_low_pct', '10'));
    $warnDays = max(1, (int) setting('warranty_warning_days', '60'));

    foreach (lab_equipment(true) as $item) {
        $id   = (int) $item['equipment_id'];
        $edit = $base . 'admin/equipment.php?edit=' . $id;

        // --- Out of service ------------------------------------------------
        if (equipment_is_down($item)) {
            $note = trim((string) $item['status_note']);
            $alerts[] = [
                'rank'     => ALERT_DOWN,
                'tone'     => 'critical',
                'tag'      => 'down',
                'name'     => $item['name'],
                'headline' => $item['name'] . ' is down'
                    . ($item['status_since'] ? ' since ' . pretty_date($item['status_since']) : '') . '.',
                'detail'   => ($note !== '' ? $note . ' ' : '') . 'Not accepting bookings or charges.',
                'href'     => $edit,
                'action'   => 'Update status',
            ];
        }

        // --- Service ---------------------------------------------------------
        $due = (string) ($item['service_due_date'] ?? '');
        if ($due !== '' && $due < $today) {
            $alerts[] = [
                'rank'     => ALERT_SERVICE_OVERDUE,
                'tone'     => 'critical',
                'tag'      => 'overdue',
                'name'     => $item['name'],
                'headline' => $item['name'] . ' was due for service ' . pretty_date($due) . '.',
                'detail'   => 'Overdue by ' . (int) ((strtotime($today) - strtotime($due)) / 86400) . ' days.',
                'href'     => $edit,
                'action'   => 'Book the service',
            ];
        } elseif ($due !== '' && $due <= date('Y-m-d', strtotime('+14 days'))) {
            $alerts[] = [
                'rank'     => ALERT_SERVICE_SOON,
                'tone'     => 'warning',
                'tag'      => 'service',
                'name'     => $item['name'],
                'headline' => $item['name'] . ' is due for service ' . pretty_date($due) . '.',
                'detail'   => trim((string) $item['status_note']),
                'href'     => $edit,
                'action'   => 'Book the service',
            ];
        } elseif (($item['status'] ?? '') === 'service_due' && $due === '') {
            $alerts[] = [
                'rank'     => ALERT_SERVICE_SOON,
                'tone'     => 'warning',
                'tag'      => 'service',
                'name'     => $item['name'],
                'headline' => $item['name'] . ' is marked as needing service.',
                'detail'   => trim((string) $item['status_note']) ?: 'No date set for it yet.',
                'href'     => $edit,
                'action'   => 'Set a date',
            ];
        }

        // --- Warranty and service contracts ----------------------------------
        $cover = db_one(
            "SELECT * FROM equipment_costs
              WHERE equipment_id = ? AND covers_end IS NOT NULL
                AND category IN ('warranty', 'service_contract')
              ORDER BY covers_end DESC LIMIT 1",
            [$id]
        );
        if ($cover) {
            $ends = (string) $cover['covers_end'];
            if ($ends < $today) {
                $alerts[] = [
                    'rank'     => ALERT_WARRANTY_GONE,
                    'tone'     => 'warning',
                    'tag'      => 'cover',
                    'name'     => $item['name'],
                    'headline' => $item['name'] . ' has no cover: '
                        . strtolower(picklist_label('cost_category', $cover['category']))
                        . ' ended ' . pretty_date($ends) . '.',
                    'detail'   => 'A repair now comes out of the laboratory rather than the contract.',
                    'href'     => $base . 'admin/costs.php?equipment_id=' . $id,
                    'action'   => 'Record a renewal',
                ];
            } elseif ($ends <= date('Y-m-d', strtotime('+' . $warnDays . ' days'))) {
                $alerts[] = [
                    'rank'     => ALERT_WARRANTY_SOON,
                    'tone'     => 'notice',
                    'tag'      => 'cover',
                    'name'     => $item['name'],
                    'headline' => $item['name'] . ' cover ends ' . pretty_date($ends) . '.',
                    'detail'   => ucfirst((string) picklist_label('cost_category', $cover['category']))
                        . ($cover['vendor'] ? ' with ' . $cover['vendor'] : '') . '.',
                    'href'     => $base . 'admin/costs.php?equipment_id=' . $id,
                    'action'   => 'Renew it',
                ];
            }
        }

        if (!equipment_is_usable($item)) {
            continue;   // demand and cost recovery mean nothing while it is down
        }

        // --- Demand ----------------------------------------------------------
        $use = equipment_utilisation($id);

        if ($use['percent'] >= $highPct) {
            $alerts[] = [
                'rank'     => ALERT_OVERSUBSCRIBED,
                'tone'     => 'notice',
                'tag'      => 'in demand',
                'name'     => $item['name'],
                'headline' => $item['name'] . ' is ' . $use['percent'] . '% booked.',
                'detail'   => $use['booked'] . ' of ' . $use['capacity'] . ' bookable hours over the last '
                    . $use['days'] . ' days. At this rate a second one would pay for itself; '
                    . 'the billing report shows what it has earned.',
                'href'     => $base . 'report.php',
                'action'   => 'See what it earns',
            ];
        } elseif ($use['percent'] <= $lowPct && $use['runs'] === 0) {
            $alerts[] = [
                'rank'     => ALERT_IDLE,
                'tone'     => 'opportunity',
                'tag'      => 'idle',
                'name'     => $item['name'],
                'headline' => $item['name'] . ' has not been used in ' . $use['days'] . ' days.',
                'detail'   => 'No runs and ' . ($use['bookings'] === 0 ? 'no bookings' : $use['bookings'] . ' bookings')
                    . '. Worth advertising to other surveys, at ' . money($item['rate']) . ' ' . $item['rate_unit'] . '.',
                'href'     => $base . 'admin/equipment.php?edit=' . $id,
                'action'   => 'Check the rate',
            ];
        }

        // --- Cost recovery ---------------------------------------------------
        $year    = 365;
        $costs   = equipment_cost_total($id, $year);
        $revenue = equipment_revenue_total($id, $year);

        if ($costs > 0 && $revenue < $costs) {
            $alerts[] = [
                'rank'     => ALERT_LOSING_MONEY,
                'tone'     => 'notice',
                'tag'      => 'recovery',
                'name'     => $item['name'],
                'headline' => $item['name'] . ' has recovered ' . money($revenue) . ' of ' . money($costs)
                    . ' spent on it this year.',
                'detail'   => 'Short by ' . money($costs - $revenue)
                    . '. Either the rate is low or the instrument is under-used.',
                'href'     => $base . 'admin/costs.php?equipment_id=' . $id,
                'action'   => 'See the costs',
            ];
        }
    }

    usort($alerts, function ($a, $b) {
        return $a['rank'] === $b['rank']
            ? strcasecmp($a['name'], $b['name'])
            : $a['rank'] - $b['rank'];
    });

    return $alerts;
}

/** The alert list, rendered the same way on every screen that shows it. */
function render_alerts(array $alerts, string $heading = 'Needs attention', int $limit = 0): void
{
    if (!$alerts) {
        return;
    }
    $shown = $limit > 0 ? array_slice($alerts, 0, $limit) : $alerts;
    $worst = $shown[0]['tone'];

    // Open when something is actually wrong. A list of opportunities can wait
    // until somebody asks for it; a broken instrument cannot.
    $urgent = in_array($worst, ['critical', 'warning'], true);
    $counts = [];
    foreach (['critical' => 'urgent', 'warning' => 'warning', 'notice' => 'to note', 'opportunity' => 'opportunity'] as $tone => $word) {
        $n = count(array_filter($alerts, fn($a) => $a['tone'] === $tone));
        if ($n > 0) { $counts[] = $n . ' ' . $word; }
    }

    accordion_open('alerts', $heading, [
        'open' => $urgent,
        'tone' => $worst,
        'meta' => implode(' · ', $counts),
    ]);
    ?>
    <div class="alert-card alert-card-<?= h($worst) ?>">
      <ol class="alert-list">
      <?php foreach ($shown as $alert): ?>
        <li class="alert-<?= h($alert['tone']) ?>">
          <!-- The row is a flex box inside the list item rather than the list
               item itself: a flexed <li> loses its marker in Chrome, and the
               number is the whole point of a ranked list. -->
          <div class="alert-row">
            <span class="alert-tag"><?= h($alert['tag']) ?></span>
            <span class="alert-body">
              <strong><?= h($alert['headline']) ?></strong>
              <?php if (!empty($alert['detail'])): ?>
                <span class="alert-detail"><?= h($alert['detail']) ?></span>
              <?php endif; ?>
            </span>
            <a class="button button-secondary button-small" href="<?= h($alert['href']) ?>"><?= h($alert['action']) ?></a>
          </div>
        </li>
      <?php endforeach; ?>
      </ol>
      <?php if ($limit > 0 && count($alerts) > $limit): ?>
        <p class="hint"><?= count($alerts) - $limit ?> more, on
          <a href="<?= h(base_url()) ?>admin/equipment.php">the equipment screen</a>.</p>
      <?php endif; ?>
    </div>
    <?php
    accordion_close();
}

/** Every instrument in the laboratory in view, retired ones included. */
function lab_equipment(bool $activeOnly = true): array
{
    return db_all(
        'SELECT * FROM equipment WHERE lab_id = ?'
        . ($activeOnly ? ' AND active = 1' : '')
        . ' ORDER BY active DESC, lower(name)',
        [current_lab_id()]
    );
}

/** Active instruments, ordered for a dropdown. */
function active_equipment(): array
{
    return lab_equipment(true);
}

/**
 * One instrument by id — but only if it belongs to the laboratory in view.
 *
 * This is the choke point that keeps laboratories apart. Every screen loads an
 * instrument through here, so an id typed into a URL cannot reach another
 * laboratory's instrument: the lookup simply finds nothing, and the caller
 * already handles nothing.
 */
function equipment_by_id($id, bool $anyLab = false): ?array
{
    if (!$id) {
        return null;
    }
    if ($anyLab) {
        return db_one('SELECT * FROM equipment WHERE equipment_id = ?', [(int) $id]);
    }
    return db_one(
        'SELECT * FROM equipment WHERE equipment_id = ? AND lab_id = ?',
        [(int) $id, current_lab_id()]
    );
}

/** Every grant belonging to the laboratory in view. */
function lab_grants(bool $activeOnly = true): array
{
    return db_all(
        'SELECT g.*, u.code AS unit_code
           FROM grants g
           LEFT JOIN units u ON u.unit_id = g.unit_id
          WHERE g.lab_id = ?' . ($activeOnly ? ' AND g.active = 1' : '') . '
          ORDER BY g.active DESC, lower(g.display_label)',
        [current_lab_id()]
    );
}

/** One grant by id, scoped to the laboratory in view. */
function grant_by_id($id): ?array
{
    return $id ? db_one(
        'SELECT * FROM grants WHERE grant_id = ? AND lab_id = ?',
        [(int) $id, current_lab_id()]
    ) : null;
}

/**
 * Grants available to charge on a given date: in this laboratory, active, and
 * with an award period covering the date of the run. An expired grant drops out
 * of the dropdown.
 */
function grants_for_date(string $useDate): array
{
    return db_all(
        'SELECT g.*, u.code AS unit_code
           FROM grants g
           LEFT JOIN units u ON u.unit_id = g.unit_id
          WHERE g.lab_id = :lab
            AND g.active = 1
              AND (g.start_date IS NULL OR g.start_date <= :d)
              AND (g.end_date   IS NULL OR g.end_date   >= :d)
          ORDER BY lower(g.display_label)',
        ['d' => $useDate, 'lab' => current_lab_id()]
    );
}

/** The label shown for a grant in dropdowns and reports. */
function grant_label(array $grant): string
{
    $label = $grant['display_label'] ?: $grant['title'];
    return $label . ' — ' . $grant['cfopa'];
}

/**
 * One short code list, as code => label, in display order.
 * Follows the ref.PickLists pattern in PRI Facilities so a list can be
 * extended from the administrative panel rather than by editing a file.
 */
function picklist(string $listKey, bool $activeOnly = true): array
{
    static $cache = [];
    $cacheKey = $listKey . ($activeOnly ? '|active' : '|all');

    if (!isset($cache[$cacheKey])) {
        $sql = 'SELECT code, label FROM picklists WHERE list_key = ?'
             . ($activeOnly ? ' AND active = 1' : '')
             . ' ORDER BY sort_order, lower(label)';
        $rows = [];
        try {
            $rows = db_all($sql, [$listKey]);
        } catch (Throwable $e) {
            $rows = [];
        }
        $cache[$cacheKey] = array_column($rows, 'label', 'code');
    }
    return $cache[$cacheKey];
}

/** The label for one code, falling back to the code itself. */
function picklist_label(string $listKey, ?string $code): string
{
    $list = picklist($listKey, false);
    return $list[(string) $code] ?? (string) $code;
}

/**
 * The rate units an instrument may charge by. The three the arithmetic
 * understands are seeded and protected; the list is read rather than
 * hard-coded so a laboratory can add its own wording.
 */
function rate_units(): array
{
    $units = array_keys(picklist('rate_unit'));
    return $units ?: ['per sample', 'per hour', 'per run'];
}

// ---------------------------------------------------------------------------
// CFOPA
//
// The account string the business office charges is a CFOPA, not a CFOPA: five
// hyphenated segments, Chart-Fund-Organization-Program-Activity, as
//
//     1-303631-375002-375150-A51
//     │ │      │      │      └── Activity      one of A, B, C then two digits
//     │ │      │      └───────── Program       six digits
//     │ │      └──────────────── Organization  six digits (INHS is 375002)
//     │ └─────────────────────── Fund          six digits
//     └───────────────────────── Chart         one digit, 1 for Urbana
//
// The activity segment is what makes a receiving account work: a self-supporting
// fund carries one activity code per facility, which is how PRI already bills
// its field stations. So the account a charge lands in and the account it comes
// out of are both CFOPAs, and the activity segment is the part that differs.
//
// These follow the PRI Suite's own rules: normalise rather than refuse. A code
// with no activity segment means the parent, which is A00.
// ---------------------------------------------------------------------------

/** A CFOPA as it should be stored: trimmed, upper case, activity filled in. */
function cfopa_normalize(?string $value): string
{
    $value = strtoupper(trim((string) $value));
    if ($value === '') {
        return '';
    }
    return preg_match('/-[ABC]\d{2}$/', $value) ? $value : $value . '-A00';
}

/** The four-segment parent, with the activity segment removed. */
function cfopa_base(?string $value): string
{
    return trim(preg_replace('/-[ABC]\d{2}$/', '', strtoupper(trim((string) $value))));
}

/** The activity segment, defaulting to the parent code A00. */
function cfopa_activity(?string $value): string
{
    return preg_match('/-([ABC]\d{2})$/', strtoupper(trim((string) $value)), $m) ? $m[1] : 'A00';
}

/** True when a CFOPA has the shape the business office issues. */
function cfopa_is_well_formed(?string $value): bool
{
    return (bool) preg_match('/^\d-\d{6}-\d{6}-\d{6}-[ABC]\d{2}$/', strtoupper(trim((string) $value)));
}

/**
 * What kind of money the fund segment holds, read from its first digit.
 * The same map the PRI Suite derives fund_type from.
 */
function cfopa_fund_type(?string $value): string
{
    $types = [
        '1' => 'GRF/Appropriated', '2' => 'ICR',            '3' => 'Cost Share',
        '4' => 'State Contracts',  '5' => 'Federal',        '6' => 'Gift/Endowment',
        '7' => 'Miscellaneous',    '9' => 'Other',
    ];
    if (!preg_match('/^\d-(\d)/', strtoupper(trim((string) $value)), $m)) {
        return 'Unknown';
    }
    return $types[$m[1]] ?? 'Unknown';
}

/**
 * A property tag as it should be stored: upper case, trimmed. PRI Facilities
 * applies no pattern to a tag and neither does this, because a laboratory with
 * an odd legacy tag still has to be able to record it. Uniqueness is what is
 * enforced, not shape.
 */
function clean_property_tag(?string $value): string
{
    return strtoupper(trim((string) $value));
}

/** Valid ISO date, or null. */
function clean_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/** Round a timestamp down to the half hour the calendar works in. */
function snap_half_hour(int $timestamp): int
{
    return $timestamp - ($timestamp % 1800);
}

/** 'Y-m-d H:i:s' snapped to a half hour boundary, or null if unparseable. */
function clean_slot_datetime(?string $value): ?string
{
    $ts = strtotime((string) $value);
    return $ts ? date('Y-m-d H:i:s', snap_half_hour($ts)) : null;
}

/**
 * The reservation that would collide with this span, or null when the span is
 * free. Callers that write must run this inside the same transaction as the
 * write, so nothing slips in between the test and the insert.
 */
function reservation_conflict(int $equipmentId, string $start, string $end, ?int $excludeId = null): ?array
{
    $sql = 'SELECT r.*, e.name AS equipment_name
              FROM reservations r
              JOIN equipment e ON e.equipment_id = r.equipment_id
             WHERE r.equipment_id = :eq
               AND r.start_datetime < :end
               AND r.end_datetime   > :start';
    $params = ['eq' => $equipmentId, 'start' => $start, 'end' => $end];

    if ($excludeId !== null) {
        $sql .= ' AND r.reservation_id <> :skip';
        $params['skip'] = $excludeId;
    }
    return db_one($sql . ' LIMIT 1', $params);
}

/** The Sunday that begins the week containing $date. */
function week_start(string $date): string
{
    $ts = strtotime($date) ?: time();
    return date('Y-m-d', strtotime('-' . (int) date('w', $ts) . ' days', $ts));
}
