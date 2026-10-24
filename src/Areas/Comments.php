<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Areas;

use VPNDetection\WordPress\Detector;
use VPNDetection\WordPress\Settings;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Comments from visitors who are not signed in: held for moderation, marked as spam or refused.
 *
 * Pingbacks and trackbacks are left alone: they come from the other site's server, which is a
 * hosting address by nature.
 */
final class Comments
{
    public function __construct(private readonly Settings $settings, private readonly Detector $detector)
    {
    }

    public function register(): void
    {
        add_filter('pre_comment_approved', [$this, 'approve'], 99, 2);
    }

    /**
     * @param mixed $approved
     * @param mixed $comment
     * @return mixed
     */
    public function approve($approved, $comment)
    {
        if (is_wp_error($approved) || $approved === 'spam' || $approved === 'trash' || !is_array($comment)) {
            return $approved;
        }
        if (in_array($comment['comment_type'] ?? '', ['pingback', 'trackback'], true)) {
            return $approved;
        }
        if (is_user_logged_in() || !empty($comment['user_id'])) {
            return $approved;
        }
        $post = (string) ($comment['comment_post_ID'] ?? '');
        $verdict = $this->detector->judge('comment', ['post' => $post === '' ? '' : 'post:' . $post]);
        if ($verdict === null || !$verdict->enforced()) {
            return $approved;
        }
        return match ($verdict->action) {
            'hold' => 0,
            'spam' => 'spam',
            default => new WP_Error('vpndetection_blocked', esc_html($this->settings->message()), 403),
        };
    }
}
