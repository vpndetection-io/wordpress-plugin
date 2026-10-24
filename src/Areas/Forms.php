<?php

declare(strict_types=1);

namespace VPNDetection\WordPress\Areas;

use VPNDetection\WordPress\Detector;
use VPNDetection\WordPress\Settings;

defined('ABSPATH') || exit;

/**
 * Contact forms: Contact Form 7, which refuses a submission it is told is spam and logs why, and
 * WPForms, which shows the form's own error.
 */
final class Forms
{
    public function __construct(private readonly Settings $settings, private readonly Detector $detector)
    {
    }

    /** Whether any form plugin this area knows is active. */
    public static function available(): bool
    {
        return defined('WPCF7_VERSION') || function_exists('wpforms');
    }

    public function register(): void
    {
        add_filter('wpcf7_spam', [$this, 'contactForm7'], 99, 2);
        add_filter('wpforms_process_initial_errors', [$this, 'wpforms'], 99, 2);
    }

    /**
     * @param mixed $spam
     * @param mixed $submission
     * @return mixed
     */
    public function contactForm7($spam, $submission = null)
    {
        if ($spam === true) {
            return $spam;
        }
        $form = is_object($submission) && method_exists($submission, 'get_contact_form') ? $submission->get_contact_form() : null;
        $title = is_object($form) && method_exists($form, 'title') ? (string) $form->title() : '';
        $verdict = $this->detector->judge('forms', ['form' => $title]);
        if ($verdict === null || !$verdict->enforced()) {
            return $spam;
        }
        if (is_object($submission) && method_exists($submission, 'add_spam_log')) {
            $submission->add_spam_log([
                'agent' => 'vpndetection',
                'reason' => sprintf(
                    /* translators: %s: what the visitor's connection was, such as "VPNs (nordvpn)". */
                    __('Sent through %s.', 'vpndetection'),
                    $verdict->describe(),
                ),
            ]);
        }
        return true;
    }

    /**
     * @param mixed $errors
     * @param mixed $form
     * @return mixed
     */
    public function wpforms($errors, $form)
    {
        if (!is_array($errors) || !is_array($form) || !isset($form['id'])) {
            return $errors;
        }
        $title = (string) ($form['settings']['form_title'] ?? '');
        $verdict = $this->detector->judge('forms', ['form' => $title]);
        if ($verdict !== null && $verdict->enforced()) {
            $errors[$form['id']]['header'] = esc_html($this->settings->message());
        }
        return $errors;
    }
}
