<?php
/**
 * UnionRoll — Member Groups.
 *
 * Restrict posts, pages, events and media files (incl. WP-Members Download
 * Protect files) to groups of members defined by rules on their profile data
 * (bargaining unit, unit number, employer/jurisdiction, job title).
 *
 * - Content with no group ticked stays visible to everyone (no change).
 * - Content with one or more groups is visible to members of ANY ticked group.
 * - Staff (editors/admins, or verified accounts on a staff email domain) see everything.
 * - Members whose member_status is on the blocked list belong to no group.
 *
 * Nothing is restricted until a group is ticked on some content (fast path).
 *
 * @since 3.21.0
 */

defined('ABSPATH') || exit;

const UR_MG_TAX = 'ur_member_group';

// ─────────────────────────────────────────────────────────────────────────────
// Settings
// ─────────────────────────────────────────────────────────────────────────────

function ur_mg_settings() {
    $defaults = [
        'staff_domains'    => 'upwhawaii.org',
        'blocked_statuses' => "Non Member\nTerminated\nInactive\nDeceased\nOpt-Out\nOut-Out",
        'denied_message'   => 'This page is only available to members of a specific bargaining unit or employer group. If you think you should have access, please contact UPW.',
    ];
    $saved = get_option('ur_mg_settings', []);
    return array_merge($defaults, is_array($saved) ? $saved : []);
}

function ur_mg_lines($text) {
    $parts = preg_split('/[\r\n,]+/', (string) $text);
    return array_values(array_filter(array_map('trim', $parts), 'strlen'));
}

function ur_mg_post_types() {
    return apply_filters('ur_mg_post_types', ['post', 'page', 'attachment']);
}

// ─────────────────────────────────────────────────────────────────────────────
// Taxonomy
// ─────────────────────────────────────────────────────────────────────────────

add_action('init', function () {
    register_taxonomy(UR_MG_TAX, ur_mg_post_types(), [
        'labels' => [
            'name'          => 'Member Groups',
            'singular_name' => 'Member Group',
            'menu_name'     => 'Member Groups',
            'all_items'     => 'All Member Groups',
            'edit_item'     => 'Edit Member Group',
            'add_new_item'  => 'Add New Member Group',
            'search_items'  => 'Search Member Groups',
            'parent_item'   => 'Parent Group',
        ],
        'description'           => 'Only members in a ticked group can see this. Leave all unticked to show it to everyone.',
        'public'                => false,
        'publicly_queryable'    => false,
        'show_ui'               => true,
        'show_in_menu'          => true,
        'show_in_nav_menus'     => false,
        'show_in_rest'          => false,
        'show_tagcloud'         => false,
        'show_in_quick_edit'    => true,
        'show_admin_column'     => true,
        'hierarchical'          => true,
        'query_var'             => false,
        'rewrite'               => false,
        'update_count_callback' => '_update_generic_term_count',
        'capabilities'          => [
            'manage_terms' => 'manage_options',
            'edit_terms'   => 'manage_options',
            'delete_terms' => 'manage_options',
            'assign_terms' => 'edit_posts',
        ],
    ]);
}, 0); // priority 0: Download Protect checks downloads on init priority 10

// ─────────────────────────────────────────────────────────────────────────────
// Group rules
// ─────────────────────────────────────────────────────────────────────────────

const UR_MG_RULE_KEYS = [
    'ur_mg_bu'         => 'Bargaining units',
    'ur_mg_islands'    => 'Islands',
    'ur_mg_units'      => 'Unit numbers',
    'ur_mg_employers'  => 'Employers / jurisdictions',
    'ur_mg_job_titles' => 'Job title contains',
];

function ur_mg_norm_bu($v) {
    $v = trim((string) $v);
    if ($v === '') return '';
    $n = ltrim($v, '0');
    return $n === '' ? '0' : strtolower($n);
}

/** Parse "501-554, 149 249" into [[501,554],[149,149],[249,249]]. */
function ur_mg_parse_units($spec) {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) $spec) as $tok) {
        if ($tok === '') continue;
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $tok, $m)) {
            $out[] = [min((int) $m[1], (int) $m[2]), max((int) $m[1], (int) $m[2])];
        } elseif (ctype_digit($tok)) {
            $out[] = [(int) $tok, (int) $tok];
        }
    }
    return $out;
}

/**
 * All groups with their parsed rules. Cached per request.
 * Reads the term tables directly so it works even before the taxonomy is registered.
 */
function ur_mg_groups($refresh = false) {
    static $groups = null;
    if ($groups !== null && !$refresh) return $groups;
    global $wpdb;
    $groups = [];
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.term_id, t.name, t.slug FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         WHERE tt.taxonomy = %s ORDER BY t.name",
        UR_MG_TAX
    ));
    foreach ((array) $rows as $t) {
        $tid = (int) $t->term_id;
        $groups[$tid] = [
            'term'      => $t,
            'match'     => get_term_meta($tid, 'ur_mg_match', true) === 'all' ? 'all' : 'any',
            'bu'        => array_map('ur_mg_norm_bu', ur_mg_lines(get_term_meta($tid, 'ur_mg_bu', true))),
            'units'     => ur_mg_parse_units(get_term_meta($tid, 'ur_mg_units', true)),
            'islands'   => array_map('strtolower', ur_mg_lines(get_term_meta($tid, 'ur_mg_islands', true))),
            'employers' => array_map('strtolower', ur_mg_lines(get_term_meta($tid, 'ur_mg_employers', true))),
            'titles'    => array_map('strtolower', preg_split('/[\r\n]+/', trim((string) get_term_meta($tid, 'ur_mg_job_titles', true)), -1, PREG_SPLIT_NO_EMPTY)),
        ];
    }
    return $groups;
}

/**
 * Does a member profile (array of meta values) match a group's rules?
 * Match "any" (default): at least one filled-in rule matches (rules add up).
 * Match "all": every filled-in rule must match (rules narrow the group).
 * A group with no rules filled in matches nobody.
 */
function ur_mg_profile_matches(array $p, array $g) {
    $results = [];

    if ($g['bu']) {
        $bu = ur_mg_norm_bu($p['bargaining_unit'] ?? '');
        $results[] = $bu !== '' && in_array($bu, $g['bu'], true);
    }

    if ($g['units']) {
        $hit  = false;
        $unit = trim((string) ($p['unit_number'] ?? ''));
        if ($unit !== '' && ctype_digit($unit)) {
            $u = (int) $unit;
            foreach ($g['units'] as $r) {
                if ($u >= $r[0] && $u <= $r[1]) { $hit = true; break; }
            }
        }
        $results[] = $hit;
    }

    if (!empty($g['islands'])) {
        $isl = strtolower(trim((string) ($p['island'] ?? '')));
        $results[] = $isl !== '' && in_array($isl, $g['islands'], true);
    }

    if ($g['employers']) {
        $hit = false;
        foreach (['employer', 'jurisdiction'] as $k) {
            $v = strtolower(trim((string) ($p[$k] ?? '')));
            if ($v !== '' && in_array($v, $g['employers'], true)) { $hit = true; break; }
        }
        $results[] = $hit;
    }

    if ($g['titles']) {
        $hit = false;
        $jt  = strtolower((string) ($p['job_title'] ?? ''));
        if ($jt !== '') {
            foreach ($g['titles'] as $needle) {
                $needle = trim($needle);
                if ($needle !== '' && strpos($jt, $needle) !== false) { $hit = true; break; }
            }
        }
        $results[] = $hit;
    }

    if (!$results) return false;
    return (($g['match'] ?? 'any') === 'all') ? !in_array(false, $results, true) : in_array(true, $results, true);
}

function ur_mg_status_blocked($status) {
    $status = strtolower(trim((string) $status));
    if ($status === '') return false;
    $blocked = array_map('strtolower', ur_mg_lines(ur_mg_settings()['blocked_statuses']));
    return in_array($status, $blocked, true);
}

/** Is this user on a staff email domain AND verified by an admin for that exact address? */
function ur_mg_is_verified_staff_email($user) {
    if (!$user || empty($user->user_email)) return false;
    $email   = strtolower(trim($user->user_email));
    $domains = array_map('strtolower', ur_mg_lines(ur_mg_settings()['staff_domains']));
    $at      = strrpos($email, '@');
    if ($at === false || !in_array(substr($email, $at + 1), $domains, true)) return false;
    $verified = strtolower(trim((string) get_user_meta($user->ID, 'ur_mg_staff_verified_email', true)));
    return $verified !== '' && hash_equals($verified, $email);
}

/**
 * Who is this user, for access purposes? Cached per user ID per request
 * (so switching users mid-request gives the right answer).
 *
 * @return array{staff:bool,groups:int[],blocked:bool}
 */
function ur_mg_user_context($user_id = null) {
    static $cache = [];
    $user_id = ($user_id === null) ? get_current_user_id() : (int) $user_id;
    if (isset($cache[$user_id])) return $cache[$user_id];

    $ctx = ['staff' => false, 'groups' => [], 'blocked' => false];
    if ($user_id <= 0) return $cache[$user_id] = $ctx;

    // Admin "view as" preview (front end only): the admin is treated as the chosen member or group.
    if ($user_id === get_current_user_id() && ($va = ur_mg_view_as())) {
        if ($va['mode'] === 'user' && $va['id'] !== $user_id) return $cache[$user_id] = ur_mg_user_context($va['id']);
        if ($va['mode'] === 'group') return $cache[$user_id] = ['staff' => false, 'groups' => [$va['id']], 'blocked' => false];
    }

    $user = get_userdata($user_id);
    if (!$user) return $cache[$user_id] = $ctx;

    if (user_can($user, 'edit_others_posts') || ur_mg_is_verified_staff_email($user)) {
        $ctx['staff'] = true;
        return $cache[$user_id] = apply_filters('ur_mg_user_context', $ctx, $user_id);
    }

    $profile = [];
    foreach (['bargaining_unit', 'unit_number', 'island', 'employer', 'jurisdiction', 'job_title', 'member_status'] as $k) {
        $profile[$k] = get_user_meta($user_id, $k, true);
    }
    if (ur_mg_status_blocked($profile['member_status'])) {
        $ctx['blocked'] = true;
        return $cache[$user_id] = apply_filters('ur_mg_user_context', $ctx, $user_id);
    }
    foreach (ur_mg_groups() as $tid => $g) {
        if (ur_mg_profile_matches($profile, $g)) $ctx['groups'][] = $tid;
    }
    return $cache[$user_id] = apply_filters('ur_mg_user_context', $ctx, $user_id);
}

// ─────────────────────────────────────────────────────────────────────────────
// Access checks
// ─────────────────────────────────────────────────────────────────────────────

/** True once any content has at least one group ticked. One cheap query per request. */
function ur_mg_has_restrictions() {
    static $has = null;
    if ($has !== null) return $has;
    global $wpdb;
    $has = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         WHERE tt.taxonomy = %s LIMIT 1",
        UR_MG_TAX
    ));
    return $has;
}

function ur_mg_post_groups($post_id) {
    if (taxonomy_exists(UR_MG_TAX)) {
        $terms = get_the_terms((int) $post_id, UR_MG_TAX);
        if (is_array($terms)) return array_map(function ($t) { return (int) $t->term_id; }, $terms);
        if ($terms === false) return [];
    }
    // Taxonomy not registered yet, or an error: read the tables directly (never fail open).
    global $wpdb;
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         WHERE tt.taxonomy = %s AND tr.object_id = %d",
        UR_MG_TAX, (int) $post_id
    )));
}

function ur_mg_user_can_view($post_id, $user_id = null) {
    if (!$post_id || !ur_mg_has_restrictions()) return true;
    if (!in_array(get_post_type($post_id), ur_mg_post_types(), true)) return true;
    $groups = ur_mg_post_groups($post_id);
    if (!$groups) return true;
    $ctx = ur_mg_user_context($user_id);
    if ($ctx['staff']) return true;
    return (bool) array_intersect($groups, $ctx['groups']);
}

/** IDs of all content this user may NOT see (restricted to groups they're not in). */
function ur_mg_denied_ids($user_id = null) {
    static $cache = [];
    $user_id = ($user_id === null) ? get_current_user_id() : (int) $user_id;
    if (isset($cache[$user_id])) return $cache[$user_id];
    if (!ur_mg_has_restrictions() || ur_mg_user_context($user_id)['staff']) return $cache[$user_id] = [];

    $all = array_keys(ur_mg_groups());
    if (!$all) return $cache[$user_id] = [];
    $restricted = ur_mg_objects_in_groups($all);
    $mine       = ur_mg_user_context($user_id)['groups'];
    $allowed    = $mine ? ur_mg_objects_in_groups($mine) : [];
    return $cache[$user_id] = array_values(array_diff($restricted, $allowed));
}

/** Object IDs tagged with any of these group term IDs. */
function ur_mg_objects_in_groups(array $term_ids) {
    $term_ids = array_filter(array_map('intval', $term_ids));
    if (!$term_ids) return [];
    global $wpdb;
    $in = implode(',', $term_ids);
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         WHERE tt.taxonomy = %s AND tt.term_id IN ($in)",
        UR_MG_TAX
    )));
}

/** Every object with at least one group (used for sitemaps). */
function ur_mg_all_restricted_ids() {
    return ur_mg_objects_in_groups(array_keys(ur_mg_groups()));
}

// ─────────────────────────────────────────────────────────────────────────────
// Enforcement: queries (lists, archives, search, feeds, REST collections, Elementor loops)
// ─────────────────────────────────────────────────────────────────────────────

function ur_mg_query_targets_our_types(WP_Query $q) {
    $pt = $q->get('post_type');
    if (empty($pt)) {
        // Default post type for most queries is 'post'; attachment queries set it explicitly.
        return true;
    }
    if ($pt === 'any') return true;
    $ours = ur_mg_post_types();
    return (bool) array_intersect((array) $pt, $ours);
}

add_action('pre_get_posts', function (WP_Query $q) {
    if ($q->get('ur_mg_skip')) return;
    if (!ur_mg_has_restrictions()) return;
    $ctx = ur_mg_user_context();
    if ($ctx['staff']) return;
    // Single post/page views are handled in template_redirect with a clear message instead of a 404.
    if ($q->is_main_query() && $q->is_singular()) return;
    if (!ur_mg_query_targets_our_types($q)) return;

    $clause = [
        'relation' => 'OR',
        ['taxonomy' => UR_MG_TAX, 'operator' => 'NOT EXISTS'],
    ];
    if ($ctx['groups']) {
        $clause[] = [
            'taxonomy'         => UR_MG_TAX,
            'field'            => 'term_id',
            'terms'            => $ctx['groups'],
            'operator'         => 'IN',
            'include_children' => false,
        ];
    }
    $existing = $q->get('tax_query');
    $q->set('tax_query', $existing ? ['relation' => 'AND', $existing, $clause] : [$clause]);
}, 999);

// ─────────────────────────────────────────────────────────────────────────────
// Enforcement: single views
// ─────────────────────────────────────────────────────────────────────────────

add_action('template_redirect', function () {
    if (!is_singular() || !ur_mg_has_restrictions()) return;
    $post = get_queried_object();
    if (!$post || empty($post->ID) || ur_mg_user_can_view($post->ID)) return;

    if (!is_user_logged_in()) {
        $back  = get_permalink($post);
        $login = function_exists('wpmem_login_url') && wpmem_login_url() ? wpmem_login_url() : wp_login_url();
        wp_safe_redirect(add_query_arg('redirect_to', rawurlencode($back), $login));
        exit;
    }
    // Redirect to a neutral URL so the restricted item's title/SEO tags never reach the page <head>.
    wp_safe_redirect(add_query_arg('ur_mg_denied', '1', home_url('/')));
    exit;
}, 5);

// Friendly "members of this group only" page, inside the site's header and footer.
add_action('template_redirect', function () {
    if (empty($_GET['ur_mg_denied'])) return;
    if (!is_user_logged_in()) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
    status_header(403);
    nocache_headers();
    add_filter('pre_get_document_title', function () { return 'Members only | ' . get_bloginfo('name'); }, 99);
    add_filter('wpseo_robots', function () { return 'noindex, nofollow'; }, 99);
    get_header();
    $msg = ur_mg_settings()['denied_message'];
    echo '<main id="content" class="site-main ur-mg-denied" style="max-width:720px;margin:64px auto;padding:0 20px;text-align:center">'
        . '<h1 style="margin-bottom:16px">Members of this group only</h1>'
        . '<p style="font-size:1.1em;line-height:1.6">' . esc_html($msg) . '</p>'
        . '<p style="margin-top:28px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap">'
        . '<a class="elementor-button" style="padding:12px 24px" href="' . esc_url(ur_mg_for_you_url() . '#for-you') . '">See what\'s for you</a>'
        . '<a class="elementor-button" style="padding:12px 24px;background:transparent;border:2px solid currentColor;color:inherit" href="' . esc_url(home_url('/')) . '">Back to home</a>'
        . '</p></main>';
    get_footer();
    exit;
}, 4);

// REST single items (collections are covered by pre_get_posts).
add_filter('rest_request_before_callbacks', function ($response, $handler, $request) {
    if (is_wp_error($response) || !ur_mg_has_restrictions()) return $response;
    $id = (int) $request->get_param('id');
    if (!$id) return $response;
    $cb = $handler['callback'] ?? null;
    $is_posts_controller = is_array($cb) && ($cb[0] instanceof WP_REST_Posts_Controller);
    if (!$is_posts_controller) return $response;
    $pt = get_post_type($id);
    if ($pt && in_array($pt, ur_mg_post_types(), true) && !ur_mg_user_can_view($id)) {
        return new WP_Error('rest_forbidden', 'Not available.', ['status' => is_user_logged_in() ? 403 : 401]);
    }
    return $response;
}, 10, 3);

// Previous / next post links.
foreach (['get_previous_post_where', 'get_next_post_where'] as $ur_mg_hook) {
    add_filter($ur_mg_hook, function ($where) {
        $deny = ur_mg_denied_ids();
        if ($deny) $where .= ' AND p.ID NOT IN (' . implode(',', array_map('intval', $deny)) . ')';
        return $where;
    });
}

// Menus: drop items that point at content this user can't see.
add_filter('wp_get_nav_menu_objects', function ($items) {
    if (is_admin() || !ur_mg_has_restrictions() || ur_mg_user_context()['staff']) return $items;
    $deny = array_flip(ur_mg_denied_ids());
    if (!$deny) return $items;
    return array_values(array_filter($items, function ($i) use ($deny) {
        return !($i->type === 'post_type' && isset($deny[(int) $i->object_id]));
    }));
});

// Sitemaps: never list group-only content.
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    return ur_mg_has_restrictions() ? array_values(array_unique(array_merge((array) $ids, ur_mg_all_restricted_ids()))) : $ids;
});

// ─────────────────────────────────────────────────────────────────────────────
// Enforcement: WP-Members Download Protect (contract PDFs etc.)
// ─────────────────────────────────────────────────────────────────────────────

function ur_mg_dp_post_id_from_key($key) {
    $key = (string) $key;
    if ($key === '') return 0;
    if (function_exists('wpmem_dp_get_file_post')) {
        $p = wpmem_dp_get_file_post($key);
        if ($p && !empty($p->ID)) return (int) $p->ID;
    }
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpmem_dp_key' AND meta_value = %s LIMIT 1", $key));
}

// The actual download request. Runs after WP-Members' own login check.
add_action('wpmem_dp_verify', function ($args) {
    $pid = (int) ($args['file_info']['post_id'] ?? 0);
    if (!$pid || ur_mg_user_can_view($pid)) return;
    if (!is_user_logged_in()) {
        // Files marked "Unrestricted" in Download Protect skip its login check; a group tag still requires login.
        if (function_exists('wpmem_dp_do_error')) wpmem_dp_do_error('login', (string) ($args['key'] ?? ''));
        wp_safe_redirect(wp_login_url());
        exit;
    }
    // Runs on init (before the theme is ready): send the member to the friendly page.
    wp_safe_redirect(add_query_arg('ur_mg_denied', '1', home_url('/')));
    exit;
}, 20);

// Video/audio players and anything else that asks.
add_filter('wpmem_dp_user_can_access', function ($access, $key, $user_id) {
    if (!$access) return $access;
    $pid = ur_mg_dp_post_id_from_key($key);
    return $pid ? ur_mg_user_can_view($pid, $user_id) : $access;
}, 20, 3);

// Hide [wpmem_file] links the member can't open.
add_filter('wpmem_dp_link', function ($content, $settings) {
    $id  = (string) ($settings['id'] ?? '');
    $key = strpos($id, 'dp_') === 0 ? substr($id, 3) : '';
    $pid = ur_mg_dp_post_id_from_key($key);
    return ($pid && !ur_mg_user_can_view($pid)) ? '' : $content;
}, 20, 2);

// Hide rows in [wpmem_file_list] / table output.
add_filter('wpmem_dp_file_list_row', function ($row) {
    $id  = (string) ($row['id'] ?? '');
    $key = strpos($id, 'dp_') === 0 ? substr($id, 3) : '';
    $pid = ur_mg_dp_post_id_from_key($key);
    if ($pid && !ur_mg_user_can_view($pid)) {
        foreach (['wrapper_before', 'wrapper_after', 'link_before', 'link_after', 'title', 'file_name', 'date', 'file_type'] as $k) {
            $row[$k] = '';
        }
    }
    return $row;
}, 20);

// ─────────────────────────────────────────────────────────────────────────────
// Page-section restriction: shortcode + Elementor CSS class
// ─────────────────────────────────────────────────────────────────────────────

/** Is the current user in any of these group slugs (or staff)? */
function ur_mg_user_in_slugs(array $slugs, $user_id = null) {
    $ctx = ur_mg_user_context($user_id);
    if ($ctx['staff']) return true;
    if (!$ctx['groups']) return false;
    foreach (ur_mg_groups() as $tid => $g) {
        if (in_array($g['term']->slug, $slugs, true) && in_array($tid, $ctx['groups'], true)) return true;
    }
    return false;
}

// [ur_group_only groups="bu-1,dsi-renal"]Only these members see this.[/ur_group_only]
add_shortcode('ur_group_only', function ($atts, $content = '') {
    $atts  = shortcode_atts(['groups' => ''], $atts, 'ur_group_only');
    $slugs = array_map('sanitize_title', ur_mg_lines($atts['groups']));
    if (!$slugs || !ur_mg_user_in_slugs($slugs)) return '';
    return do_shortcode($content);
});

// Elementor: add CSS class "ur-group-<slug>" to any section/container/column/widget
// to show it only to that group (several classes = any of them).
function ur_mg_elementor_should_render($should_render, $element) {
    if (!$should_render || !is_object($element) || !method_exists($element, 'get_settings')) return $should_render;
    if (\Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode()) return $should_render;
    $classes = trim((string) $element->get_settings('_css_classes') . ' ' . (string) $element->get_settings('css_classes'));
    if ($classes === '' || strpos($classes, 'ur-group-') === false) return $should_render;
    preg_match_all('/(?:^|\s)ur-group-([a-z0-9-]+)/', $classes, $m);
    return $m[1] ? ur_mg_user_in_slugs($m[1]) : $should_render;
}
add_action('elementor/init', function () {
    foreach (['widget', 'section', 'column', 'container'] as $type) {
        add_filter("elementor/frontend/{$type}/should_render", 'ur_mg_elementor_should_render', 10, 2);
    }
});

// Elementor element caching stores a page's static HTML once and serves it to every visitor.
// Anything whose output depends on the viewer's group must be rendered per request instead.
add_filter('elementor/element/is_dynamic_content', function ($is_dynamic, $raw_data) {
    if ($is_dynamic) return $is_dynamic;
    $s = (array) ($raw_data['settings'] ?? []);
    $classes = (string) ($s['_css_classes'] ?? '') . ' ' . (string) ($s['css_classes'] ?? '');
    if (strpos($classes, 'ur-group-') !== false) return true;
    $json = (string) wp_json_encode($s);
    foreach (['ur_group_only', 'wpmem_file', 'ur_for_you', 'ur_my_division', 'a=download'] as $needle) {
        if (strpos($json, $needle) !== false) return true;
    }
    return false;
}, 10, 2);

// ─────────────────────────────────────────────────────────────────────────────
// Hide links to contracts/files a logged-in member can't open (any Elementor widget or post).
// Logged-out visitors still see them; clicking leads to the login page.
// ─────────────────────────────────────────────────────────────────────────────

function ur_mg_key_denied($key) {
    static $cache = [];
    $ck = get_current_user_id() . ':' . $key;
    if (isset($cache[$ck])) return $cache[$ck];
    $pid = ur_mg_dp_post_id_from_key($key);
    return $cache[$ck] = ($pid && !ur_mg_user_can_view($pid));
}

function ur_mg_should_filter_links() {
    return is_user_logged_in() && ur_mg_has_restrictions() && !ur_mg_user_context()['staff'];
}

/** Remove list items, then unwrap links, that point at a download the member can't open. */
function ur_mg_strip_denied_links($html) {
    if (strpos($html, 'key=') === false || !ur_mg_should_filter_links()) return $html;
    $denied = function ($chunk) {
        if (!preg_match_all('/key=([a-f0-9]{32})/', $chunk, $m)) return false;
        foreach ($m[1] as $k) if (ur_mg_key_denied($k)) return true;
        return false;
    };
    $html = preg_replace_callback('#<li\b[^>]*>.*?</li>#s', function ($m) use ($denied) {
        return $denied($m[0]) ? '' : $m[0];
    }, $html);
    return preg_replace_callback('#<a\b[^>]*href=["\'][^"\']*key=[a-f0-9]{32}[^"\']*["\'][^>]*>(.*?)</a>#s', function ($m) use ($denied) {
        return $denied($m[0]) ? $m[1] : $m[0];
    }, $html);
}

add_filter('elementor/widget/render_content', function ($content) {
    return ur_mg_strip_denied_links((string) $content);
}, 20);
add_filter('the_content', 'ur_mg_strip_denied_links', 20);

// A widget whose main link is a download the member can't open (e.g. a contract card) is hidden entirely.
add_filter('elementor/frontend/widget/should_render', function ($should, $widget) {
    if (!$should || !ur_mg_should_filter_links() || !is_object($widget) || !method_exists($widget, 'get_settings')) return $should;
    $link = $widget->get_settings('link');
    $url  = is_array($link) ? (string) ($link['url'] ?? '') : '';
    if ($url !== '' && preg_match('/key=([a-f0-9]{32})/', $url, $m) && ur_mg_key_denied($m[1])) return false;
    return $should;
}, 20, 2);

// ─────────────────────────────────────────────────────────────────────────────
// [ur_for_you] — contracts, news and upcoming events tagged for the member's groups.
// Shows nothing to logged-out visitors, staff, or members with nothing tagged for them.
// Admins see it as a member while "View as" preview is on.
// ─────────────────────────────────────────────────────────────────────────────

function ur_mg_for_you_items($user_id, $limit = 5) {
    $ctx = ur_mg_user_context($user_id);
    if ($ctx['staff'] || !$ctx['groups']) return null;
    $ids = ur_mg_objects_in_groups($ctx['groups']);
    $out = ['contracts' => [], 'news' => [], 'events' => []];
    if (!$ids) return $out;
    $now = current_time('mysql');
    foreach ($ids as $id) {
        $p = get_post($id);
        if (!$p) continue;
        if ($p->post_type === 'attachment' && ($k = get_post_meta($id, '_wpmem_dp_key', true))) {
            $out['contracts'][] = ['title' => $p->post_title, 'url' => add_query_arg(['a' => 'download', 'key' => $k], home_url('/')), 'sort' => $p->post_title];
        } elseif ($p->post_type === 'post' && $p->post_status === 'publish') {
            $out['news'][] = ['title' => $p->post_title, 'url' => get_permalink($p), 'sort' => $p->post_date, 'meta' => get_the_date('', $p)];
        } elseif ($p->post_type === 'tribe_events' && $p->post_status === 'publish') {
            $start = (string) get_post_meta($id, '_EventStartDate', true);
            if ($start !== '' && $start >= $now) {
                $out['events'][] = ['title' => $p->post_title, 'url' => get_permalink($p), 'sort' => $start, 'meta' => mysql2date('D, M j, Y', $start)];
            }
        }
    }
    usort($out['contracts'], function ($a, $b) { return strcasecmp($a['sort'], $b['sort']); });
    usort($out['news'], function ($a, $b) { return strcmp($b['sort'], $a['sort']); });
    usort($out['events'], function ($a, $b) { return strcmp($a['sort'], $b['sort']); });
    foreach ($out as $k => $v) $out[$k] = array_slice($v, 0, $limit);
    return $out;
}

/** The page that shows the member's UnionRoll QR card ([mmm_user_qr]), or '' if none. Cached a day. */
function ur_mg_membership_card_url() {
    $id = get_transient('ur_mg_card_page');
    if ($id === false) {
        global $wpdb;
        $id = (int) $wpdb->get_var("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
            WHERE p.post_type = 'page' AND p.post_status = 'publish' AND (p.post_content LIKE '%[mmm_user_qr%' OR m.meta_value LIKE '%[mmm_user_qr%') ORDER BY p.ID LIMIT 1");
        set_transient('ur_mg_card_page', $id, DAY_IN_SECONDS);
    }
    return $id ? get_permalink((int) $id) : '';
}
add_action('save_post_page', function () { delete_transient('ur_mg_card_page'); });

/** The page that shows [ur_for_you] (setting, else the first published page containing it, else home). */
function ur_mg_for_you_url() {
    $set = trim((string) (ur_mg_settings()['for_you_url'] ?? ''));
    if ($set !== '') return $set;
    $id = get_transient('ur_mg_for_you_page');
    if ($id === false) {
        global $wpdb;
        $id = (int) $wpdb->get_var("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
            WHERE p.post_type = 'page' AND p.post_status = 'publish' AND (p.post_content LIKE '%[ur_for_you%' OR m.meta_value LIKE '%[ur_for_you%') ORDER BY p.ID LIMIT 1");
        set_transient('ur_mg_for_you_page', $id, DAY_IN_SECONDS);
    }
    return $id ? get_permalink((int) $id) : home_url('/');
}
add_action('save_post_page', function () { delete_transient('ur_mg_for_you_page'); });

add_shortcode('ur_for_you', function ($atts) {
    $atts = shortcode_atts(['limit' => 5, 'title' => 'For You'], $atts, 'ur_for_you');
    if (!is_user_logged_in()) return '';
    $uid = get_current_user_id();
    $items = ur_mg_for_you_items($uid, max(1, (int) $atts['limit']));
    $card  = ur_mg_membership_card_url();
    $has   = $items && array_filter($items);
    if (!$has && !$card) return '';
    $cols = ['contracts' => 'Your contracts', 'news' => 'News for your unit', 'events' => 'Your upcoming meetings'];
    // Matches the site's Elementor look: navy cards (primary #1C244B), white headings,
    // light text, white links with a gold (#D3C89D) hover, gold buttons with navy text.
    $html = '<style>.ur-for-you{max-width:1140px;margin:0 auto;padding:40px 20px}.ur-for-you h2{margin:0 0 6px;color:var(--e-global-color-primary,#1C244B)}.ur-for-you .ur-fy-sub{margin:0 0 22px;color:var(--e-global-color-text,#324A6D)}'
        . '.ur-fy-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px}'
        . '.ur-for-you .ur-fy-card{background:var(--e-global-color-primary,#1C244B);color:#F3F5F8;border-top:4px solid #D3C89D;border-radius:6px;padding:20px 22px}'
        . '.ur-for-you .ur-fy-card h3{font-size:1.1em;margin:0 0 10px;color:#fff}.ur-fy-card ul{list-style:none;margin:0;padding:0}.ur-fy-card li{padding:10px 0;border-top:1px solid rgba(255,255,255,.15)}'
        . '.ur-fy-card li:first-child{border-top:0}.ur-for-you .ur-fy-card small{display:block;margin-top:2px;color:#C8D5DC}'
        . '.ur-for-you .ur-fy-card a{color:#fff;font-weight:600;text-decoration:none}.ur-for-you .ur-fy-card a:hover,.ur-for-you .ur-fy-card a:focus{color:#D3C89D;text-decoration:underline}'
        . '.ur-for-you .ur-fy-member-card{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 20px;padding:18px 22px;border-radius:6px;background:#11274A;color:#F3F5F8;border-left:4px solid #D3C89D}'
        . '.ur-for-you .ur-fy-member-card strong{display:block;font-size:1.1em;color:#fff}'
        . '.ur-for-you .ur-fy-member-card a{background:#D3C89D;color:#1C244B;padding:10px 22px;border-radius:4px;text-decoration:none;font-weight:700;white-space:nowrap}'
        . '.ur-for-you .ur-fy-member-card a:hover,.ur-for-you .ur-fy-member-card a:focus{background:#fff;color:#1C244B}</style>';
    $html .= '<section id="for-you" class="ur-for-you"><h2>' . esc_html($atts['title']) . '</h2>'
        . ($has ? '<p class="ur-fy-sub">Contracts, news and meetings for your unit and division.</p>' : '');
    if ($card) {
        $html .= '<div class="ur-fy-member-card"><span><strong>Your membership card</strong>Show your QR code to check in at union meetings and events.</span>'
            . '<a href="' . esc_url($card) . '">Open my card</a></div>';
    }
    $html .= '<div class="ur-fy-grid">';
    foreach ($cols as $k => $label) {
        if (empty($items[$k])) continue;
        $html .= '<div class="ur-fy-card"><h3>' . esc_html($label) . '</h3><ul>';
        foreach ($items[$k] as $it) {
            $html .= '<li><a href="' . esc_url($it['url']) . '">' . esc_html($it['title']) . '</a>' . (!empty($it['meta']) ? '<small>' . esc_html($it['meta']) . '</small>' : '') . '</li>';
        }
        $html .= '</ul></div>';
    }
    return $html . '</div></section>';
});

// ─────────────────────────────────────────────────────────────────────────────
// [ur_my_division] — "All events / My division" toggle for event calendars.
// "My division" = the member's division category PLUS every event with no division
// category (statewide events like the Labor Day picnic always stay visible).
// ─────────────────────────────────────────────────────────────────────────────

const UR_MG_DIVISION_CATS = ['oahu-division', 'hawaii-division', 'kauai-division', 'maui-division', 'private-sector-division'];

function ur_mg_user_divisions($user_id = null) {
    $user_id = ($user_id === null) ? ur_mg_effective_user_id() : (int) $user_id;
    if (!$user_id) return [];
    $map = ['oahu' => 'oahu-division', 'hawaii' => 'hawaii-division', 'kauai' => 'kauai-division', 'maui' => 'maui-division', 'molokai' => 'maui-division', 'lanai' => 'maui-division'];
    $out = [];
    $isl = strtolower(trim((string) get_user_meta($user_id, 'island', true)));
    if (isset($map[$isl])) $out[] = $map[$isl];
    $unit = trim((string) get_user_meta($user_id, 'unit_number', true));
    if (ur_mg_norm_bu(get_user_meta($user_id, 'bargaining_unit', true)) === '0' || (ctype_digit($unit) && (int) $unit >= 501 && (int) $unit <= 554)) {
        $out[] = 'private-sector-division';
    }
    return array_values(array_unique(array_filter($out, function ($slug) { return (bool) get_term_by('slug', $slug, 'tribe_events_cat'); })));
}

/** Event IDs to hide for "My division": in another division's category and not in the member's own. */
function ur_mg_division_excluded_ids() {
    static $cache = [];
    $mine = ur_mg_user_divisions();
    $ck   = implode(',', $mine);
    if (isset($cache[$ck])) return $cache[$ck];
    if (!$mine) return $cache[$ck] = [];
    $ids = function ($slugs) {
        $tids = [];
        foreach ($slugs as $slug) {
            $t = get_term_by('slug', $slug, 'tribe_events_cat');
            if ($t) $tids[] = (int) $t->term_id;
        }
        return $tids ? array_map('intval', (array) get_objects_in_term($tids, 'tribe_events_cat')) : [];
    };
    $others = array_values(array_diff(UR_MG_DIVISION_CATS, $mine));
    return $cache[$ck] = array_values(array_diff($ids($others), $ids($mine)));
}

add_action('pre_get_posts', function (WP_Query $q) {
    if (($_GET['ur_division'] ?? '') !== 'mine' || (is_admin() && !wp_doing_ajax())) return;
    if (!in_array('tribe_events', (array) $q->get('post_type'), true)) return;
    $exclude = ur_mg_division_excluded_ids();
    if (!$exclude) return;
    $q->set('post__not_in', array_values(array_unique(array_merge((array) $q->get('post__not_in'), $exclude))));
}, 1000);

add_shortcode('ur_my_division', function () {
    if (!is_user_logged_in()) return '';
    $mine = ur_mg_user_divisions();
    if (!$mine) return '';
    $names = array_map(function ($slug) { $t = get_term_by('slug', $slug, 'tribe_events_cat'); return $t ? $t->name : $slug; }, $mine);
    $on    = ($_GET['ur_division'] ?? '') === 'mine';
    $base  = remove_query_arg('ur_division');
    $btn   = function ($label, $url, $active) {
        return '<a href="' . esc_url($url) . '" style="display:inline-block;padding:8px 16px;border-radius:20px;text-decoration:none;border:2px solid var(--e-global-color-primary,#1f3a68);'
            . ($active ? 'background:var(--e-global-color-primary,#1f3a68);color:#fff' : 'color:var(--e-global-color-primary,#1f3a68)') . '">' . esc_html($label) . '</a>';
    };
    return '<div class="ur-my-division" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 16px">'
        . '<span>Show:</span>' . $btn('All events', $base, !$on) . $btn('My division (' . implode(' + ', $names) . ')', add_query_arg('ur_division', 'mine', $base), $on)
        . '<small style="opacity:.7">My division still includes statewide events.</small></div>';
});

// Elementor Posts / Loop Grid with Query ID "ur_for_me": only content tagged for the member's groups.
add_action('elementor/query/ur_for_me', function ($q) {
    $ctx = ur_mg_user_context();
    if ($ctx['staff']) {
        $q->set('tax_query', [['taxonomy' => UR_MG_TAX, 'operator' => 'EXISTS']]);
        return;
    }
    if (!$ctx['groups']) {
        $q->set('post__in', [0]);
        return;
    }
    $q->set('tax_query', [[
        'taxonomy'         => UR_MG_TAX,
        'field'            => 'term_id',
        'terms'            => $ctx['groups'],
        'include_children' => false,
    ]]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin: media modal checkboxes (edit screen uses the normal meta box)
// ─────────────────────────────────────────────────────────────────────────────

add_filter('attachment_fields_to_edit', function ($fields, $post) {
    global $pagenow;
    if ($pagenow === 'post.php' || !current_user_can('edit_post', $post->ID)) return $fields;
    $terms = get_terms(['taxonomy' => UR_MG_TAX, 'hide_empty' => false]);
    if (is_wp_error($terms) || !$terms) return $fields;
    $have = ur_mg_post_groups($post->ID);
    $name = 'attachments[' . (int) $post->ID . '][ur_mg_terms][]';
    $html = '<input type="hidden" name="attachments[' . (int) $post->ID . '][ur_mg_present]" value="1">';
    foreach ($terms as $t) {
        $html .= '<label style="display:block"><input type="checkbox" name="' . esc_attr($name) . '" value="' . (int) $t->term_id . '"'
            . checked(in_array((int) $t->term_id, $have, true), true, false) . '> ' . esc_html($t->name) . '</label>';
    }
    unset($fields[UR_MG_TAX]);
    $fields['ur_mg_terms'] = [
        'label' => 'Member Groups',
        'input' => 'html',
        'html'  => $html,
        'helps' => 'Only these groups can open this file. Leave all unticked for everyone.',
    ];
    return $fields;
}, 10, 2);

add_filter('attachment_fields_to_save', function ($post, $attachment) {
    if (empty($attachment['ur_mg_present']) || !current_user_can('edit_post', $post['ID'])) return $post;
    $ids = array_map('intval', (array) ($attachment['ur_mg_terms'] ?? []));
    wp_set_object_terms((int) $post['ID'], $ids, UR_MG_TAX, false);
    return $post;
}, 10, 2);

// ─────────────────────────────────────────────────────────────────────────────
// Admin: WP-Members Download Protect screen (Media > Download Protect)
// Its upload/edit forms have no hooks, so the checkboxes are added to the form in the
// browser and saved on admin_init around Download Protect's own save (priority 11).
// ─────────────────────────────────────────────────────────────────────────────

function ur_mg_is_dp_screen() {
    return is_admin() && isset($_GET['page']) && $_GET['page'] === 'wpmem-download-protect';
}

function ur_mg_dp_posted_terms() {
    if (empty($_POST['ur_mg_dp_present']) || empty($_POST['ur_mg_dp_nonce'])) return null;
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ur_mg_dp_nonce'])), 'ur_mg_dp')) return null;
    return array_map('intval', (array) ($_POST['ur_mg_dp_terms'] ?? []));
}

add_action('admin_init', function () {
    if (!ur_mg_is_dp_screen() || 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) return;
    $terms = ur_mg_dp_posted_terms();
    if ($terms === null) return;
    $action = sanitize_text_field(wp_unslash($_POST['wpmem_admin_a'] ?? ''));
    if ($action === 'dp_upload' || $action === 'dp_update') {
        // A new or replacement file creates its attachment during Download Protect's save; tag it as it's created.
        add_action('add_attachment', function ($id) use ($terms) {
            static $done = false;
            if ($done || !current_user_can('edit_post', $id)) return;
            $done = true;
            wp_set_object_terms((int) $id, $terms, UR_MG_TAX, false);
        });
    }
    if ($action === 'dp_update') {
        $id = (int) ($_POST['dpid'] ?? 0);
        if ($id && get_post_type($id) === 'attachment' && current_user_can('edit_post', $id)) {
            wp_set_object_terms($id, $terms, UR_MG_TAX, false);
        }
    }
}, 10);

add_action('admin_footer', function () {
    if (!ur_mg_is_dp_screen() || !current_user_can('upload_files')) return;
    $terms = get_terms(['taxonomy' => UR_MG_TAX, 'hide_empty' => false]);
    if (is_wp_error($terms) || !$terms) return;

    $edit_id = (isset($_GET['action']) && $_GET['action'] === 'edit') ? (int) ($_GET['id'] ?? 0) : 0;
    $have    = $edit_id ? ur_mg_post_groups($edit_id) : [];
    $by_parent = [];
    foreach ($terms as $t) $by_parent[(int) $t->parent][] = $t;

    $box  = '<li id="ur-mg-dp-box" style="margin:12px 0;padding:10px;border:1px solid #dcdcde;background:#fff;text-align:left">';
    $box .= '<strong>Member Groups</strong><br><span class="description">Only these groups can open this file. Leave all unticked for every logged-in member.</span>';
    $box .= '<div style="max-height:220px;overflow:auto;margin-top:6px">';
    $render = function ($parent, $depth) use (&$render, $by_parent, $have, &$box) {
        foreach ($by_parent[$parent] ?? [] as $t) {
            $box .= sprintf('<label style="display:block;margin-left:%dpx"><input type="checkbox" name="ur_mg_dp_terms[]" value="%d"%s> %s</label>',
                $depth * 16, (int) $t->term_id, checked(in_array((int) $t->term_id, $have, true), true, false), esc_html($t->name));
            $render((int) $t->term_id, $depth + 1);
        }
    };
    $render(0, 0);
    $box .= '</div><input type="hidden" name="ur_mg_dp_present" value="1">'
          . '<input type="hidden" name="ur_mg_dp_nonce" value="' . esc_attr(wp_create_nonce('ur_mg_dp')) . '"></li>';

    // Group names per file key, for labels in the file list.
    $labels = [];
    if (!$edit_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS dp_key, t.name FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE pm.meta_key = '_wpmem_dp_key'", UR_MG_TAX));
        foreach ((array) $rows as $r) $labels[$r->dp_key][] = $r->name;
    }
    ?>
    <template id="ur-mg-dp-template"><?php echo $box; // Built from escaped parts above. ?></template>
    <script>
    (function () {
        var tpl = document.getElementById('ur-mg-dp-template');
        ['edit-file', 'upload-file'].forEach(function (id) {
            var form = document.getElementById(id);
            if (!form || !tpl) return;
            var node = tpl.content.firstElementChild.cloneNode(true);
            var ul = form.querySelector('ul');
            var submit = form.querySelector('[type=submit]');
            var anchor = submit ? submit.closest('li') : null;
            if (id === 'upload-file' && submit) {
                var div = document.createElement('div');
                div.innerHTML = node.innerHTML;
                div.setAttribute('style', node.getAttribute('style'));
                submit.parentNode.insertBefore(div, submit);
            } else if (anchor && ul) {
                ul.insertBefore(node, anchor);
            } else {
                form.appendChild(node);
            }
        });
        var labels = <?php echo wp_json_encode($labels); ?>;
        document.querySelectorAll('table.wp-list-table tbody tr').forEach(function (tr) {
            var keyCell = tr.querySelector('td.column-key');
            var titleCell = tr.querySelector('td.column-title, th.column-title');
            if (!keyCell || !titleCell) return;
            var names = labels[keyCell.textContent.trim()];
            if (!names) return;
            var tag = document.createElement('div');
            tag.style.cssText = 'margin-top:4px;font-size:12px;color:#2271b1';
            tag.textContent = 'Member Groups: ' + names.join(', ');
            titleCell.appendChild(tag);
        });
    })();
    </script>
    <?php
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin: group rule fields on the Member Groups screen
// ─────────────────────────────────────────────────────────────────────────────

/** Island choices: from the WP-Members "island" field when present, else the six islands. */
function ur_mg_island_options() {
    $opts = [];
    if (function_exists('wpmem_fields')) {
        $f = wpmem_fields();
        foreach ((array) ($f['island']['options'] ?? []) as $val => $label) {
            if ((string) $val !== '') $opts[] = (string) $val;
        }
    }
    return $opts ?: ['Hawaii', 'Kauai', 'Lanai', 'Maui', 'Molokai', 'Oahu'];
}

function ur_mg_island_checkboxes($saved) {
    $have = array_map('strtolower', ur_mg_lines($saved));
    $html = '<input type="hidden" name="ur_mg_islands_present" value="1"><div style="display:flex;flex-wrap:wrap;gap:4px 16px">';
    foreach (ur_mg_island_options() as $isl) {
        $html .= sprintf('<label style="display:inline-block;width:auto"><input type="checkbox" name="ur_mg_islands[]" value="%1$s"%2$s> %3$s</label>',
            esc_attr($isl), checked(in_array(strtolower($isl), $have, true), true, false), esc_html($isl));
    }
    return $html . '</div>';
}

function ur_mg_match_select($current) {
    return '<select name="ur_mg_match" id="ur_mg_match">'
        . '<option value="any"' . selected($current, 'any', false) . '>Any rule (members matching any box: bigger group)</option>'
        . '<option value="all"' . selected($current, 'all', false) . '>All rules (members matching every filled-in box: narrower group)</option>'
        . '</select>';
}

function ur_mg_match_help() {
    return 'Example: Bargaining unit 10 + Job title ADULT CORRECTIONS OFFICER. "Any" = all BU 10 members plus all ACOs. "All" = only BU 10 ACOs. Likewise Island Maui + Bargaining unit 1 on "All" = BU 1 members on Maui. Empty boxes are ignored.';
}

function ur_mg_rule_help($key) {
    switch ($key) {
        case 'ur_mg_bu':         return 'e.g. 1 or 10 (0 = Private Sector). Comma-separated.';
        case 'ur_mg_islands':    return 'Member\'s Island on their profile. Tick one or more.';
        case 'ur_mg_units':      return 'e.g. 535 or 550-554, 149, 249. Ranges allowed.';
        case 'ur_mg_employers':  return 'One per line, exact name as on the member profile (Employer or Jurisdiction), e.g. US Renal Care.';
        case 'ur_mg_job_titles': return 'One per line; matches if the job title contains the text, e.g. ADULT CORRECTIONS OFFICER.';
    }
    return '';
}

add_action(UR_MG_TAX . '_add_form_fields', function () {
    wp_nonce_field('ur_mg_term', 'ur_mg_term_nonce');
    echo '<p><strong>Who is in this group?</strong> Fill in one or more rules below.</p>';
    echo '<div class="form-field"><label for="ur_mg_match">Match</label>' . ur_mg_match_select('any') . '<p>' . esc_html(ur_mg_match_help()) . '</p></div>';
    foreach (UR_MG_RULE_KEYS as $k => $label) {
        if ($k === 'ur_mg_islands') {
            printf('<div class="form-field"><label>%1$s</label>%2$s<p>%3$s</p></div>', esc_html($label), ur_mg_island_checkboxes(''), esc_html(ur_mg_rule_help($k)));
            continue;
        }
        printf('<div class="form-field"><label for="%1$s">%2$s</label><textarea name="%1$s" id="%1$s" rows="2"></textarea><p>%3$s</p></div>',
            esc_attr($k), esc_html($label), esc_html(ur_mg_rule_help($k)));
    }
});

add_action(UR_MG_TAX . '_edit_form_fields', function ($term) {
    wp_nonce_field('ur_mg_term', 'ur_mg_term_nonce');
    printf('<tr class="form-field"><th scope="row"><label for="ur_mg_match">Match</label></th><td>%s<p class="description">%s</p></td></tr>',
        ur_mg_match_select(get_term_meta($term->term_id, 'ur_mg_match', true) === 'all' ? 'all' : 'any'), esc_html(ur_mg_match_help()));
    foreach (UR_MG_RULE_KEYS as $k => $label) {
        if ($k === 'ur_mg_islands') {
            printf('<tr class="form-field"><th scope="row">%1$s</th><td>%2$s<p class="description">%3$s</p></td></tr>',
                esc_html($label), ur_mg_island_checkboxes((string) get_term_meta($term->term_id, $k, true)), esc_html(ur_mg_rule_help($k)));
            continue;
        }
        printf('<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea name="%1$s" id="%1$s" rows="3">%3$s</textarea><p class="description">%4$s</p></td></tr>',
            esc_attr($k), esc_html($label), esc_textarea((string) get_term_meta($term->term_id, $k, true)), esc_html(ur_mg_rule_help($k)));
    }
    $n = ur_mg_member_counts()[(int) $term->term_id] ?? 0;
    printf('<tr class="form-field"><th scope="row">Members matched</th><td>%d registered members match these rules today.</td></tr>', (int) $n);
});

function ur_mg_save_term_rules($term_id) {
    if (!isset($_POST['ur_mg_term_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ur_mg_term_nonce'])), 'ur_mg_term')) return;
    if (!current_user_can('manage_options')) return;
    if (!empty($_POST['ur_mg_islands_present'])) {
        $allowed = array_map('strtolower', ur_mg_island_options());
        $picked  = [];
        foreach ((array) wp_unslash($_POST['ur_mg_islands'] ?? []) as $isl) {
            $isl = sanitize_text_field($isl);
            if (in_array(strtolower($isl), $allowed, true)) $picked[] = $isl;
        }
        update_term_meta($term_id, 'ur_mg_islands', implode(', ', $picked));
    }
    foreach (array_keys(UR_MG_RULE_KEYS) as $k) {
        if ($k === 'ur_mg_islands') continue;
        if (isset($_POST[$k])) update_term_meta($term_id, $k, sanitize_textarea_field(wp_unslash($_POST[$k])));
    }
    if (isset($_POST['ur_mg_match'])) {
        update_term_meta($term_id, 'ur_mg_match', wp_unslash($_POST['ur_mg_match']) === 'all' ? 'all' : 'any');
    }
    delete_transient('ur_mg_member_counts');
}
add_action('created_' . UR_MG_TAX, 'ur_mg_save_term_rules');
add_action('edited_' . UR_MG_TAX, 'ur_mg_save_term_rules');

/** term_id => number of registered (non-staff, non-blocked) members matching. Cached 10 min. */
function ur_mg_member_counts() {
    $cached = get_transient('ur_mg_member_counts');
    if (is_array($cached)) return $cached;
    global $wpdb;
    $keys = ['bargaining_unit', 'unit_number', 'island', 'employer', 'jurisdiction', 'job_title', 'member_status'];
    $in   = implode(',', array_fill(0, count($keys), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ($in)", $keys), ARRAY_A);
    $profiles = [];
    foreach ($rows as $r) $profiles[(int) $r['user_id']][$r['meta_key']] = $r['meta_value'];
    $groups = ur_mg_groups(true);
    $counts = array_fill_keys(array_keys($groups), 0);
    foreach ($profiles as $p) {
        if (ur_mg_status_blocked($p['member_status'] ?? '')) continue;
        foreach ($groups as $tid => $g) {
            if (ur_mg_profile_matches($p, $g)) $counts[$tid]++;
        }
    }
    set_transient('ur_mg_member_counts', $counts, 10 * MINUTE_IN_SECONDS);
    return $counts;
}

add_filter('manage_edit-' . UR_MG_TAX . '_columns', function ($cols) {
    unset($cols['description']);
    $cols['ur_mg_match']   = 'Match';
    $cols['ur_mg_members'] = 'Members';
    return $cols;
});
add_filter('manage_' . UR_MG_TAX . '_custom_column', function ($out, $col, $term_id) {
    if ($col === 'ur_mg_match') return get_term_meta($term_id, 'ur_mg_match', true) === 'all' ? 'All rules' : 'Any rule';
    return $col === 'ur_mg_members' ? (string) (int) (ur_mg_member_counts()[(int) $term_id] ?? 0) : $out;
}, 10, 3);

// ─────────────────────────────────────────────────────────────────────────────
// Admin: staff verification on the user profile
// ─────────────────────────────────────────────────────────────────────────────

function ur_mg_staff_profile_field($user) {
    if (!current_user_can('manage_options')) return;
    $on = ur_mg_is_verified_staff_email($user);
    echo '<h2>UnionRoll Member Groups</h2><table class="form-table"><tr><th>Staff access</th><td>';
    wp_nonce_field('ur_mg_staff', 'ur_mg_staff_nonce');
    printf('<label><input type="checkbox" name="ur_mg_staff_verified" value="1" %s> Verified staff: sees all member-group content</label>', checked($on, true, false));
    echo '<p class="description">Only works for addresses on a staff domain (Settings: ' . esc_html(ur_mg_settings()['staff_domains']) . '). If the email address changes, staff access stops until re-verified.</p></td></tr></table>';
}
add_action('edit_user_profile', 'ur_mg_staff_profile_field');
add_action('show_user_profile', 'ur_mg_staff_profile_field');

function ur_mg_staff_profile_save($user_id) {
    if (!current_user_can('manage_options') || !isset($_POST['ur_mg_staff_nonce'])) return;
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ur_mg_staff_nonce'])), 'ur_mg_staff')) return;
    $user = get_userdata($user_id);
    if (!empty($_POST['ur_mg_staff_verified']) && $user) {
        update_user_meta($user_id, 'ur_mg_staff_verified_email', strtolower(trim($user->user_email)));
    } else {
        delete_user_meta($user_id, 'ur_mg_staff_verified_email');
    }
}
// profile_update fires after the user's email is saved, so a changed address is the one verified.
add_action('profile_update', function ($user_id) {
    if (is_admin() && isset($_POST['ur_mg_staff_nonce'])) ur_mg_staff_profile_save($user_id);
}, 20);

// ─────────────────────────────────────────────────────────────────────────────
// Admin: settings + member lookup page
// ─────────────────────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_submenu_page('union_roll', 'Member Groups', 'Member Groups', 'manage_options',
        'edit-tags.php?taxonomy=' . UR_MG_TAX);
    add_submenu_page('union_roll', 'Member Groups Settings', 'Member Groups Settings', 'manage_options',
        'union_roll_member_groups', 'ur_mg_render_settings_page');
}, 20);

function ur_mg_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $notice = '';

    if (isset($_POST['ur_mg_save']) && check_admin_referer('ur_mg_settings')) {
        update_option('ur_mg_settings', [
            'staff_domains'    => sanitize_textarea_field(wp_unslash($_POST['staff_domains'] ?? '')),
            'blocked_statuses' => sanitize_textarea_field(wp_unslash($_POST['blocked_statuses'] ?? '')),
            'denied_message'   => sanitize_textarea_field(wp_unslash($_POST['denied_message'] ?? '')),
            'for_you_url'      => esc_url_raw(wp_unslash($_POST['for_you_url'] ?? '')),
        ], false);
        delete_transient('ur_mg_member_counts');
        $notice = 'Settings saved.';
    }

    if (isset($_POST['ur_mg_verify_all']) && check_admin_referer('ur_mg_settings')) {
        $n = 0;
        foreach (ur_mg_lines(ur_mg_settings()['staff_domains']) as $d) {
            $users = get_users(['search' => '*@' . $d, 'search_columns' => ['user_email'], 'fields' => ['ID', 'user_email']]);
            foreach ($users as $u) {
                update_user_meta($u->ID, 'ur_mg_staff_verified_email', strtolower(trim($u->user_email)));
                $n++;
            }
        }
        $notice = sprintf('%d staff-domain accounts verified.', $n);
    }

    $s = ur_mg_settings();
    echo '<div class="wrap"><h1>Member Groups Settings</h1>';
    if ($notice) echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
    echo '<p>Create groups and their rules under <a href="' . esc_url(admin_url('edit-tags.php?taxonomy=' . UR_MG_TAX)) . '">Member Groups</a>. '
        . 'Tick groups on any post, page, event or media file. Content with no group ticked stays visible to everyone. '
        . 'To restrict part of a page, add the CSS class <code>ur-group-&lt;group-slug&gt;</code> to an Elementor section or widget, '
        . 'or wrap text in <code>[ur_group_only groups="group-slug"]…[/ur_group_only]</code>. '
        . 'An Elementor Posts / Loop Grid with Query ID <code>ur_for_me</code> shows only content tagged for the viewer\'s groups.</p>';
    echo '<form method="post">';
    wp_nonce_field('ur_mg_settings');
    echo '<table class="form-table">';
    printf('<tr><th>Staff email domains</th><td><textarea name="staff_domains" rows="2" cols="50">%s</textarea><p class="description">Editors and administrators always see everything. Accounts on these domains see everything only once verified (below, or on the user\'s profile).</p></td></tr>', esc_textarea($s['staff_domains']));
    printf('<tr><th>Member statuses with no group access</th><td><textarea name="blocked_statuses" rows="6" cols="50">%s</textarea><p class="description">One per line, matched against the member\'s Member Status. These members only see content with no group ticked.</p></td></tr>', esc_textarea($s['blocked_statuses']));
    printf('<tr><th>For You page</th><td><input type="url" name="for_you_url" value="%s" class="regular-text" placeholder="%s"><p class="description">Where "See what\'s for you" and View-as previews go. Leave empty to use the page that contains [ur_for_you] (now: %s).</p></td></tr>',
        esc_attr($s['for_you_url'] ?? ''), esc_attr(home_url('/')), esc_html(ur_mg_for_you_url()));
    printf('<tr><th>Message when access is denied</th><td><textarea name="denied_message" rows="3" cols="80">%s</textarea></td></tr>', esc_textarea($s['denied_message']));
    echo '</table><p><button class="button button-primary" name="ur_mg_save" value="1">Save settings</button> ';
    echo '<button class="button" name="ur_mg_verify_all" value="1" onclick="return confirm(\'Verify every account currently on the staff domains?\')">Verify all current staff-domain accounts</button></p></form>';

    // Member lookup.
    $q = isset($_GET['ur_mg_lookup']) ? sanitize_text_field(wp_unslash($_GET['ur_mg_lookup'])) : '';
    echo '<h2 id="ur-mg-view-as">Preview the site</h2><p>See the site exactly as a member sees it: check a member below and click <em>View the site as this member</em>. Or preview a single group: ';
    echo '<select onchange="if(this.value)location.href=this.value"><option value="">Choose a group&hellip;</option>';
    foreach (ur_mg_groups() as $tid => $g) printf('<option value="%s">%s</option>', esc_url(ur_mg_view_as_url('group:' . (int) $tid)), esc_html($g['term']->name));
    echo '</select> <span class="description">(Also in the admin bar on the front end: &#128065; View as member.)</span></p>';
    echo '<h2>Check a member</h2><form method="get"><input type="hidden" name="page" value="union_roll_member_groups">';
    printf('<input type="text" name="ur_mg_lookup" value="%s" placeholder="Email, username or user ID" size="40"> <button class="button">Check</button></form>', esc_attr($q));
    if ($q !== '') {
        $u = ctype_digit($q) ? get_userdata((int) $q) : (get_user_by('email', $q) ?: get_user_by('login', $q));
        if (!$u) {
            echo '<p>No user found.</p>';
        } else {
            $ctx = ur_mg_user_context($u->ID);
            $names = [];
            foreach (ur_mg_groups() as $tid => $g) if (in_array($tid, $ctx['groups'], true)) $names[] = $g['term']->name;
            echo '<table class="widefat striped" style="max-width:700px"><tbody>';
            printf('<tr><th>User</th><td>%s (#%d)</td></tr>', esc_html($u->display_name), (int) $u->ID);
            foreach (['bargaining_unit' => 'Bargaining unit', 'unit_number' => 'Unit', 'island' => 'Island', 'employer' => 'Employer', 'jurisdiction' => 'Jurisdiction', 'job_title' => 'Job title', 'member_status' => 'Member status'] as $k => $label) {
                printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) get_user_meta($u->ID, $k, true)));
            }
            $access = $ctx['staff'] ? 'Staff: sees everything' : ($ctx['blocked'] ? 'Blocked by member status: sees only unrestricted content' : ($names ? esc_html(implode(', ', $names)) : 'No groups: sees only unrestricted content'));
            printf('<tr><th>Access</th><td><strong>%s</strong></td></tr>', $access);
            echo '</tbody></table>';
            printf('<p><a class="button button-primary" href="%s">View the site as this member</a> <span class="description">Opens the home page as this member sees it (red banner at the bottom; ends after 2 hours or when you click Stop preview).</span></p>',
                esc_url(ur_mg_view_as_url('user:' . (int) $u->ID)));
        }
    }
    echo '</div>';
}


// ─────────────────────────────────────────────────────────────────────────────
// Admin "View as": preview the front end as a specific member or a group.
// Front end only (never wp-admin, the Elementor editor, or requests coming from wp-admin),
// only for administrators, nonce-protected, expires after 2 hours.
// ─────────────────────────────────────────────────────────────────────────────

const UR_MG_VIEW_AS_TTL = 2 * HOUR_IN_SECONDS;

/** The real logged-in user is an administrator (checked without any preview applied). */
function ur_mg_real_user_is_admin() {
    $u = wp_get_current_user();
    return $u && $u->exists() && !empty($u->allcaps['manage_options']);
}

function ur_mg_view_as_context_allowed() {
    if (isset($_GET['elementor-preview']) || (isset($_GET['action']) && $_GET['action'] === 'elementor')) return false;
    if (is_admin() && !wp_doing_ajax()) return false;
    $is_rest = defined('REST_REQUEST') && REST_REQUEST;
    if (wp_doing_ajax() || $is_rest) {
        $ref = (string) wp_get_referer();
        if ($ref === '' || strpos($ref, '/wp-admin/') !== false) return false;
    }
    return true;
}

/** @return array{mode:string,id:int,expires:int}|null */
function ur_mg_view_as() {
    static $va = false;
    if ($va !== false) return $va;
    $va = null;
    if (!is_user_logged_in() || !ur_mg_real_user_is_admin() || !ur_mg_view_as_context_allowed()) return $va;
    $saved = get_user_meta(get_current_user_id(), 'ur_mg_view_as', true);
    if (!is_array($saved) || empty($saved['mode']) || empty($saved['id']) || (int) ($saved['expires'] ?? 0) < time()) return $va;
    if (!in_array($saved['mode'], ['user', 'group'], true)) return $va;
    return $va = ['mode' => $saved['mode'], 'id' => (int) $saved['id'], 'expires' => (int) $saved['expires']];
}

/** The member whose profile should drive per-member features (For You, My division). */
function ur_mg_effective_user_id() {
    $va = ur_mg_view_as();
    return ($va && $va['mode'] === 'user') ? $va['id'] : get_current_user_id();
}

function ur_mg_view_as_url($value, $redirect = '') {
    return add_query_arg([
        'ur_mg_view_as' => $value,
        '_ur_mg_nonce'  => wp_create_nonce('ur_mg_view_as'),
        'ur_mg_back'    => rawurlencode($redirect ?: ur_mg_for_you_url()),
    ], home_url('/'));
}

// Turn preview on/off.
add_action('init', function () {
    if (!isset($_GET['ur_mg_view_as']) || !is_user_logged_in() || !ur_mg_real_user_is_admin()) return;
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_ur_mg_nonce'] ?? '')), 'ur_mg_view_as')) return;
    $val = sanitize_text_field(wp_unslash($_GET['ur_mg_view_as']));
    $uid = get_current_user_id();
    if ($val === 'off') {
        delete_user_meta($uid, 'ur_mg_view_as');
    } elseif (preg_match('/^(user|group):(\d+)$/', $val, $m)) {
        $ok = $m[1] === 'user' ? (bool) get_userdata((int) $m[2]) : (bool) term_exists((int) $m[2], UR_MG_TAX);
        if ($ok) update_user_meta($uid, 'ur_mg_view_as', ['mode' => $m[1], 'id' => (int) $m[2], 'expires' => time() + UR_MG_VIEW_AS_TTL]);
    }
    $back = isset($_GET['ur_mg_back']) ? esc_url_raw(rawurldecode(wp_unslash($_GET['ur_mg_back']))) : home_url('/');
    wp_safe_redirect($back ?: home_url('/'));
    exit;
}, 1);

function ur_mg_view_as_label($va) {
    if ($va['mode'] === 'user') {
        $u = get_userdata($va['id']);
        $ctx = ur_mg_user_context($va['id']);
        $names = [];
        foreach (ur_mg_groups() as $tid => $g) if (in_array($tid, $ctx['groups'], true)) $names[] = $g['term']->name;
        $who = $u ? $u->display_name . ' (#' . $u->ID . ')' : 'member #' . $va['id'];
        if ($ctx['staff']) return $who . ': staff, sees everything';
        if ($ctx['blocked']) return $who . ': blocked by member status, sees only unrestricted content';
        return $who . ': ' . ($names ? implode(', ', $names) : 'no groups, sees only unrestricted content');
    }
    $t = get_term($va['id'], UR_MG_TAX);
    return 'group "' . ($t && !is_wp_error($t) ? $t->name : '#' . $va['id']) . '" only (For You and My division are approximate in group mode)';
}

// Red banner on every front-end page while previewing.
add_action('wp_footer', function () {
    $va = ur_mg_view_as();
    if (!$va) return;
    $mins = max(1, (int) round(($va['expires'] - time()) / 60));
    $here = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    echo '<div id="ur-mg-view-as-banner" style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#b00020;color:#fff;padding:10px 16px;font:600 14px/1.4 system-ui,sans-serif;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;box-shadow:0 -2px 8px rgba(0,0,0,.25)">'
        . '<span>Previewing the site as ' . esc_html(ur_mg_view_as_label($va)) . '. Ends in ' . (int) $mins . ' min.</span>'
        . '<a href="' . esc_url(ur_mg_view_as_url('off', $here)) . '" style="background:#fff;color:#b00020;padding:6px 14px;border-radius:4px;text-decoration:none">Stop preview</a></div>';
}, 999);

// Admin bar: "View as" menu (front end only).
add_action('admin_bar_menu', function ($bar) {
    if (is_admin() || !ur_mg_real_user_is_admin()) return;
    $here = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $here = remove_query_arg(['ur_mg_view_as', '_ur_mg_nonce', 'ur_mg_back'], $here);
    $va = ur_mg_view_as();
    $bar->add_node(['id' => 'ur-mg-view-as', 'title' => $va ? '&#128065; Previewing as member' : '&#128065; View as member', 'href' => admin_url('admin.php?page=union_roll_member_groups#ur-mg-view-as'),
        'meta' => $va ? ['html' => '<style>#wp-admin-bar-ur-mg-view-as>.ab-item{background:#b00020!important;color:#fff!important}</style>'] : []]);
    if ($va) $bar->add_node(['parent' => 'ur-mg-view-as', 'id' => 'ur-mg-view-as-off', 'title' => 'Stop preview', 'href' => ur_mg_view_as_url('off', $here)]);
    $bar->add_node(['parent' => 'ur-mg-view-as', 'id' => 'ur-mg-view-as-member', 'title' => 'A specific member&hellip;', 'href' => admin_url('admin.php?page=union_roll_member_groups#ur-mg-view-as')]);
    $by_parent = [];
    foreach (ur_mg_groups() as $tid => $g) {
        $parent = (int) (get_term($tid, UR_MG_TAX)->parent ?? 0);
        $by_parent[$parent][] = $g['term'];
    }
    $add = function ($parent, $depth) use (&$add, $by_parent, $bar, $here) {
        foreach ($by_parent[$parent] ?? [] as $t) {
            $bar->add_node(['parent' => 'ur-mg-view-as', 'id' => 'ur-mg-view-as-g' . (int) $t->term_id,
                'title' => str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . 'Group: ' . esc_html($t->name), 'href' => ur_mg_view_as_url('group:' . (int) $t->term_id, $here)]);
            $add((int) $t->term_id, $depth + 1);
        }
    };
    $add(0, 0);
}, 100);
