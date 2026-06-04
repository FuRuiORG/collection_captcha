<?php
namespace captcha\collection_captcha\logic;

/**
 * Cloudflare Turnstile 验证码逻辑
 *
 * 文档: https://developers.cloudflare.com/turnstile/
 *
 * 支持三种 Widget 模式：
 * - managed（托管，推荐）：自动根据访客风险选择交互/非交互
 * - non-interactive（非交互式）：显示加载动画，无需用户操作
 * - invisible（不可见）：完全后台运行
 *
 * 支持三种外观模式：
 * - always：始终可见
 * - execute：质询时可见
 * - interaction-only：仅交互时可见（已验证访客跳过质询）
 *
 * 流程：
 * 1. 前端加载 turnstile api.js（显式渲染），渲染 widget
 * 2. 用户通过验证后，callback 返回 token → 直接传给表单（不提前验证）
 * 3. 用户提交登录/操作 → 框架 check_captcha() 调用 verify()
 * 4. 后端 POST 到 siteverify API 验证 token（唯一一次消费）
 */
class TurnstileLogic
{
    /**
     * 是否启用调试日志（设为 false 禁用）
     */
    const DEBUG_LOG = false;

    /**
     * 生成前端验证码HTML
     */
    public function describe($config, $is_admin = false)
    {
        $sitekey = $config['turnstile_sitekey'] ?? '';

        if (empty($sitekey)) {
            return "<div style='color:red;padding:10px;'>请先在后台配置 Turnstile Site Key</div>";
        }

        if ($is_admin) {
            return $this->buildAdminHtml($sitekey, $config);
        } else {
            return $this->buildClientHtml($sitekey, $config);
        }
    }

    /**
     * 服务端验证 Turnstile token
     *
     * 注意：Turnstile token 是单次使用的（single-use），
     * 此方法由框架 check_captcha() 在登录等操作时统一调用，
     * 是 token 的唯一消费点。
     */
    public function verify($config, $param)
    {
        $secret = $config['turnstile_secret'] ?? '';
        // 框架通过 check_captcha() 传入的参数格式：
        // ['captcha' => $token, 'token' => $token, 'base' => false]
        // captcha 和 token 字段都携带同一个 Turnstile token
        $token  = $param['token'] ?? $param['captcha'] ?? $param['cf-turnstile-response'] ?? '';

        $this->writeLog('verify 开始', [
            'has_secret' => !empty($secret),
            'has_token'  => !empty($token),
            'token_len'  => strlen($token),
            'param_keys' => array_keys($param),
            'ip'         => $this->getClientIp(),
        ]);

        if (empty($secret)) {
            $this->writeLog('verify 失败', ['reason' => 'secret 为空']);
            return ['status' => 400, 'msg' => 'Turnstile Secret Key 未配置'];
        }

        if (empty($token)) {
            $this->writeLog('verify 失败', ['reason' => 'token 为空', 'param' => $param]);
            return ['status' => 400, 'msg' => '验证码 token 为空'];
        }

        $postData = http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $this->getClientIp(),
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno) {
            $this->writeLog('verify CURL 错误', [
                'errno' => $curlErrno,
                'error' => $curlError,
            ]);
            return ['status' => 400, 'msg' => '验证服务连接失败: ' . $curlError];
        }

        $result = json_decode($response, true);

        $this->writeLog('CF siteverify 返回', [
            'success'     => $result['success'] ?? null,
            'error-codes' => $result['error-codes'] ?? [],
            'hostname'    => $result['hostname'] ?? '',
        ]);

        if (isset($result['success']) && $result['success'] === true) {
            $this->writeLog('verify 成功');
            return ['status' => 200, 'msg' => '验证成功'];
        }

        $errors = isset($result['error-codes']) ? implode(', ', $result['error-codes']) : '未知错误';
        $this->writeLog('verify 失败', ['errors' => $errors]);
        return ['status' => 400, 'msg' => '验证失败: ' . $errors];
    }

    /**
     * 从配置中获取 widget 参数
     */
    protected function getWidgetParams($config)
    {
        return [
            'mode'       => $config['turnstile_mode'] ?? 'managed',
            'appearance' => $config['turnstile_appearance'] ?? 'always',
            'size'       => $config['turnstile_size'] ?? 'normal',
            'theme'      => $this->getTheme(),
        ];
    }

    /**
     * 构建后台验证码HTML
     *
     * 关键设计：checkCaptchaFn 不再提前 AJAX 调用 verify 接口，
     * 而是直接将 Turnstile token 通过 captchaCheckSuccsss 写入表单，
     * 由框架在提交 login 时统一做唯一一次 token 验证。
     */
    protected function buildAdminHtml($sitekey, $config)
    {
        $params = $this->getWidgetParams($config);
        $mode = $params['mode'];
        $appearance = $params['appearance'];
        $size = $params['size'];
        $theme = $params['theme'];

        $isInvisible = ($mode === 'invisible');

        $containerStyle = $isInvisible
            ? "style='width:0;height:0;overflow:hidden;'"
            : "style='display:flex;align-items:center;width:100%;'";

        return "
<style>
    #admin-captcha .turnstile-wrapper{ {$containerStyle} }
</style>
<div id='admin-captcha'>
    <div class='turnstile-wrapper'>
        <div id='turnstile-admin-container'></div>
    </div>
</div>

<script src='https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit' async defer></script>
<script>
var turnstileAdminToken = null;
var turnstileAdminWidgetId = null;

function onTurnstileAdminSuccess(token) {
    turnstileAdminToken = token;
}

function onTurnstileAdminError(errorCode) {
    console.warn('[Turnstile] admin error:', errorCode);
    turnstileAdminToken = null;
}

function onTurnstileAdminExpired() {
    console.warn('[Turnstile] admin token expired');
    turnstileAdminToken = null;
}

function onTurnstileAdminTimeout() {
    console.warn('[Turnstile] admin timeout');
    turnstileAdminToken = null;
    if (typeof turnstile !== 'undefined' && turnstileAdminWidgetId !== null) {
        turnstile.reset(turnstileAdminWidgetId);
    }
}

// 显式渲染
function renderAdminTurnstile() {
    if (typeof turnstile === 'undefined') {
        setTimeout(renderAdminTurnstile, 100);
        return;
    }
    turnstileAdminWidgetId = turnstile.render('#turnstile-admin-container', {
        sitekey: '{$sitekey}',
        theme: '{$theme}',
        mode: '{$mode}',
        appearance: '{$appearance}',
        size: '{$size}',
        callback: onTurnstileAdminSuccess,
        'error-callback': onTurnstileAdminError,
        'expired-callback': onTurnstileAdminExpired,
        'timeout-callback': onTurnstileAdminTimeout,
        'refresh-expired': 'auto',
        'refresh-timeout': 'auto'
    });
}

// 框架调用的验证入口 —— 直接将 token 交给表单，不提前消费
window.checkCaptchaFn = async () => {
    return new Promise((resolve, reject) => {
        if (!turnstileAdminToken) {
            resolve({ success: false, msg: '请等待验证完成' });
            return;
        }
        // 直接将 token 写入框架表单，由 login 提交时统一验证（single-use）
        captchaCheckSuccsss(true, turnstileAdminToken, turnstileAdminToken);
        resolve({ success: true });
    });
};

// 页面加载后渲染
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderAdminTurnstile);
} else {
    renderAdminTurnstile();
}
</script>
";
    }

    /**
     * 构建前台验证码HTML
     *
     * 关键设计：callback 成功后直接调用 captchaCheckSuccsss 将 token 写入表单，
     * 不再提前 AJAX 调用 verify 接口消费 token。
     * Token 的唯一消费点在框架 check_captcha() → verify() 中。
     */
    protected function buildClientHtml($sitekey, $config)
    {
        $params = $this->getWidgetParams($config);
        $mode = $params['mode'];
        $appearance = $params['appearance'];
        $size = $params['size'];
        $theme = $params['theme'];

        $isInvisible = ($mode === 'invisible');

        // 不可见模式：不显示弹窗容器，widget 隐藏在页面中自动完成
        if ($isInvisible) {
            return "
<div id='turnstile-client-container' style='position:fixed;left:-9999px;top:-9999px;'></div>

<script src='https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit' async defer></script>
<script>
var turnstileClientToken = null;
var turnstileClientWidgetId = null;

function onTurnstileClientSuccess(token) {
    turnstileClientToken = token;
    // 不可见模式：拿到 token 后立即交给框架表单，不提前验证
    captchaCheckSuccsss(true, turnstileClientToken, turnstileClientToken);
}

function onTurnstileClientError(errorCode) {
    console.warn('[Turnstile] client error:', errorCode);
    turnstileClientToken = null;
}

function onTurnstileClientExpired() {
    turnstileClientToken = null;
}

function onTurnstileClientTimeout() {
    turnstileClientToken = null;
    if (typeof turnstile !== 'undefined' && turnstileClientWidgetId !== null) {
        turnstile.reset(turnstileClientWidgetId);
    }
}

// 显式渲染
function renderClientTurnstile() {
    if (typeof turnstile === 'undefined') {
        setTimeout(renderClientTurnstile, 100);
        return;
    }
    turnstileClientWidgetId = turnstile.render('#turnstile-client-container', {
        sitekey: '{$sitekey}',
        theme: '{$theme}',
        mode: '{$mode}',
        appearance: '{$appearance}',
        size: '{$size}',
        callback: onTurnstileClientSuccess,
        'error-callback': onTurnstileClientError,
        'expired-callback': onTurnstileClientExpired,
        'timeout-callback': onTurnstileClientTimeout,
        'refresh-expired': 'auto',
        'refresh-timeout': 'auto'
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderClientTurnstile);
} else {
    renderClientTurnstile();
}
</script>
";
        }

        // 可见模式（托管/非交互式）：显示弹窗容器
        return "
<div id='captcha-outer' style='
    background: rgba(123,123,123,0.6);
    position: fixed;
    left: 0px; top: 0px;
    height: 100vh; width: 100vw;
    z-index: 3000;
'>
    <div class='captcha-content' style='
        background:#fff;
        position:fixed;
        left:50%;top:50%;
        transform:translate(-50%,-50%);
        padding:25px;
        border-radius:8px;
        min-width:320px;
        text-align:center;
    '>
        <div style='font-size:16px;font-weight:bold;margin-bottom:18px;' class='captcha-title'>安全验证</div>
        <div id='turnstile-client-container'></div>
        <div class='captcha-footer' style='display:flex;justify-content:flex-end;margin-top:15px;gap:10px;'>
            <button id='cancel-btn' style='padding:8px 24px;border:none;background:#E7E7E7;color:#1E2736;border-radius:4px;cursor:pointer;'>取消</button>
        </div>
        <p id='captcha-error-text' style='color:red;font-size:14px;margin-top:10px;'></p>
    </div>
</div>

<script src='https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit' async defer></script>
<script>
var turnstileClientToken = null;
var turnstileClientWidgetId = null;

function onTurnstileClientSuccess(token) {
    turnstileClientToken = token;
    // 验证通过后直接交给框架表单，不提前 AJAX 验证（避免 single-use token 双重消费）
    captchaCheckSuccsss(true, turnstileClientToken, turnstileClientToken);
    // 短暂延迟后关闭弹窗
    setTimeout(function(){
        var outer = document.getElementById('captcha-outer');
        if (outer) outer.style.display = 'none';
    }, 500);
}

function onTurnstileClientError(errorCode) {
    console.warn('[Turnstile] client error:', errorCode);
    var errEl = document.getElementById('captcha-error-text');
    if (errEl) errEl.textContent = '验证出错，请重试';
    turnstileClientToken = null;
}

function onTurnstileClientExpired() {
    turnstileClientToken = null;
    var errEl = document.getElementById('captcha-error-text');
    if (errEl) errEl.textContent = '验证已过期，请重新验证';
}

function onTurnstileClientTimeout() {
    turnstileClientToken = null;
    var errEl = document.getElementById('captcha-error-text');
    if (errEl) errEl.textContent = '验证超时，请重试';
    if (typeof turnstile !== 'undefined' && turnstileClientWidgetId !== null) {
        turnstile.reset(turnstileClientWidgetId);
    }
}

document.getElementById('cancel-btn').onclick = function() {
    var errEl = document.getElementById('captcha-error-text');
    if (errEl) errEl.textContent = '';
    captchaCheckCancel();
};

// 显式渲染
function renderClientTurnstile() {
    if (typeof turnstile === 'undefined') {
        setTimeout(renderClientTurnstile, 100);
        return;
    }
    turnstileClientWidgetId = turnstile.render('#turnstile-client-container', {
        sitekey: '{$sitekey}',
        theme: '{$theme}',
        mode: '{$mode}',
        appearance: '{$appearance}',
        size: '{$size}',
        callback: onTurnstileClientSuccess,
        'error-callback': onTurnstileClientError,
        'expired-callback': onTurnstileClientExpired,
        'timeout-callback': onTurnstileClientTimeout,
        'refresh-expired': 'auto',
        'refresh-timeout': 'auto'
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderClientTurnstile);
} else {
    renderClientTurnstile();
}
</script>
";
    }

    /**
     * 获取客户端IP
     */
    protected function getClientIp()
    {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '127.0.0.1';
    }

    /**
     * 获取主题（适配亮色/暗色）
     */
    protected function getTheme()
    {
        return 'auto'; // auto/light/dark
    }

    /**
     * 写入调试日志
     */
    protected function writeLog($action, $data = [])
    {
        if (!self::DEBUG_LOG) {
            return;
        }
        try {
            $logFile = __DIR__ . '/../turnstile_debug.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $entry = date('Y-m-d H:i:s') . ' [' . $action . ']';
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                $entry .= ' ' . $k . '=' . $v;
            }
            $entry .= PHP_EOL;
            @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // 日志写入失败不影响主流程
        }
    }
}
