<?php
/**
 * 验证码集合插件 - English Language Pack
 * @author RuiNexus
 */
return [
    // Plugin info
    'plugin_title'       => 'Captcha Collection',
    'plugin_description' => 'Supports Geetest v4, Turnstile, and hCAPTCHA services',

    // Captcha types
    'geetest_title'      => 'Geetest v4',
    'turnstile_title'    => 'Turnstile (Cloudflare)',
    'hcaptcha_title'     => 'hCAPTCHA',

    // Config labels
    'captcha_type_label'         => 'Captcha Type',
    'captcha_type_tip'           => 'Select the captcha service to use. Changes take effect immediately.',
    'geetest_captcha_id_label'   => 'Captcha ID',
    'geetest_captcha_id_tip'     => 'CaptchaID from GeeTest dashboard',
    'geetest_captcha_key_label'  => 'Captcha Key',
    'geetest_captcha_key_tip'    => 'CaptchaKey from GeeTest dashboard (for server-side verification)',
    'turnstile_sitekey_label'    => 'Site Key',
    'turnstile_sitekey_tip'      => 'Site Key from Cloudflare Dashboard',
    'turnstile_secret_label'     => 'Secret Key',
    'turnstile_secret_tip'       => 'Secret Key from Cloudflare Dashboard',
    'turnstile_mode_label'       => 'Widget Mode',
    'turnstile_mode_tip'         => 'Managed auto-selects interactive/non-interactive based on visitor risk; Non-Interactive shows spinner without user action; Invisible runs entirely in background',
    'turnstile_appearance_label' => 'Appearance Mode',
    'turnstile_appearance_tip'   => 'interaction-only mode skips the widget for verified visitors, only suspicious traffic triggers interactive challenge',
    'turnstile_size_label'       => 'Widget Size',
    'turnstile_size_tip'         => 'Only applies to Managed and Non-Interactive modes; Invisible mode has no visual element',
    'hcaptcha_sitekey_label'     => 'Site Key',
    'hcaptcha_sitekey_tip'       => 'Site Key from hCaptcha Dashboard',
    'hcaptcha_secret_label'      => 'Secret Key',
    'hcaptcha_secret_tip'        => 'Secret Key from hCaptcha Dashboard',

    // Frontend text
    'verify_title'               => 'Human Verification',
    'security_verify'            => 'Security Verification',
    'cancel_btn'                 => 'Cancel',
    'input_placeholder'          => 'Enter captcha code',
    'verify_success'             => 'Verification successful',
    'verify_failed'              => 'Verification failed',
    'wait_verify'                => 'Please wait for verification',
    'complete_verify_first'      => 'Please complete verification first',
    'network_error'              => 'Network error',
    'config_incomplete'          => 'Configuration incomplete, please contact administrator',
    'type_not_configured'        => 'Captcha type not configured',

    // Error messages
    'error_token_empty'          => 'Captcha token is empty',
    'error_param_incomplete'     => 'Verification parameters incomplete',
    'error_sign_failed'          => 'Failed to obtain signature',
    'error_key_not_configured'   => 'Key not configured',
];
