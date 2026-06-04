<?php
/**
 * 验证码集合插件 - 后台配置表单
 * @author RuiNexus
 */
return [
    'captcha_type' => [
        'title'   => '验证码类型',
        'type'    => 'select',
        'value'   => 'geetest',
        'options' => [
            ['value' => 'geetest',   'label' => 'Geetest v4（极验）'],
            ['value' => 'turnstile', 'label' => 'Turnstile（Cloudflare）'],
            ['value' => 'hcaptcha',  'label' => 'hCAPTCHA'],
        ],
        'tip'     => '选择要使用的验证码服务，切换后立即生效',
    ],

    // ===== Geetest v4 配置 =====
    'geetest_group' => [
        'title' => 'Geetest v4 配置',
        'type'  => 'tip',
        'value' => '',
        'size'  => 300,
    ],
    'geetest_captcha_id' => [
        'title' => 'Captcha ID',
        'type'  => 'text',
        'value' => '',
        'tip'   => '极验后台申请的 CaptchaID',
        'size'  => 300,
    ],
    'geetest_captcha_key' => [
        'title' => 'Captcha Key',
        'type'  => 'text',
        'value' => '',
        'tip'   => '极验后台申请的 CaptchaKey（用于服务端二次验证签名）',
        'size'  => 300,
    ],

    // ===== Turnstile 配置 =====
    'turnstile_group' => [
        'title' => 'Turnstile（Cloudflare）配置',
        'type'  => 'tip',
        'value' => '',
        'size'  => 300,
    ],
    'turnstile_sitekey' => [
        'title' => 'Site Key',
        'type'  => 'text',
        'value' => '',
        'tip'   => 'Cloudflare Dashboard 获取的 Site Key',
        'size'  => 300,
    ],
    'turnstile_secret' => [
        'title' => 'Secret Key',
        'type'  => 'text',
        'value' => '',
        'tip'   => 'Cloudflare Dashboard 获取的 Secret Key',
        'size'  => 300,
    ],
    'turnstile_mode' => [
        'title'   => 'Widget 模式',
        'type'    => 'select',
        'value'   => 'managed',
        'options' => [
            ['value' => 'managed',         'label' => '托管模式（推荐）'],
            ['value' => 'non-interactive',  'label' => '非交互式'],
            ['value' => 'invisible',        'label' => '不可见'],
        ],
        'tip'     => '托管模式自动根据访客风险选择交互/非交互；非交互式无需用户操作但显示加载动画；不可见模式完全后台运行',
    ],
    'turnstile_appearance' => [
        'title'   => '外观模式',
        'type'    => 'select',
        'value'   => 'always',
        'options' => [
            ['value' => 'always',           'label' => '始终可见（默认）'],
            ['value' => 'execute',          'label' => '质询时可见'],
            ['value' => 'interaction-only', 'label' => '仅交互时可见（已验证访客跳过）'],
        ],
        'tip'     => 'interaction-only 模式下，已通过验证的访客不会看到任何验证组件，仅可疑流量触发交互质询',
    ],
    'turnstile_size' => [
        'title'   => 'Widget 尺寸',
        'type'    => 'select',
        'value'   => 'normal',
        'options' => [
            ['value' => 'normal',    'label' => '标准（300x65）'],
            ['value' => 'flexible',  'label' => '自适应宽度'],
            ['value' => 'compact',   'label' => '紧凑（150x140）'],
        ],
        'tip'     => '仅对托管和非交互式模式生效，不可见模式无视觉元素',
    ],

    // ===== hCAPTCHA 配置 =====
    'hcaptcha_group' => [
        'title' => 'hCAPTCHA 配置',
        'type'  => 'tip',
        'value' => '',
        'size'  => 300,
    ],
    'hcaptcha_sitekey' => [
        'title' => 'Site Key',
        'type'  => 'text',
        'value' => '',
        'tip'   => 'hCaptcha Dashboard 获取的 Site Key',
        'size'  => 300,
    ],
    'hcaptcha_secret' => [
        'title' => 'Secret Key',
        'type'  => 'text',
        'value' => '',
        'tip'   => 'hCaptcha Dashboard 获取的 Secret Key',
        'size'  => 300,
    ],
];
