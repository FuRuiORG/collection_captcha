<?php
/**
 * 驗證碼集合插件 - 繁體中文語言包
 * @author RuiNexus
 */
return [
    // 插件基本資訊
    'plugin_title'       => '驗證碼集合',
    'plugin_description' => '支援 Geetest v4、Turnstile、hCAPTCHA 三種驗證碼服務',

    // 驗證碼類型
    'geetest_title'      => 'Geetest v4（極驗）',
    'turnstile_title'    => 'Turnstile（Cloudflare）',
    'hcaptcha_title'     => 'hCAPTCHA',

    // 配置項標籤
    'captcha_type_label'         => '驗證碼類型',
    'captcha_type_tip'           => '選擇要使用的驗證碼服務，切換後即時生效',
    'geetest_captcha_id_label'   => 'Captcha ID',
    'geetest_captcha_id_tip'     => '極驗後台申請的 CaptchaID',
    'geetest_captcha_key_label'  => 'Captcha Key',
    'geetest_captcha_key_tip'    => '極驗後台申請的 CaptchaKey（用於服務端二次驗證簽名）',
    'turnstile_sitekey_label'    => 'Site Key',
    'turnstile_sitekey_tip'      => 'Cloudflare Dashboard 取得的 Site Key',
    'turnstile_secret_label'     => 'Secret Key',
    'turnstile_secret_tip'       => 'Cloudflare Dashboard 取得的 Secret Key',
    'turnstile_mode_label'       => 'Widget 模式',
    'turnstile_mode_tip'         => '託管模式自動根據訪客風險選擇互動/非互動；非互動式無需使用者操作但顯示載入動畫；不可見模式完全背景執行',
    'turnstile_appearance_label' => '外觀模式',
    'turnstile_appearance_tip'   => 'interaction-only 模式下，已通過驗證的訪客不會看到任何驗證元件，僅可疑流量觸發互動質詢',
    'turnstile_size_label'       => 'Widget 尺寸',
    'turnstile_size_tip'         => '僅對託管和非互動式模式生效，不可見模式無視覺元素',
    'hcaptcha_sitekey_label'     => 'Site Key',
    'hcaptcha_sitekey_tip'       => 'hCaptcha Dashboard 取得的 Site Key',
    'hcaptcha_secret_label'      => 'Secret Key',
    'hcaptcha_secret_tip'        => 'hCaptcha Dashboard 取得 Secret Key',

    // 前台文案
    'verify_title'               => '人機驗證',
    'security_verify'            => '安全驗證',
    'cancel_btn'                 => '取消',
    'input_placeholder'          => '請輸入驗證碼',
    'verify_success'             => '驗證成功',
    'verify_failed'              => '驗證失敗',
    'wait_verify'                => '請等待驗證完成',
    'complete_verify_first'      => '請先完成驗證',
    'network_error'              => '網絡錯誤',
    'config_incomplete'          => '配置不完整，請聯繫管理員',
    'type_not_configured'        => '未配置驗證碼類型',

    // 錯誤訊息
    'error_token_empty'          => '驗證碼 token 為空',
    'error_param_incomplete'     => '驗證參數不完整',
    'error_sign_failed'          => '取得簽名失敗',
    'error_key_not_configured'   => '金鑰未配置',
];
