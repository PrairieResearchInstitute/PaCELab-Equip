<?php
/**
 * install.php — Creates the schema, seeds the unit list and the interface text,
 * and registers the first administrator. Locks itself after a successful run.
 *
 * Run check.php first.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$lockFile = dirname(LAB_DB_PATH) . '/.installed';

// --- The lock --------------------------------------------------------------
if (file_exists($lockFile)) {
    page_header('Already installed', ['chromeless' => true, 'mainClass' => 'page narrow']);
    echo '<h1>Already installed</h1>';
    echo '<p class="lede">This application has been installed and the installer has locked itself.</p>';
    echo '<p>Delete <code>' . h(basename(dirname($lockFile)) . '/.installed') . '</code> only if you intend to install over the existing database.</p>';
    echo '<p><a class="button" href="index.php">Open the application</a></p>';
    page_footer(['chromeless' => true]);
    exit;
}

/** The complete schema. Seven working tables plus a sign-in throttle table. */
function schema_statements(): array
{
    return [

        'CREATE TABLE IF NOT EXISTS units (
            unit_id   INTEGER PRIMARY KEY AUTOINCREMENT,
            code      TEXT NOT NULL,
            name      TEXT NOT NULL DEFAULT \'\',
            active    INTEGER NOT NULL DEFAULT 1
        )',

        // A laboratory. Everything a laboratory owns hangs off this row:
        // instruments, grants, bookings, charges, costs, and the people it
        // tells when something breaks. Adding a second laboratory adds a row
        // here and nothing else.
        'CREATE TABLE IF NOT EXISTS labs (
            lab_id       INTEGER PRIMARY KEY AUTOINCREMENT,
            code         TEXT NOT NULL UNIQUE,
            name         TEXT NOT NULL,
            description  TEXT NOT NULL DEFAULT \'\',
            unit_id      INTEGER REFERENCES units(unit_id),

            -- Who this laboratory tells. Was one set of settings when there was
            -- one laboratory; belongs to the laboratory now there are several.
            lab_group_name         TEXT NOT NULL DEFAULT \'\',
            lab_group_email        TEXT NOT NULL DEFAULT \'\',
            equipment_contact_name  TEXT NOT NULL DEFAULT \'\',
            equipment_contact_email TEXT NOT NULL DEFAULT \'\',
            equipment_contact_cc    TEXT NOT NULL DEFAULT \'\',

            active       INTEGER NOT NULL DEFAULT 1,
            created_at   TEXT NOT NULL
        )',

        // Who may work in which laboratory.
        //
        // person_key is whatever identifies a person today, folded to lower
        // case: the last name they type. When current_user_name() starts
        // returning a NetID, this column holds NetIDs instead and nothing else
        // about the table changes — which is the point of keying it on the
        // same string the rest of the application already uses.
        'CREATE TABLE IF NOT EXISTS lab_members (
            member_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            lab_id       INTEGER NOT NULL REFERENCES labs(lab_id),
            person_key   TEXT NOT NULL,
            display_name TEXT NOT NULL DEFAULT \'\',
            created_at   TEXT NOT NULL,
            UNIQUE (lab_id, person_key)
        )',

        // property_tag is the natural key PRI Facilities already uses for a
        // tagged item (its PropertyTag, shown as "university inventory
        // number"). Carrying the same value here means an instrument can be
        // matched between the two systems without either owning the other.
        'CREATE TABLE IF NOT EXISTS equipment (
            equipment_id        INTEGER PRIMARY KEY AUTOINCREMENT,
            lab_id              INTEGER NOT NULL REFERENCES labs(lab_id),
            property_tag        TEXT NOT NULL DEFAULT \'\',
            equip_class         TEXT NOT NULL DEFAULT \'instrument\',
            name                TEXT NOT NULL,
            manufacturer        TEXT NOT NULL DEFAULT \'\',
            model               TEXT NOT NULL DEFAULT \'\',
            location            TEXT NOT NULL DEFAULT \'\',
            rate                REAL NOT NULL DEFAULT 0,
            rate_unit           TEXT NOT NULL DEFAULT \'per sample\',
            rate_effective_date DATE,
            receiving_subaccount TEXT NOT NULL DEFAULT \'\',
            contact_person      TEXT NOT NULL DEFAULT \'\',

            -- Operational state, which is a different question from whether the
            -- instrument is still on the books. An instrument that is down is
            -- still active: it is in the laboratory, it has a rate, and it has a
            -- charge history. It just cannot be booked or charged today.
            status              TEXT NOT NULL DEFAULT \'available\',
            status_note         TEXT NOT NULL DEFAULT \'\',
            status_since        TEXT,
            service_due_date    DATE,

            active              INTEGER NOT NULL DEFAULT 1,
            created_at          TEXT NOT NULL
        )',

        // The short code lists an administrator may extend without a file edit.
        // Modelled on ref.PickLists in PRI Facilities, down to the protected
        // flag: a protected code is one the application itself branches on, so
        // it can be relabelled but not removed.
        'CREATE TABLE IF NOT EXISTS picklists (
            picklist_id INTEGER PRIMARY KEY AUTOINCREMENT,
            list_key    TEXT NOT NULL,
            code        TEXT NOT NULL,
            label       TEXT NOT NULL,
            sort_order  INTEGER NOT NULL DEFAULT 100,
            active      INTEGER NOT NULL DEFAULT 1,
            protected   INTEGER NOT NULL DEFAULT 0,
            UNIQUE (list_key, code)
        )',

        // cfopa is the five-segment account string the business office issues
        // and the natural key the PRI Suite keys its own account tables on.
        // cfopa_base and activity_code are derived from it on save, so a
        // sub-account can be grouped under its parent without parsing at query
        // time.
        'CREATE TABLE IF NOT EXISTS grants (
            grant_id              INTEGER PRIMARY KEY AUTOINCREMENT,
            lab_id                INTEGER NOT NULL REFERENCES labs(lab_id),
            -- Unique within a laboratory, not across the institute: two
            -- laboratories charging the same award is normal, and each keeps
            -- its own row with its own dates and label.
            cfopa                 TEXT NOT NULL,
            cfopa_base            TEXT NOT NULL DEFAULT \'\',
            activity_code         TEXT NOT NULL DEFAULT \'A00\',
            title                 TEXT NOT NULL DEFAULT \'\',
            display_label         TEXT NOT NULL DEFAULT \'\',
            principal_investigator TEXT NOT NULL DEFAULT \'\',
            unit_id               INTEGER REFERENCES units(unit_id),
            start_date            DATE,
            end_date              DATE,
            active                INTEGER NOT NULL DEFAULT 1,
            created_at            TEXT NOT NULL
        )',

        // What an instrument costs to keep running: consumables, repairs,
        // parts, calibration, and the contracts that cover it. Set against the
        // charges it earns, this is what says whether a rate is right.
        //
        // covers_start and covers_end are what make a warranty different from a
        // bag of pipette tips: the money was spent once, but it buys a period,
        // and the end of that period is worth a warning.
        'CREATE TABLE IF NOT EXISTS equipment_costs (
            cost_id         INTEGER PRIMARY KEY AUTOINCREMENT,
            equipment_id    INTEGER NOT NULL REFERENCES equipment(equipment_id),
            cost_date       DATE NOT NULL,
            category        TEXT NOT NULL DEFAULT \'consumable\',
            description     TEXT NOT NULL DEFAULT \'\',
            vendor          TEXT NOT NULL DEFAULT \'\',
            amount          REAL NOT NULL DEFAULT 0,
            paid_from_cfopa TEXT NOT NULL DEFAULT \'\',
            reference       TEXT NOT NULL DEFAULT \'\',
            covers_start    DATE,
            covers_end      DATE,
            notes           TEXT NOT NULL DEFAULT \'\',
            created_at      TEXT NOT NULL,
            created_by      TEXT NOT NULL DEFAULT \'\'
        )',

        // Every message the application has composed, with the words it used.
        // It hands messages to a mail client rather than sending them, so
        // "opened" is the strongest thing it can honestly claim; the log says
        // that rather than pretending the message went.
        'CREATE TABLE IF NOT EXISTS email_log (
            email_id     INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at   TEXT NOT NULL,
            created_by   TEXT NOT NULL DEFAULT \'\',
            purpose      TEXT NOT NULL DEFAULT \'\',
            to_address   TEXT NOT NULL DEFAULT \'\',
            cc_address   TEXT NOT NULL DEFAULT \'\',
            subject      TEXT NOT NULL DEFAULT \'\',
            body         TEXT NOT NULL DEFAULT \'\',
            equipment_id INTEGER REFERENCES equipment(equipment_id),
            opened_at    TEXT,
            status       TEXT NOT NULL DEFAULT \'composed\'
        )',

        'CREATE TABLE IF NOT EXISTS export_batches (
            batch_id     INTEGER PRIMARY KEY AUTOINCREMENT,
            lab_id       INTEGER NOT NULL REFERENCES labs(lab_id),
            period_start DATE NOT NULL,
            period_end   DATE NOT NULL,
            generated_at TEXT NOT NULL,
            generated_by TEXT NOT NULL DEFAULT \'\',
            record_count INTEGER NOT NULL DEFAULT 0,
            total_amount REAL NOT NULL DEFAULT 0,
            reopened     INTEGER NOT NULL DEFAULT 0
        )',

        'CREATE TABLE IF NOT EXISTS usage_records (
            usage_id             INTEGER PRIMARY KEY AUTOINCREMENT,
            equipment_id         INTEGER NOT NULL REFERENCES equipment(equipment_id),
            grant_id             INTEGER NOT NULL REFERENCES grants(grant_id),
            operator_name        TEXT NOT NULL DEFAULT \'\',
            use_date             DATE NOT NULL,
            sample_count         INTEGER NOT NULL DEFAULT 1,
            rate_charged         REAL NOT NULL DEFAULT 0,
            rate_unit_charged    TEXT NOT NULL DEFAULT \'\',
            receiving_subaccount TEXT NOT NULL DEFAULT \'\',
            total_charge         REAL NOT NULL DEFAULT 0,
            batch_identifier     TEXT NOT NULL DEFAULT \'\',
            notes                TEXT NOT NULL DEFAULT \'\',
            exported             INTEGER NOT NULL DEFAULT 0,
            export_batch_id      INTEGER REFERENCES export_batches(batch_id),
            voided               INTEGER NOT NULL DEFAULT 0,
            void_reason          TEXT NOT NULL DEFAULT \'\',
            created_at           TEXT NOT NULL
        )',

        'CREATE TABLE IF NOT EXISTS reservations (
            reservation_id INTEGER PRIMARY KEY AUTOINCREMENT,
            equipment_id   INTEGER NOT NULL REFERENCES equipment(equipment_id),
            reserved_by    TEXT NOT NULL DEFAULT \'\',
            start_datetime TEXT NOT NULL,
            end_datetime   TEXT NOT NULL,
            purpose        TEXT NOT NULL DEFAULT \'\',
            usage_id       INTEGER REFERENCES usage_records(usage_id),
            created_at     TEXT NOT NULL
        )',

        'CREATE TABLE IF NOT EXISTS admin_users (
            user_id       INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            display_name  TEXT NOT NULL DEFAULT \'\',
            active        INTEGER NOT NULL DEFAULT 1,
            created_at    TEXT NOT NULL,
            last_login    TEXT
        )',

        'CREATE TABLE IF NOT EXISTS settings (
            "key"  TEXT PRIMARY KEY,
            value  TEXT NOT NULL DEFAULT \'\'
        )',

        // Sign-in throttling needs server-side state; a session cookie the
        // caller controls cannot slow anybody down.
        'CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_id   INTEGER PRIMARY KEY AUTOINCREMENT,
            ip           TEXT NOT NULL DEFAULT \'\',
            username     TEXT NOT NULL DEFAULT \'\',
            succeeded    INTEGER NOT NULL DEFAULT 0,
            attempted_at TEXT NOT NULL
        )',

        // Overlap tests run on every calendar action, so the index earns its keep.
        'CREATE INDEX IF NOT EXISTS idx_reservations_equipment_start ON reservations (equipment_id, start_datetime)',
        'CREATE INDEX IF NOT EXISTS idx_reservations_equipment_end   ON reservations (equipment_id, end_datetime)',
        'CREATE INDEX IF NOT EXISTS idx_usage_use_date   ON usage_records (use_date)',
        'CREATE INDEX IF NOT EXISTS idx_usage_exported   ON usage_records (exported, voided)',
        'CREATE INDEX IF NOT EXISTS idx_usage_grant      ON usage_records (grant_id)',
        'CREATE INDEX IF NOT EXISTS idx_usage_equipment  ON usage_records (equipment_id)',
        'CREATE INDEX IF NOT EXISTS idx_grants_base      ON grants (cfopa_base)',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_grants_lab_cfopa ON grants (lab_id, cfopa)',
        'CREATE INDEX IF NOT EXISTS idx_equipment_lab    ON equipment (lab_id, active)',
        'CREATE INDEX IF NOT EXISTS idx_lab_members_key  ON lab_members (person_key)',
        'CREATE INDEX IF NOT EXISTS idx_attempts_time    ON login_attempts (attempted_at)',

        // A tag identifies exactly one instrument, but most laboratories have
        // instruments with no tag at all, so the blanks are exempt.
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_equipment_tag ON equipment (property_tag) WHERE property_tag <> \'\'',
    ];
}

/**
 * The short code lists, seeded with the vocabulary PRI Facilities already uses
 * so the two systems describe an instrument the same way.
 */
function seed_picklists(): array
{
    return [
        // list_key,      code,            label,                              sort, protected
        ['equip_class',  'instrument',    'Laboratory instrument',              10, 0],
        ['equip_class',  'computer',      'Computer or peripheral',             20, 0],
        ['equip_class',  'field',         'Field equipment',                    30, 0],
        ['equip_class',  'vehicle',       'Vehicle',                            40, 0],
        ['equip_class',  'furniture',     'Furniture',                          50, 0],
        ['equip_class',  'shop',          'Shop equipment',                     60, 0],
        ['equip_class',  'av',            'Audio visual',                       70, 0],
        ['equip_class',  'other',         'Other',                              80, 0],

        // The three rate units the charge arithmetic understands. Each is
        // protected because the entry form names the count after it.
        ['rate_unit',    'per sample',    'per sample',                         10, 1],
        ['rate_unit',    'per hour',      'per hour',                           20, 1],
        ['rate_unit',    'per run',       'per run',                            30, 1],

        // Operational state. All four are protected: the application decides
        // what can be booked and charged by branching on these exact codes.
        ['equip_status', 'available',     'Available',                          10, 1],
        ['equip_status', 'service_due',   'Service due',                        20, 1],
        ['equip_status', 'down',          'Down, out of service',               30, 1],
        ['equip_status', 'retired',       'Retired',                            40, 1],

        // What money spent on an instrument was spent on. Warranty and service
        // contract are protected: the alert that says cover is running out
        // looks for exactly those two.
        ['cost_category', 'consumable',       'Consumables',                    10, 0],
        ['cost_category', 'repair',           'Repair',                         20, 0],
        ['cost_category', 'parts',            'Parts',                          30, 0],
        ['cost_category', 'service_contract', 'Service contract',               40, 1],
        ['cost_category', 'warranty',         'Warranty',                       50, 1],
        ['cost_category', 'calibration',      'Calibration',                    60, 0],
        ['cost_category', 'software',         'Software or licence',            70, 0],
        ['cost_category', 'training',         'Training',                       80, 0],
        ['cost_category', 'other',            'Other',                          90, 0],
    ];
}

/** Survey codes, seeded so adding a unit later needs no code change. */
function seed_units(): array
{
    return [
        ['OED',  'Office of the Executive Director'],
        ['INHS', 'Illinois Natural History Survey'],
        ['ISTC', 'Illinois Sustainable Technology Center'],
        ['ISGS', 'Illinois State Geological Survey'],
        ['ISWS', 'Illinois State Water Survey'],
        ['ISAS', 'Illinois State Archaeological Survey'],
    ];
}

/** Interface text, all editable later from the administrative panel. */
function seed_settings(string $labName): array
{
    return [
        'lab_name'            => $labName,
        // The survey the application belongs to. It names the banner and the
        // footer, so another survey can run this same code by changing two
        // settings rather than editing a file.
        // The thresholds the alert list works from. Every one of them is a
        // judgement the laboratory should be able to change without asking
        // anybody to edit a file.
        'utilisation_hours_per_week' => '50',
        'utilisation_window_days'    => '28',
        'utilisation_high_pct'       => '75',
        'utilisation_low_pct'        => '10',
        'warranty_warning_days'      => '60',

        'lab_group_name'      => 'the laboratory group',
        'unit_name'           => 'Illinois Natural History Survey',
        'unit_url'            => 'https://inhs.illinois.edu',
        'footer_note'         => 'Illinois Natural History Survey, Prairie Research Institute.',
        'entry_title'         => 'Record equipment use',
        'entry_instructions'  => 'Choose the instrument, set the date of the run, and enter how many samples, hours, or runs it took. The rate and the account come from the instrument record.',
        'schedule_title'      => 'Instrument calendar',
        'schedule_instructions' => 'Drag down a day column to book time. Drag a block to move it, or drag an edge to change its length. Bookings are first come, first served.',
        'report_title'        => 'Monthly billing report',
        'report_instructions' => 'The default range is last month and the default filter is records not yet exported, which together make the normal monthly run.',
        'admin_title'         => 'Administration',
    ];
}

// --- Handle the form -------------------------------------------------------
$errors   = [];
$labName  = trim((string) ($_POST['lab_name'] ?? 'Shared Laboratory Equipment'));
$username = trim((string) ($_POST['username'] ?? ''));
$display  = trim((string) ($_POST['display_name'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($labName === '')                       { $errors[] = 'Give the laboratory a name.'; }
    if ($username === '')                      { $errors[] = 'Choose a username for the first administrator.'; }
    if (strlen($password) < 10)                { $errors[] = 'The administrator password must be at least ten characters.'; }
    if ($password !== $confirm)                { $errors[] = 'The two passwords do not match.'; }

    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            foreach (schema_statements() as $sql) {
                $pdo->exec($sql);
            }

            $now = date('Y-m-d H:i:s');

            if ((int) db_value('SELECT COUNT(*) FROM units') === 0) {
                foreach (seed_units() as [$code, $name]) {
                    db_run('INSERT INTO units (code, name, active) VALUES (?, ?, 1)', [$code, $name]);
                }
            }

            foreach (seed_picklists() as [$list, $code, $label, $sort, $protected]) {
                db_run('INSERT OR IGNORE INTO picklists (list_key, code, label, sort_order, active, protected)
                        VALUES (?, ?, ?, ?, 1, ?)', [$list, $code, $label, $sort, $protected]);
            }

            foreach (seed_settings($labName) as $key => $value) {
                db_run('INSERT OR IGNORE INTO settings ("key", value) VALUES (?, ?)', [$key, $value]);
            }

            db_run(
                'INSERT INTO admin_users (username, password_hash, display_name, active, created_at)
                 VALUES (?, ?, ?, 1, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $display ?: $username, $now]
            );

            $pdo->commit();

            // Make sure the database file itself is not downloadable.
            $dataDir = dirname(LAB_DB_PATH);
            if (!file_exists($dataDir . '/.htaccess')) {
                @file_put_contents($dataDir . '/.htaccess',
                    "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
            }

            @file_put_contents($lockFile, "Installed " . $now . "\n");

            flash('Installation finished. Add your instruments and grants next.', 'success');
            redirect('admin/login.php');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}

page_header('Install', ['chromeless' => true, 'mainClass' => 'page narrow']);
?>
<h1>Install the equipment system</h1>
<p class="lede">This creates the database, seeds the survey unit list and the on-screen wording, and registers the first administrator. It runs once and then locks itself.</p>

<?php if (!file_exists(__DIR__ . '/check.php')): ?>
  <div class="flash flash-notice">check.php is not in this directory. If you have not yet confirmed that the server provides PHP and PDO SQLite, do that first.</div>
<?php else: ?>
  <p><a class="link-quiet" href="check.php">Run the server capability check first &rarr;</a></p>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<form method="post" class="card form">
  <?= csrf_field() ?>

  <div class="field">
    <label for="lab_name">Laboratory name</label>
    <input type="text" id="lab_name" name="lab_name" value="<?= h($labName) ?>" required>
    <p class="hint">Shown in the header of every screen. Changeable later from the administrative panel.</p>
  </div>

  <h2>First administrator</h2>

  <div class="field-row">
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= h($username) ?>" autocomplete="username" required>
    </div>
    <div class="field">
      <label for="display_name">Display name</label>
      <input type="text" id="display_name" name="display_name" value="<?= h($display) ?>" autocomplete="name">
    </div>
  </div>

  <div class="field-row">
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required minlength="10">
      <p class="hint">Ten characters or more.</p>
    </div>
    <div class="field">
      <label for="password_confirm">Password again</label>
      <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required minlength="10">
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="button">Create the database</button>
  </div>
</form>

<p class="hint">The database will be written to <code><?= h(LAB_DB_PATH) ?></code>.</p>
<?php
page_footer(['chromeless' => true]);
