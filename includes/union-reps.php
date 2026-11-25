<?php
/**
 * Union Reps — shows each logged-in member their Union Representative (by unit number)
 * and routes the "Contact my Union Rep" Elementor form to that rep, BCC the Division Director.
 *
 * Data (one option, `ur_rep_data`):
 *   reps      [slug => name, title, email, phone, photo (attachment id), division, role (rep|director|other)]
 *   units     [unit => [rep => slug, division => 'Oahu', temporary => bool]]
 *   divisions [division => [effective, source, updated]]
 * The previous version is kept in `ur_rep_data_prev` for one-click undo.
 *
 * Updating: Union Roll → Union Reps. Upload the division's "Unit Assignments" .docx, review the
 * changes, Apply. Rep contact details and photos are imported from the Staff page (with a preview).
 *
 * Routing is decided on the server from the logged-in member's own unit; nothing posted by the
 * browser chooses a recipient. Test mode (default ON) sends everything to the test address only.
 */

if (!defined('ABSPATH')) exit;

const UR_REP_DIVISIONS = ['Hawaii', 'Kauai', 'Maui', 'Oahu'];

function ur_rep_defaults() {
    return ['reps' => [], 'units' => [], 'divisions' => []];
}

function ur_rep_data() {
    static $d = null;
    if ($d === null) {
        $d = get_option('ur_rep_data');
        if (!is_array($d)) $d = ur_rep_defaults();
        $d += ur_rep_defaults();
    }
    return $d;
}

function ur_rep_save_data(array $new, $keep_prev = true) {
    if ($keep_prev) update_option('ur_rep_data_prev', get_option('ur_rep_data', ur_rep_defaults()), false);
    update_option('ur_rep_data', $new, false);
}

function ur_rep_settings() {
    $s = get_option('ur_rep_settings');
    $s = is_array($s) ? $s : [];
    return $s + [
        'form_name'      => 'Contact my Union Rep',
        'test_mode'      => 1,
        'test_recipient' => '',
        'fallback_email' => '',
        'show_temporary' => 0,
    ];
}

function ur_rep_norm_name($name) {
    $name = remove_accents(wp_strip_all_tags((string) $name));
    $name = preg_replace('/\s*[-–]\s*temporary\s*$/i', '', $name);
    $name = str_replace(['’', "'", '‘', 'ʻ', '`'], '', $name);
    return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
}

/** Division from a unit: the assignment's division, else the first digit (1 Hawaii, 2 Kauai, 3 Maui, 4 Oahu). */
function ur_rep_division_for_unit($unit) {
    $unit = trim((string) $unit);
    $d = ur_rep_data();
    if (!empty($d['units'][$unit]['division'])) return $d['units'][$unit]['division'];
    $map = ['1' => 'Hawaii', '2' => 'Kauai', '3' => 'Maui', '4' => 'Oahu'];
    return $map[substr($unit, 0, 1)] ?? '';
}

function ur_rep_director($division) {
    foreach (ur_rep_data()['reps'] as $slug => $r) {
        if (($r['role'] ?? '') === 'director' && ($r['division'] ?? '') === $division) return $r + ['slug' => $slug];
    }
    return null;
}

/**
 * The member's rep context. $user_id null = the viewer (honours the admin "view as member" preview).
 * @return array{unit:string,division:string,rep:?array,director:?array,temporary:bool}
 */
function ur_rep_for_user($user_id = null) {
    if ($user_id === null) {
        $user_id = function_exists('ur_mg_effective_user_id') ? ur_mg_effective_user_id() : get_current_user_id();
    }
    $unit = trim((string) get_user_meta((int) $user_id, 'unit_number', true));
    $d = ur_rep_data();
    $a = $d['units'][$unit] ?? null;
    $division = ur_rep_division_for_unit($unit);
    $rep = ($a && isset($d['reps'][$a['rep']])) ? $d['reps'][$a['rep']] + ['slug' => $a['rep']] : null;
    return [
        'unit'      => $unit,
        'division'  => $division,
        'rep'       => $rep,
        'director'  => $division ? ur_rep_director($division) : null,
        'temporary' => !empty($a['temporary']),
    ];
}

function ur_rep_phone_link($phone) {
    // "(808) 847-2631 ext. 244" -> tel:+18088472631,244
    if (!preg_match('/\(?(\d{3})\)?[\s.-]*(\d{3})[\s.-]*(\d{4})(?:\s*(?:ext\.?|x)\s*(\d+))?/i', (string) $phone, $m)) return '';
    return 'tel:+1' . $m[1] . $m[2] . $m[3] . (!empty($m[4]) ? ',' . $m[4] : '');
}

// ─────────────────────────────────────────────────────────────────────────────
// Front end: [ur_my_rep] card (also shown inside [ur_for_you])
// ─────────────────────────────────────────────────────────────────────────────

function ur_rep_card_css() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>.ur-rep-card{display:flex;gap:22px;align-items:center;flex-wrap:wrap;background:var(--e-global-color-primary,#1C244B);color:#F3F5F8;border-top:4px solid #D3C89D;border-radius:6px;padding:22px;font-family:Poppins,sans-serif;margin:0 0 20px}'
        . '.ur-rep-card img{width:110px;height:110px;border-radius:50%;object-fit:cover;border:3px solid #D3C89D;flex:none}'
        . '.ur-rep-card .ur-rep-body{flex:1;min-width:220px}.ur-rep-card .ur-rep-kicker{font-size:14px;font-weight:300;color:#C8D5DC;margin:0 0 2px}'
        . '.ur-rep-card h3{font-family:Poppins,sans-serif;font-size:25px;font-weight:600;line-height:1.2;margin:0 0 4px;color:#fff}'
        . '.ur-rep-card .ur-rep-title{font-weight:300;margin:0 0 10px}.ur-rep-card .ur-rep-lines{font-size:16px;font-weight:300;line-height:1.6}'
        . '.ur-rep-card a{color:#fff;font-weight:600;text-decoration:none}.ur-rep-card a:hover,.ur-rep-card a:focus{color:#D3C89D;text-decoration:underline}'
        . '.ur-rep-card .ur-rep-btn{display:inline-block;margin-top:12px;background:#D3C89D;color:#fff;padding:10px 22px;border-radius:4px;font-weight:600;text-decoration:none}'
        . '.ur-rep-card .ur-rep-btn:hover,.ur-rep-card .ur-rep-btn:focus{background:#fff;color:#D3C89D;text-decoration:none}'
        . '.ur-rep-offices{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;font-family:Poppins,sans-serif}'
        . '.ur-rep-offices div{background:var(--e-global-color-primary,#1C244B);color:#F3F5F8;border-top:4px solid #D3C89D;border-radius:6px;padding:16px 18px}'
        . '.ur-rep-offices strong{display:block;color:#fff;font-size:20px;font-weight:600}.ur-rep-offices a{color:#fff;font-weight:600}'
        . '@media (max-width:767px){.ur-rep-card h3{font-size:22px}.ur-rep-card img{width:84px;height:84px}}</style>';
}

function ur_rep_offices_html() {
    $html = '<div class="ur-rep-offices">';
    foreach (UR_REP_DIVISIONS as $div) {
        $dir = ur_rep_director($div);
        if (!$dir) continue;
        $tel = ur_rep_phone_link($dir['phone'] ?? '');
        $phone = preg_replace('/\s*ext\.?\s*\d+/i', '', (string) ($dir['phone'] ?? ''));
        $html .= '<div><strong>' . esc_html($div) . ' Division</strong>'
            . ($phone ? '<a href="' . esc_url(preg_replace('/,\d+$/', '', $tel)) . '">' . esc_html($phone) . '</a>' : '') . '</div>';
    }
    return $html . '</div>';
}

function ur_rep_card_html($ctx, $form_anchor = '#contact-my-rep') {
    $person = $ctx['rep'] ?: $ctx['director'];
    if (!$person) return '';
    $s = ur_rep_settings();
    $kicker = $ctx['rep'] ? 'Your Union Representative' : 'Your ' . $ctx['division'] . ' Division contact';
    $img = !empty($person['photo']) ? wp_get_attachment_image((int) $person['photo'], 'medium', false, ['alt' => $person['name'], 'loading' => 'lazy']) : '';
    $lines = [];
    if (!empty($person['phone'])) $lines[] = '<a href="' . esc_url(ur_rep_phone_link($person['phone'])) . '">' . esc_html($person['phone']) . '</a>';
    if ($ctx['unit'] !== '') $lines[] = 'Unit ' . esc_html($ctx['unit']) . ($ctx['division'] ? ' · ' . esc_html($ctx['division']) . ' Division' : '');
    $title = $person['title'] ?? '';
    if ($ctx['rep'] && $ctx['temporary'] && !empty($s['show_temporary'])) $title .= ' (temporary)';
    return ur_rep_card_css() . '<div class="ur-rep-card">' . $img . '<div class="ur-rep-body">'
        . '<p class="ur-rep-kicker">' . esc_html($kicker) . '</p><h3>' . esc_html($person['name']) . '</h3>'
        . ($title ? '<p class="ur-rep-title">' . esc_html($title) . '</p>' : '')
        . '<div class="ur-rep-lines">' . implode('<br>', $lines) . '</div>'
        . ($form_anchor ? '<a class="ur-rep-btn" href="' . esc_url($form_anchor) . '">Send a message</a>' : '')
        . '</div></div>';
}

add_shortcode('ur_my_rep', function ($atts) {
    $atts = shortcode_atts(['form' => '#contact-my-rep'], $atts, 'ur_my_rep');
    if (!is_user_logged_in()) {
        $login = function_exists('wpmem_login_url') ? wpmem_login_url(get_permalink()) : wp_login_url(get_permalink());
        return ur_rep_card_css() . '<p style="font-family:Poppins,sans-serif">Log in to see your Union Representative and send them a message. '
            . '<a href="' . esc_url($login) . '">Log in</a></p>' . ur_rep_offices_html();
    }
    $ctx = ur_rep_for_user();
    $card = ur_rep_card_html($ctx, $atts['form']);
    if ($card === '') {
        return ur_rep_card_css() . '<p style="font-family:Poppins,sans-serif">We couldn\'t match your unit to a representative yet. Please call your division office:</p>' . ur_rep_offices_html();
    }
    return $card;
});

// The card and the logged-out text differ per viewer: never serve them from Elementor's element cache.
add_filter('elementor/element/is_dynamic_content', function ($is_dynamic, $raw_data) {
    if ($is_dynamic) return $is_dynamic;
    return strpos((string) wp_json_encode($raw_data['settings'] ?? []), 'ur_my_rep') !== false;
}, 11, 2);

// ─────────────────────────────────────────────────────────────────────────────
// Elementor Pro form routing (form name = setting "form_name")
// ─────────────────────────────────────────────────────────────────────────────

function ur_rep_is_our_form($record) {
    return is_object($record) && trim((string) $record->get_form_settings('form_name')) === trim(ur_rep_settings()['form_name']);
}

/** Route for the logged-in submitter, computed once per request. */
function ur_rep_route() {
    static $route = null;
    if ($route !== null) return $route;
    $uid = get_current_user_id();
    $user = $uid ? get_userdata($uid) : null;
    if (!$user) return $route = [];
    $ctx = ur_rep_for_user($uid);
    $s = ur_rep_settings();
    $to = $ctx['rep']['email'] ?? '';
    $bcc = $ctx['director']['email'] ?? '';
    if ($to === '') { $to = $bcc; $bcc = ''; }
    if ($to === '') $to = $s['fallback_email'];
    if (strcasecmp($to, $bcc) === 0) $bcc = '';
    $phone = (string) get_user_meta($uid, 'phone1', true);
    return $route = [
        'user'     => $user,
        'ctx'      => $ctx,
        'to'       => sanitize_email($to),
        'bcc'      => sanitize_email($bcc),
        'member'   => [
            'member_name'  => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
            'member_email' => $user->user_email,
            'member_phone' => $phone,
            'member_unit'  => $ctx['unit'],
        ],
    ];
}

add_action('elementor_pro/forms/validation', function ($record, $ajax_handler) {
    if (!ur_rep_is_our_form($record)) return;
    if (!is_user_logged_in()) {
        $ajax_handler->add_error_message('Please log in to send a message to your Union Representative.');
        return;
    }
    $r = ur_rep_route();
    if (empty($r['to'])) {
        $ajax_handler->add_error_message('We couldn\'t find a representative for your unit. Please call your division office.');
    }
}, 10, 2);

// Fill the audit fields (if the form has them) from the account, overwriting anything posted.
add_filter('elementor_pro/forms/record/actions_before', function ($record, $ajax_handler) {
    if (!ur_rep_is_our_form($record) || !is_user_logged_in()) return $record;
    $r = ur_rep_route();
    if (!$r) return $record;
    $vals = $r['member'] + ['routed_to' => $r['to'] . ($r['bcc'] ? ' (BCC ' . $r['bcc'] . ')' : '') . (ur_rep_settings()['test_mode'] ? ' [TEST MODE]' : '')];
    foreach ($vals as $id => $v) {
        $record->update_field($id, 'value', $v);
        $record->update_field($id, 'raw_value', $v);
    }
    return $record;
}, 10, 2);

add_filter('elementor_pro/forms/wp_mail_fields', function ($fields, $record) {
    if (!ur_rep_is_our_form($record) || !is_user_logged_in()) return $fields;
    $r = ur_rep_route();
    if (!$r || empty($r['to'])) return $fields;
    // Leave a second "confirmation to the member" email action alone.
    if (strcasecmp(trim($fields['email_to']), $r['member']['member_email']) === 0) return $fields;

    $s = ur_rep_settings();
    $m = $r['member'];
    $html = strpos((string) $fields['email_content'], '<br>') !== false;
    $lines = [
        'Message from ' . $m['member_name'] . ' (unit ' . ($m['member_unit'] ?: 'unknown') . ')',
        'Email: ' . $m['member_email'] . ($m['member_phone'] ? ' · Phone: ' . $m['member_phone'] : ''),
        'Reply to this email to answer the member directly.',
        '---',
    ];
    if ($html) $lines = array_map('esc_html', $lines);
    $br = $html ? '<br>' : "\n";
    $fields['email_content'] = implode($br, $lines) . $br . $fields['email_content'];

    if (!empty($s['test_mode'])) {
        $test = sanitize_email($s['test_recipient']);
        $fields['email_subject'] = '[TEST → ' . $r['to'] . ($r['bcc'] ? ', BCC ' . $r['bcc'] : '') . '] ' . $fields['email_subject'];
        $fields['email_to'] = $test ?: get_option('admin_email');
        $fields['email_to_cc'] = '';
        $fields['email_to_bcc'] = '';
    } else {
        $fields['email_to'] = $r['to'];
        $fields['email_to_cc'] = '';
        $fields['email_to_bcc'] = $r['bcc'];
    }
    $GLOBALS['ur_rep_reply_to'] = $m['member_email'];
    return $fields;
}, 10, 2);

// Reply-To = the member, so the rep can answer directly. (Elementor builds Reply-To before the fields filter.)
add_filter('elementor_pro/forms/wp_mail_headers', function ($headers) {
    $to = $GLOBALS['ur_rep_reply_to'] ?? '';
    if (!$to || !is_string($headers) || !is_email($to)) return $headers;
    return preg_replace('/^Reply-To:.*$/mi', 'Reply-To: ' . $to, $headers);
});

// ─────────────────────────────────────────────────────────────────────────────
// Parsing: the division's "Unit Assignments" .docx, and the Staff page
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{division:string,effective:string,reps:array,warnings:string[]}|WP_Error */
function ur_rep_parse_docx($path) {
    if (!class_exists('ZipArchive')) return new WP_Error('zip', 'This server cannot open .docx files (ZipArchive missing).');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return new WP_Error('zip', 'Could not open the file. Is it a .docx?');
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return new WP_Error('docx', 'No document text found in the file.');

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadXML($xml, LIBXML_NONET);
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $out = ['division' => '', 'effective' => '', 'reps' => [], 'warnings' => []];
    $cur = null;
    foreach ($xp->query('//w:body//w:p') as $p) {
        $text = '';
        foreach ($xp->query('.//w:t', $p) as $t) $text .= $t->textContent;
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $text)));
        if ($text === '') continue;
        $bold = false;
        foreach ($xp->query('.//w:r[w:t]', $p) as $run) {
            $b = $xp->query('./w:rPr/w:b', $run)->item(0);
            if ($b && !in_array(strtolower($b->getAttribute('w:val')), ['0', 'false'], true) && trim($run->textContent) !== '') { $bold = true; break; }
        }
        if (preg_match('/^(oahu|maui|kauai|kaua.i|hawaii|hawai.i)\s+division\b/iu', $text, $m)) {
            $d = ucfirst(strtolower(remove_accents(str_replace(['ʻ', "'", '’'], '', $m[1]))));
            $out['division'] = in_array($d, UR_REP_DIVISIONS, true) ? $d : '';
            continue;
        }
        if (preg_match('/^effective\s+(.+)$/i', $text, $m)) { $out['effective'] = trim($m[1]); continue; }
        if (preg_match('/unit\s+assignments/i', $text)) continue;

        // A unit line starts with digits; take every 3-digit number before the first letter.
        if (preg_match('/^\d/', $text)) {
            $lead = preg_split('/[A-Za-z]/', $text, 2)[0];
            preg_match_all('/\b(\d{3})\b/', $lead, $mm);
            $label = trim(substr($text, strlen($lead)));
            if (!$cur) { $out['warnings'][] = 'Unit line before any rep name: "' . $text . '"'; continue; }
            foreach ($mm[1] as $u) $out['reps'][$cur]['units'][$u] = $label;
            continue;
        }
        if ($bold) {
            $temporary = (bool) preg_match('/[-–]\s*temporary\s*$/i', $text);
            $name = trim(preg_replace('/\s*[-–]\s*temporary\s*$/i', '', $text));
            $cur = ur_rep_norm_name($name);
            $out['reps'][$cur] = ['name' => $name, 'temporary' => $temporary, 'units' => []];
            continue;
        }
        $out['warnings'][] = 'Line not understood (skipped): "' . $text . '"';
    }
    if ($out['division'] === '') $out['warnings'][] = 'No "<Island> DIVISION" heading found.';
    return $out;
}

/** People listed under "<Island> Division" headings on the Staff page (Essential Addons team members). */
function ur_rep_parse_staff_page($page_id) {
    $data = json_decode((string) get_post_meta((int) $page_id, '_elementor_data', true), true);
    if (!is_array($data)) return new WP_Error('staff', 'Staff page has no Elementor data.');
    $people = [];
    $section = '';
    $walk = function ($els) use (&$walk, &$people, &$section) {
        foreach ($els as $e) {
            $s = $e['settings'] ?? [];
            $type = $e['widgetType'] ?? '';
            if ($type === 'heading') $section = trim(wp_strip_all_tags((string) ($s['title'] ?? $section)));
            if ($type === 'eael-team-member' && preg_match('/^(\w+)\s+Division$/i', $section, $m) && in_array(ucfirst(strtolower($m[1])), UR_REP_DIVISIONS, true)) {
                $name = trim(wp_strip_all_tags((string) ($s['eael_team_member_name'] ?? '')));
                $title = trim(wp_strip_all_tags((string) ($s['eael_team_member_job_title'] ?? '')));
                $desc = wp_strip_all_tags((string) ($s['eael_team_member_description'] ?? ''));
                preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $desc, $em);
                preg_match('/\(\d{3}\)\s*\d{3}-\d{4}(?:\s*ext\.?\s*\d+)?/i', $desc, $ph);
                if ($name === '') continue;
                $role = (preg_match('/division director/i', $title) && !preg_match('/assistant/i', $title)) ? 'director' : (preg_match('/representative|assistant division director/i', $title) ? 'rep' : 'other');
                $people[sanitize_title($name)] = [
                    'name' => $name, 'title' => $title, 'email' => strtolower($em[0] ?? ''), 'phone' => $ph[0] ?? '',
                    'photo' => (int) ($s['eael_team_member_image']['id'] ?? 0), 'division' => ucfirst(strtolower($m[1])), 'role' => $role,
                ];
            }
            if (!empty($e['elements'])) $walk($e['elements']);
        }
    };
    $walk($data);
    return $people;
}

function ur_rep_staff_page_id() {
    $p = get_page_by_path('about/staff') ?: get_page_by_path('staff');
    return $p ? $p->ID : 1146;
}

function ur_rep_unit_member_counts() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT meta_value u, COUNT(*) n FROM {$wpdb->usermeta} WHERE meta_key = 'unit_number' AND meta_value <> '' GROUP BY meta_value", ARRAY_A);
    $out = [];
    foreach ($rows as $r) $out[$r['u']] = (int) $r['n'];
    return $out;
}

function ur_rep_known_units() {
    if (!function_exists('wpmem_fields')) return [];
    $f = wpmem_fields();
    return array_filter(array_keys((array) ($f['unit_number']['options'] ?? [])), 'strlen');
}

/** Build the new data set from a parsed .docx and describe every change. */
function ur_rep_plan_from_docx(array $parsed) {
    $d = ur_rep_data();
    $new = $d;
    $div = $parsed['division'];
    $byname = [];
    foreach ($d['reps'] as $slug => $r) $byname[ur_rep_norm_name($r['name'])] = $slug;
    $known = array_flip(ur_rep_known_units());
    $counts = ur_rep_unit_member_counts();
    $report = ['division' => $div, 'effective' => $parsed['effective'], 'changes' => [], 'errors' => [], 'notes' => $parsed['warnings']];

    $seen = [];
    foreach ($parsed['reps'] as $key => $rep) {
        $slug = $byname[$key] ?? '';
        if ($slug === '') { $report['errors'][] = 'Rep "' . $rep['name'] . '" is not in the reps list. Add them (Import from Staff page, or Add a rep) and upload again.'; continue; }
        foreach ($rep['units'] as $u => $label) {
            if (isset($seen[$u])) { $report['errors'][] = 'Unit ' . $u . ' is listed under two reps (' . $seen[$u] . ' and ' . $rep['name'] . ').'; continue; }
            $seen[$u] = $rep['name'];
            if ($known && !isset($known[$u])) $report['notes'][] = 'Unit ' . $u . ' (' . $label . ') is not in the website\'s unit list.';
            $old = $d['units'][$u]['rep'] ?? '';
            $new['units'][$u] = ['rep' => $slug, 'division' => $div, 'temporary' => !empty($rep['temporary'])];
            if ($old !== $slug) {
                $report['changes'][] = 'Unit ' . $u . ' ' . $label . ': ' . ($old ? ($d['reps'][$old]['name'] ?? $old) : '(none)') . ' → ' . $rep['name'] . ' · ' . (int) ($counts[$u] ?? 0) . ' members';
            }
        }
    }
    // Units this division used to assign that the new list drops.
    foreach ($d['units'] as $u => $a) {
        if (($a['division'] ?? '') === $div && !isset($seen[$u])) {
            unset($new['units'][$u]);
            $report['changes'][] = 'Unit ' . $u . ': ' . ($d['reps'][$a['rep']]['name'] ?? $a['rep']) . ' → (no rep; messages go to the Division Director) · ' . (int) ($counts[$u] ?? 0) . ' members';
        }
    }
    // Units in this division with members but no rep after the change.
    foreach ($counts as $u => $n) {
        if (isset($new['units'][$u])) continue;
        $udiv = ur_rep_division_for_unit($u);
        if ($udiv === $div && !preg_match('/^\d(48|49|98|99)$/', $u)) $report['notes'][] = 'Unit ' . $u . ' has ' . $n . ' member(s) but no rep in this list (messages go to the Division Director).';
    }
    $new['divisions'][$div] = ['effective' => $parsed['effective'], 'source' => 'docx', 'updated' => current_time('mysql')];
    return [$new, $report];
}

function ur_rep_plan_from_staff() {
    $people = ur_rep_parse_staff_page(ur_rep_staff_page_id());
    if (is_wp_error($people)) return $people;
    $d = ur_rep_data();
    $new = $d;
    $report = ['changes' => [], 'errors' => [], 'notes' => []];
    foreach ($people as $slug => $p) {
        $old = $d['reps'][$slug] ?? null;
        if (!$old) { $new['reps'][$slug] = $p; $report['changes'][] = 'Add ' . $p['name'] . ' (' . $p['title'] . ', ' . $p['division'] . ')'; continue; }
        $diff = [];
        foreach (['title', 'email', 'phone', 'photo', 'division', 'role'] as $k) {
            if ((string) ($old[$k] ?? '') !== (string) $p[$k] && $p[$k] !== '' && $p[$k] !== 0) { $diff[] = $k . ': ' . ($old[$k] ?? '') . ' → ' . $p[$k]; $new['reps'][$slug][$k] = $p[$k]; }
        }
        if ($diff) $report['changes'][] = 'Update ' . $p['name'] . ' (' . implode('; ', $diff) . ')';
    }
    foreach ($d['reps'] as $slug => $r) {
        if (!isset($people[$slug])) $report['notes'][] = $r['name'] . ' is not on the Staff page (kept as is).';
    }
    return [$new, $report];
}

// ─────────────────────────────────────────────────────────────────────────────
// Admin: Union Roll → Union Reps
// ─────────────────────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_submenu_page('union_roll', 'Union Reps', 'Union Reps', 'manage_options', 'ur-union-reps', 'ur_rep_render_admin');
}, 20);

function ur_rep_pending_key() { return 'ur_rep_pending_' . get_current_user_id(); }

function ur_rep_handle_post() {
    if (!current_user_can('manage_options') || empty($_POST['ur_rep_action'])) return '';
    check_admin_referer('ur_rep_admin');
    $act = sanitize_key($_POST['ur_rep_action']);

    if ($act === 'upload') {
        if (empty($_FILES['ur_rep_docx']['tmp_name']) || !is_uploaded_file($_FILES['ur_rep_docx']['tmp_name'])) return 'Choose a .docx file first.';
        if (!preg_match('/\.docx$/i', (string) $_FILES['ur_rep_docx']['name'])) return 'Please upload the .docx version of the assignments.';
        $parsed = ur_rep_parse_docx($_FILES['ur_rep_docx']['tmp_name']);
        if (is_wp_error($parsed)) return $parsed->get_error_message();
        if ($parsed['division'] === '') return 'Could not find the division name (e.g. "OAHU DIVISION") at the top of the document.';
        [$new, $report] = ur_rep_plan_from_docx($parsed);
        set_transient(ur_rep_pending_key(), ['new' => $new, 'report' => $report, 'kind' => 'docx', 'file' => sanitize_file_name($_FILES['ur_rep_docx']['name'])], HOUR_IN_SECONDS);
        return '';
    }
    if ($act === 'staff') {
        $plan = ur_rep_plan_from_staff();
        if (is_wp_error($plan)) return $plan->get_error_message();
        [$new, $report] = $plan;
        set_transient(ur_rep_pending_key(), ['new' => $new, 'report' => $report, 'kind' => 'staff', 'file' => 'Staff page'], HOUR_IN_SECONDS);
        return '';
    }
    if ($act === 'apply') {
        $p = get_transient(ur_rep_pending_key());
        if (!$p) return 'Nothing to apply (the preview expired). Upload again.';
        if (!empty($p['report']['errors'])) return 'Fix the errors listed in the preview first.';
        ur_rep_save_data($p['new']);
        delete_transient(ur_rep_pending_key());
        return 'Applied. The previous version is saved; use Undo to restore it.';
    }
    if ($act === 'cancel') { delete_transient(ur_rep_pending_key()); return 'Preview discarded.'; }
    if ($act === 'undo') {
        $prev = get_option('ur_rep_data_prev');
        if (!is_array($prev)) return 'There is no previous version to restore.';
        $cur = get_option('ur_rep_data', ur_rep_defaults());
        update_option('ur_rep_data', $prev, false);
        update_option('ur_rep_data_prev', $cur, false);
        return 'Restored the previous version. (Undo again to switch back.)';
    }
    if ($act === 'reps') {
        $d = ur_rep_data();
        foreach ((array) ($_POST['rep'] ?? []) as $slug => $r) {
            $slug = sanitize_title($slug);
            if (!empty($r['delete'])) {
                foreach ($d['units'] as $u => $a) if ($a['rep'] === $slug) return 'Cannot delete ' . esc_html($d['reps'][$slug]['name'] ?? $slug) . ': units are still assigned to them.';
                unset($d['reps'][$slug]);
                continue;
            }
            $name = sanitize_text_field(wp_unslash($r['name'] ?? ''));
            if ($name === '') continue;
            if ($slug === 'new') $slug = sanitize_title($name);
            $d['reps'][$slug] = [
                'name' => $name,
                'title' => sanitize_text_field(wp_unslash($r['title'] ?? '')),
                'email' => sanitize_email(wp_unslash($r['email'] ?? '')),
                'phone' => sanitize_text_field(wp_unslash($r['phone'] ?? '')),
                'photo' => absint($r['photo'] ?? 0),
                'division' => in_array($r['division'] ?? '', UR_REP_DIVISIONS, true) ? $r['division'] : '',
                'role' => in_array($r['role'] ?? '', ['rep', 'director', 'other'], true) ? $r['role'] : 'rep',
            ];
        }
        ur_rep_save_data($d);
        return 'Reps saved.';
    }
    if ($act === 'settings') {
        update_option('ur_rep_settings', [
            'form_name' => sanitize_text_field(wp_unslash($_POST['form_name'] ?? '')) ?: 'Contact my Union Rep',
            'test_mode' => empty($_POST['test_mode']) ? 0 : 1,
            'test_recipient' => sanitize_email(wp_unslash($_POST['test_recipient'] ?? '')),
            'fallback_email' => sanitize_email(wp_unslash($_POST['fallback_email'] ?? '')),
            'show_temporary' => empty($_POST['show_temporary']) ? 0 : 1,
        ], false);
        return 'Settings saved.';
    }
    return '';
}

function ur_rep_render_admin() {
    if (!current_user_can('manage_options')) return;
    $notice = ur_rep_handle_post();
    $d = ur_rep_data();
    $s = ur_rep_settings();
    $pending = get_transient(ur_rep_pending_key());
    $counts = ur_rep_unit_member_counts();
    $nonce = wp_nonce_field('ur_rep_admin', '_wpnonce', true, false);
    echo '<div class="wrap"><h1>Union Reps</h1>';
    if ($notice) echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
    if (!empty($s['test_mode'])) echo '<div class="notice notice-warning"><p><strong>Test mode is on.</strong> Every message from the Contact my Union Rep form goes only to ' . esc_html($s['test_recipient'] ?: get_option('admin_email')) . ', with the real recipients shown in the subject.</p></div>';

    // Preview of a pending change.
    if ($pending) {
        $r = $pending['report'];
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 18px;margin:16px 0"><h2 style="margin-top:0">Review before applying: ' . esc_html($pending['file']) . '</h2>';
        if (!empty($r['division'])) echo '<p><strong>' . esc_html($r['division']) . ' Division</strong>' . ($r['effective'] ? ', effective ' . esc_html($r['effective']) : '') . '</p>';
        if ($r['errors']) { echo '<h3 style="color:#b32d2e">Must fix</h3><ul>'; foreach ($r['errors'] as $x) echo '<li>' . esc_html($x) . '</li>'; echo '</ul>'; }
        echo '<h3>Changes (' . count($r['changes']) . ')</h3>';
        echo $r['changes'] ? '<ul style="max-height:320px;overflow:auto">' . implode('', array_map(function ($x) { return '<li>' . esc_html($x) . '</li>'; }, $r['changes'])) . '</ul>' : '<p>No changes.</p>';
        if ($r['notes']) { echo '<h3>Notes</h3><ul>'; foreach ($r['notes'] as $x) echo '<li>' . esc_html($x) . '</li>'; echo '</ul>'; }
        echo '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="ur_rep_action" value="apply"><button class="button button-primary"' . ($r['errors'] ? ' disabled' : '') . '>Apply</button></form> ';
        echo '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="ur_rep_action" value="cancel"><button class="button">Discard</button></form></div>';
    }

    // Upload + staff import + undo.
    echo '<h2>Update assignments</h2><p>Upload a division\'s <em>Unit Assignments</em> Word document (.docx) as it comes in. You\'ll see every change before anything is saved.</p>';
    echo '<form method="post" enctype="multipart/form-data">' . $nonce . '<input type="hidden" name="ur_rep_action" value="upload"><input type="file" name="ur_rep_docx" accept=".docx"> <button class="button button-primary">Upload and preview</button></form>';
    echo '<p style="margin-top:12px"><form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="ur_rep_action" value="staff"><button class="button">Import names, titles, phones, emails and photos from the Staff page</button></form> ';
    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Restore the previous version of reps and assignments?\')">' . $nonce . '<input type="hidden" name="ur_rep_action" value="undo"><button class="button"' . (get_option('ur_rep_data_prev') ? '' : ' disabled') . '>Undo last change</button></form></p>';

    // Current assignments by division.
    echo '<h2>Current assignments</h2>';
    foreach (UR_REP_DIVISIONS as $div) {
        $dir = ur_rep_director($div);
        $meta = $d['divisions'][$div] ?? [];
        $by = [];
        foreach ($d['units'] as $u => $a) if (($a['division'] ?? '') === $div) $by[$a['rep']][] = $u . (isset($counts[$u]) ? ' (' . $counts[$u] . ')' : '');
        echo '<h3>' . esc_html($div) . ' Division' . (!empty($meta['effective']) ? ' <small style="font-weight:normal">effective ' . esc_html($meta['effective']) . '</small>' : '') . '</h3>';
        echo '<p>Division Director (BCC on every message): ' . ($dir ? esc_html($dir['name'] . ' <' . $dir['email'] . '>') : '<strong style="color:#b32d2e">not set</strong>') . '</p>';
        if (!$by) { echo '<p><em>No assignments uploaded yet.</em></p>'; continue; }
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Rep</th><th>Units (members)</th></tr></thead><tbody>';
        foreach ($by as $slug => $units) {
            sort($units);
            echo '<tr><td>' . esc_html($d['reps'][$slug]['name'] ?? $slug) . '</td><td>' . esc_html(implode(', ', $units)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // Reps list (editable).
    echo '<h2>Reps and Division Directors</h2><form method="post">' . $nonce . '<input type="hidden" name="ur_rep_action" value="reps">';
    echo '<table class="widefat striped"><thead><tr><th>Photo</th><th>Name</th><th>Title</th><th>Email</th><th>Phone</th><th>Photo ID</th><th>Division</th><th>Role</th><th>Delete</th></tr></thead><tbody>';
    $rows = $d['reps'];
    uasort($rows, function ($a, $b) { return strcmp(($a['division'] ?? '') . $a['name'], ($b['division'] ?? '') . $b['name']); });
    $rows['new'] = ['name' => '', 'title' => 'Union Representative', 'email' => '', 'phone' => '', 'photo' => 0, 'division' => '', 'role' => 'rep'];
    foreach ($rows as $slug => $r) {
        $f = function ($k, $w = 160) use ($slug, $r) { return '<input name="rep[' . esc_attr($slug) . '][' . $k . ']" value="' . esc_attr($r[$k] ?? '') . '" style="width:' . $w . 'px">'; };
        $sel = function ($k, $opts) use ($slug, $r) { $h = '<select name="rep[' . esc_attr($slug) . '][' . $k . ']">'; foreach ($opts as $v => $l) $h .= '<option value="' . esc_attr($v) . '"' . selected($r[$k] ?? '', $v, false) . '>' . esc_html($l) . '</option>'; return $h . '</select>'; };
        echo '<tr><td>' . (!empty($r['photo']) ? wp_get_attachment_image((int) $r['photo'], [48, 48]) : '') . '</td><td>' . $f('name') . ($slug === 'new' ? '<br><small>Add a rep</small>' : '') . '</td><td>' . $f('title') . '</td><td>' . $f('email', 200) . '</td><td>' . $f('phone', 170) . '</td><td>' . $f('photo', 70) . '</td><td>'
            . $sel('division', ['' => '—'] + array_combine(UR_REP_DIVISIONS, UR_REP_DIVISIONS)) . '</td><td>' . $sel('role', ['rep' => 'Rep', 'director' => 'Division Director', 'other' => 'Other']) . '</td><td>'
            . ($slug === 'new' ? '' : '<input type="checkbox" name="rep[' . esc_attr($slug) . '][delete]" value="1">') . '</td></tr>';
    }
    echo '</tbody></table><p><small>Photo ID = the Media Library ID of the photo (shown in the image\'s URL as <code>post=1234</code>).</small></p><p><button class="button button-primary">Save reps</button></p></form>';

    // Settings.
    echo '<h2>Contact form settings</h2><form method="post">' . $nonce . '<input type="hidden" name="ur_rep_action" value="settings"><table class="form-table">';
    echo '<tr><th>Elementor form name</th><td><input name="form_name" value="' . esc_attr($s['form_name']) . '" class="regular-text"><p class="description">The Form Name set on the Elementor form. Only that form is routed to reps.</p></td></tr>';
    echo '<tr><th>Test mode</th><td><label><input type="checkbox" name="test_mode" value="1"' . checked($s['test_mode'], 1, false) . '> Send every message only to the test address</label></td></tr>';
    echo '<tr><th>Test address</th><td><input name="test_recipient" value="' . esc_attr($s['test_recipient']) . '" class="regular-text"></td></tr>';
    echo '<tr><th>Fallback address</th><td><input name="fallback_email" value="' . esc_attr($s['fallback_email']) . '" class="regular-text"><p class="description">Used only when a member has no rep and no Division Director (e.g. no unit on file).</p></td></tr>';
    echo '<tr><th>Show "temporary"</th><td><label><input type="checkbox" name="show_temporary" value="1"' . checked($s['show_temporary'], 1, false) . '> Show "(temporary)" next to temporary reps on the member card</label></td></tr>';
    echo '</table><p><button class="button button-primary">Save settings</button></p></form></div>';
}
