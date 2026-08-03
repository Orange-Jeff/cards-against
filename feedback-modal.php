<?php
/**
 * NetBound Ecosystem Feedback Modal
 * Version 1.1.0 - 2026-01-09 03:00
 *
 * Standalone IFRAME page for plugin feedback
 * - Display vote counts (aggregated from all customer sites)
 * - Allow one vote per domain per plugin
 * - Optional email notifications
 * - Comment submission creates WordPress post comments
 * - Admin email attached to all feedback submissions
 *
 * Location: /downloads/plugins/feedback-modal.php
 * URL: https://netbound.ca/downloads/plugins/feedback-modal/?plugin=nb-dashboard&domain=customer.com
 */

// Load WordPress (for comment creation + database)
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');

// Get parameters
$plugin = sanitize_text_field($_GET['plugin'] ?? '');
$domain = sanitize_text_field($_GET['domain'] ?? '');
$action = sanitize_text_field($_POST['action'] ?? '');

// Validate
if (empty($plugin) || empty($domain)) {
    die('Invalid parameters');
}

// ============================================================================
// Database Setup - Votes Table
// ============================================================================
function nb_ensure_votes_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nb_ecosystem_votes';

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        return; // Table exists
    }

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        plugin varchar(100) NOT NULL,
        domain varchar(255) NOT NULL,
        vote varchar(10) NOT NULL,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_vote (plugin, domain)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

nb_ensure_votes_table();

// ============================================================================
// Handle Vote Submission (AJAX)
// ============================================================================
if ($action === 'vote') {
    global $wpdb;

    $vote = sanitize_text_field($_POST['vote'] ?? '');
    if (!in_array($vote, ['up', 'down'])) {
        wp_send_json_error(['message' => 'Invalid vote']);
    }

    $table_name = $wpdb->prefix . 'nb_ecosystem_votes';

    // Check if already voted
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE plugin = %s AND domain = %s",
        $plugin, $domain
    ));

    if ($existing) {
        // Update existing vote
        $wpdb->update(
            $table_name,
            ['vote' => $vote, 'timestamp' => current_time('mysql')],
            ['plugin' => $plugin, 'domain' => $domain],
            ['%s', '%s'],
            ['%s', '%s']
        );
    } else {
        // Insert new vote
        $wpdb->insert(
            $table_name,
            [
                'plugin' => $plugin,
                'domain' => $domain,
                'vote' => $vote,
                'timestamp' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s']
        );
    }

    wp_send_json_success(['message' => 'Vote recorded']);
}

// ============================================================================
// Handle Comment Submission
// ============================================================================
if ($action === 'comment') {
    $message = sanitize_textarea_field($_POST['message'] ?? '');
    $subscribe = isset($_POST['subscribe']);

    if (empty($message)) {
        wp_send_json_error(['message' => 'Message required']);
    }

    // Get or create plugin feedback post
    $post_title = 'Feedback: ' . $plugin;
    $post = get_page_by_title($post_title, OBJECT, 'post');

    if (!$post) {
        $post_id = wp_insert_post([
            'post_title' => $post_title,
            'post_content' => 'Ecosystem feedback aggregation',
            'post_status' => 'private',
            'post_type' => 'post'
        ]);
    } else {
        $post_id = $post->ID;
    }

    // Get admin email for attachment
    $admin_email = get_option('admin_email');

    // Insert comment with admin email attached
    $comment_content = $message . "\n\n--- Feedback from: {$domain} | Admin Email: {$admin_email}";
    $comment_id = wp_insert_comment([
        'comment_post_ID' => $post_id,
        'comment_content' => $comment_content,
        'comment_author' => $domain,
        'comment_author_email' => $admin_email,
        'comment_author_url' => 'https://' . $domain,
        'comment_type' => 'nb_feedback',
        'comment_approved' => 0, // Moderation queue
        'user_id' => 0
    ]);

    // Store subscription preference if checked
    if ($subscribe) {
        update_option(
            'nb_feedback_subscription_' . md5($plugin . $domain),
            ['plugin' => $plugin, 'domain' => $domain, 'email' => $domain, 'subscribed' => true]
        );
    }

    if ($comment_id) {
        wp_send_json_success(['message' => 'Thank you for your feedback!']);
    } else {
        wp_send_json_error(['message' => 'Error saving feedback']);
    }
}

// ============================================================================
// Get Vote Counts
// ============================================================================
function nb_get_vote_counts($plugin) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nb_ecosystem_votes';

    $up = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE plugin = %s AND vote = 'up'",
        $plugin
    ));

    $down = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE plugin = %s AND vote = 'down'",
        $plugin
    ));

    return ['up' => (int)$up, 'down' => (int)$down];
}

function nb_has_voted($plugin, $domain) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nb_ecosystem_votes';

    return $wpdb->get_row($wpdb->prepare(
        "SELECT vote FROM $table_name WHERE plugin = %s AND domain = %s",
        $plugin, $domain
    ));
}

// Get current state
$votes = nb_get_vote_counts($plugin);
$user_vote = nb_has_voted($plugin, $domain);

// ============================================================================
// HTML Output
// ============================================================================
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($plugin); ?> Feedback</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            font-size: 14px;
            line-height: 1.5;
        }

        .container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #FFA500;
            padding-bottom: 12px;
        }

        .header h2 {
            font-size: 16px;
            color: #23282d;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .header p {
            font-size: 12px;
            color: #666;
        }

        .vote-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 20px;
        }

        .vote-button {
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            transition: all 0.2s;
        }

        .vote-button:hover {
            border-color: #FFA500;
            background: #fff8f0;
        }

        .vote-button.active {
            border-color: #FFA500;
            background: #FFA500;
            color: white;
        }

        .vote-count {
            font-size: 24px;
            font-weight: bold;
            color: #23282d;
            display: block;
            margin-bottom: 4px;
        }

        .vote-label {
            font-size: 12px;
            color: #666;
        }

        .divider {
            height: 1px;
            background: #eee;
            margin: 15px 0;
        }

        .feedback-section h3 {
            font-size: 13px;
            font-weight: 600;
            color: #23282d;
            margin-bottom: 12px;
        }

        textarea {
            width: 100%;
            min-height: 80px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 13px;
            resize: vertical;
            margin-bottom: 10px;
        }

        textarea:focus {
            outline: none;
            border-color: #FFA500;
            box-shadow: 0 0 0 2px rgba(255,165,0,0.1);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            font-size: 12px;
        }

        .checkbox-group input[type="checkbox"] {
            margin-right: 8px;
            cursor: pointer;
        }

        .checkbox-group label {
            cursor: pointer;
        }

        .button-group {
            display: flex;
            gap: 8px;
        }

        button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-submit {
            background: #FFA500;
            color: white;
        }

        .btn-submit:hover {
            background: #FF9500;
        }

        .btn-submit:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .btn-cancel {
            background: #f0f0f0;
            color: #23282d;
        }

        .btn-cancel:hover {
            background: #e0e0e0;
        }

        .message {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 12px;
            font-size: 13px;
            text-align: center;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .loading {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #FFA500;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2><?php echo esc_html($plugin); ?></h2>
            <p>What do you think about this plugin?</p>
        </div>

        <div id="message-area"></div>

        <!-- Vote Section -->
        <div class="vote-section">
            <button type="button" class="vote-button" id="btn-vote-up" data-vote="up">
                <span class="vote-count"><?php echo $votes['up']; ?></span>
                <span class="vote-label">👍 Works Great</span>
            </button>
            <button type="button" class="vote-button" id="btn-vote-down" data-vote="down">
                <span class="vote-count"><?php echo $votes['down']; ?></span>
                <span class="vote-label">👎 Has Issues</span>
            </button>
        </div>

        <div class="divider"></div>

        <!-- Feedback Section -->
        <div class="feedback-section">
            <h3>📝 Leave Feedback (Optional)</h3>
            <textarea id="feedback-message" placeholder="Tell us more... (max 500 chars)" maxlength="500"></textarea>

            <div class="checkbox-group">
                <input type="checkbox" id="subscribe-checkbox">
                <label for="subscribe-checkbox">🔔 Email me about updates</label>
            </div>

            <div class="button-group">
                <button type="button" class="btn-submit" id="btn-submit-feedback">Send Feedback</button>
                <button type="button" class="btn-cancel" id="btn-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <script>
    const plugin = '<?php echo esc_js($plugin); ?>';
    const domain = '<?php echo esc_js($domain); ?>';
    const userVote = <?php echo $user_vote ? "'" . esc_js($user_vote->vote) . "'" : 'null'; ?>;

    // Set initial active state
    if (userVote) {
        document.getElementById('btn-vote-' + userVote).classList.add('active');
    }

    // Vote buttons
    document.getElementById('btn-vote-up').addEventListener('click', function() {
        recordVote('up', this);
    });

    document.getElementById('btn-vote-down').addEventListener('click', function() {
        recordVote('down', this);
    });

    function recordVote(vote, button) {
        const formData = new FormData();
        formData.append('action', 'vote');
        formData.append('plugin', plugin);
        formData.append('domain', domain);
        formData.append('vote', vote);

        button.disabled = true;
        const originalText = button.innerHTML;
        button.innerHTML = '<div class="loading" style="margin: 0 auto;"></div>';

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Update buttons
                document.querySelectorAll('.vote-button').forEach(btn => {
                    btn.classList.remove('active');
                });
                button.classList.add('active');

                showMessage('Vote recorded!', 'success');
                button.disabled = false;
                button.innerHTML = originalText;
            } else {
                showMessage(data.data.message || 'Error', 'error');
                button.disabled = false;
                button.innerHTML = originalText;
            }
        })
        .catch(err => {
            showMessage('Error: ' + err.message, 'error');
            button.disabled = false;
            button.innerHTML = originalText;
        });
    }

    // Submit feedback
    document.getElementById('btn-submit-feedback').addEventListener('click', function() {
        const message = document.getElementById('feedback-message').value.trim();
        const subscribe = document.getElementById('subscribe-checkbox').checked;

        if (!message) {
            showMessage('Please enter feedback', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'comment');
        formData.append('plugin', plugin);
        formData.append('domain', domain);
        formData.append('message', message);
        if (subscribe) formData.append('subscribe', '1');

        const btn = this;
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="loading"></span> Sending...';

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showMessage(data.data.message, 'success');
                document.getElementById('feedback-message').value = '';
                document.getElementById('subscribe-checkbox').checked = false;
                setTimeout(() => window.parent.postMessage({ close: true }, '*'), 2000);
            } else {
                showMessage(data.data.message || 'Error', 'error');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(err => {
            showMessage('Error: ' + err.message, 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });

    document.getElementById('btn-cancel').addEventListener('click', function() {
        window.parent.postMessage({ close: true }, '*');
    });

    function showMessage(text, type) {
        const area = document.getElementById('message-area');
        area.innerHTML = '<div class="message ' + type + '">' + escapeHtml(text) + '</div>';
        area.scrollIntoView({ behavior: 'smooth' });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    </script>
</body>
</html>
