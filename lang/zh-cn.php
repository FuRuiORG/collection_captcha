<?php
/**
 * 验证码集合插件 - 中文简体语言包
 * @author RuiNexus
 */
return [
    // 插件基本信息
    'plugin_title'       => '验证码集合',
    'plugin_description' => '支持 Geetest v4、Turnstile、hCAPTCHA 三种验证码服务',

    // 验证码类型
    'geetest_title'      => 'Geetest v4（极验）',
    'turnstile_title'    => 'Turnstile（Cloudflare）',
    'hcaptcha_title'     => 'hCAPTCHA',

    // 配置项标签
    'captcha_type_label'         => '验证码类型',
    'captcha_type_tip'           => '选择要使用的验证码服务，切换后立即生效',
    'geetest_captcha_id_label'   => 'Captcha ID',
    'geetest_captcha_id_tip'     => '极验后台申请的 CaptchaID',
    'geetest_captcha_key_label'  => 'Captcha Key',
    'geetest_captcha_key_tip'    => '极验后台申请的 CaptchaKey（用于服务端二次验证签名）',
    'turnstile_sitekey_label'    => 'Site Key',
    'turnstile_sitekey_tip'      => 'Cloudflare Dashboard 获取的 Site Key',
    'turnstile_secret_label'     => 'Secret Key',
    'turnstile_secret_tip'       => 'Cloudflare Dashboard 获取的 Secret Key',
    'turnstile_mode_label'       => 'Widget 模式',
    'turnstile_mode_tip'         => '托管模式自动根据访客风险选择交互/非交互；非交互式无需用户操作但显示加载动画；不可见模式完全后台运行',
    'turnstile_appearance_label' => '外观模式',
    'turnstile_appearance_tip'   => 'interaction-only 模式下，已通过验证的访客不会看到任何验证组件，仅可疑流量触发交互质询',
    'turnstile_size_label'       => 'Widget 尺寸',
    'turnstile_size_tip'         => '仅对托管和非交互式模式生效，不可见模式无视觉元素',
    'hcaptcha_sitekey_label'     => 'Site Key',
    'hcaptcha_sitekey_tip'       => 'hCaptcha Dashboard 获取的 Site Key',
    'hcaptcha_secret_label'      => 'Secret Key',
    'hcaptcha_secret_tip'        => 'hCaptcha Dashboard 获取的 Secret Key',

    // 前台文案
    'verify_title'               => '人机验证',
    'security_verify'            => '安全验证',
    'cancel_btn'                 => '取消',
    'input_placeholder'          => '请输入验证码',
    'verify_success'             => '验证成功',
    'verify_failed'              => '验证失败',
    'wait_verify'                => '请等待验证完成',
    'complete_verify_first'      => '请先完成验证',
    'network_error'              => '网络错误',
    'config_incomplete'          => '配置不完整，请联系管理员',
    'type_not_configured'        => '未配置验证码类型',

    // 错误信息
    'error_token_empty'          => '验证码 token 为空',
    'error_param_incomplete'     => '验证参数不完整',
    'error_sign_failed'          => '获取签名失败',
    'error_key_not_configured'   => '密钥未配置',
];
