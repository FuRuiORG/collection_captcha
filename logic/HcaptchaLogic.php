<?php
namespace captcha\collection_captcha\logic;

/**
 * hCAPTCHA 验证码逻辑
 *
 * 文档: https://docs.hcaptcha.com/
 *
 * 流程：
 * 1. 前端加载 hcaptcha api.js（隐式渲染），渲染 widget
 * 2. 用户通过验证后，callback 返回 token（single-use）
 * 3. 前端将 token 直接传给表单 → 框架 check_captcha() 调用 verify()
 * 4. 后端 POST 到 siteverify API 验证 token（唯一消费点）
 */
class HcaptchaLogic
{
    /**
     * 是否启用调试日志（设为 false 禁用）
     */
    const DEBUG_LOG = false;

    /**
     * 生成前端验证码HTML
     * @param array $config 插件配置
     * @param bool $is_admin 是否后台
     * @return string HTML+JS
     */
    public function describe($config, $is_admin = false)
    {
        $sitekey = $config['hcaptcha_sitekey'] ?? '';

        if (empty($sitekey)) {
            return "<div style='color:red;padding:10px;'>请先在后台配置 hCAPTCHA Site Key</div>";
        }

        if ($is_admin) {
            return $this->buildAdminHtml($sitekey);
        } else {
            return $this->buildClientHtml($sitekey);
        }
    }

    /**
     * 服务端验证 hCAPTCHA token
     *
     * 注意：hCAPTCHA token 是单次使用的（single-use），
     * 此方法由框架 check_captcha() 在登录等操作时统一调用，
     * 是 token 的唯一消费点。
     *
     * @param array $config 插件配置
     * @param array $param 前端提交参数
     * @return array ['status'=>200|400, 'msg'=>'...']
     */
    public function verify($config, $param)
    {
        $secret = $config['hcaptcha_secret'] ?? '';
        // 框架通过 check_captcha() 传入的参数格式：
        // ['captcha' => $token, 'token' => $token, 'base' => false]
        // 同时兼容直接 AJAX 调用的格式：['h-captcha-response' => $token]
        $token  = $param['token'] ?? $param['captcha'] ?? $param['h-captcha-response'] ?? '';

        $this->writeLog('verify 开始', [
            'has_secret' => !empty($secret),
            'has_token'  => !empty($token),
            'token_len'  => strlen($token),
            'ip'         => $this->getClientIp(),
        ]);

        if (empty($secret)) {
            return ['status' => 400, 'msg' => 'hCAPTCHA Secret Key 未配置'];
        }

        if (empty($token)) {
            $this->writeLog('verify 失败', ['reason' => 'token 为空']);
            return ['status' => 400, 'msg' => '验证码 token 为空'];
        }

        $postData = http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $this->getClientIp(),
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.hcaptcha.com/siteverify');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno) {
            $this->writeLog('CURL 错误', ['errno' => $curlErrno, 'error' => $curlError]);
            return ['status' => 400, 'msg' => '验证服务连接失败: ' . $curlError];
        }

        $result = json_decode($response, true);

        $this->writeLog('hCAPTCHA siteverify 返回', [
            'success'      => $result['success'] ?? null,
            'error-codes'  => $result['error-codes'] ?? [],
            'hostname'     => $result['hostname'] ?? '',
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
     * 构建后台验证码HTML
     *
     * 关键设计：checkCaptchaFn 不再提前 AJAX 调用 verify 接口，
     * 而是直接将 hCAPTCHA token 通过 captchaCheckSuccsss 写入表单，
     * 由框架在提交 login 时统一做唯一一次 token 验证。
     *
     * @param string $sitekey hCAPTCHA 站点密钥
     * @return string HTML+JS
     */
    protected function buildAdminHtml($sitekey)
    {
        return "
<style>
    #admin-captcha .hcaptcha-wrapper{ display:flex;align-items:center;width:100%; }
</style>
<div id='admin-captcha'>
    <div class='hcaptcha-wrapper'>
        <div id='hcaptcha-admin-container' class='h-captcha'
             data-sitekey='" . $sitekey . "'
             data-theme='" . $this->getTheme() . "'
             data-callback='onHcaptchaAdminSuccess'></div>
    </div>
</div>

<script src='https://js.hcaptcha.com/1/api.js' async defer></script>
<script>
var hcaptchaAdminToken = null;

function onHcaptchaAdminSuccess(token) {
    hcaptchaAdminToken = token;
    console.log('[hCAPTCHA] admin 验证完成');
}

// 框架调用的验证入口 —— 直接将 token 交给表单，不提前消费
window.checkCaptchaFn = async () => {
    return new Promise((resolve, reject) => {
        if (!hcaptchaAdminToken) {
            resolve({ success: false, msg: '请等待验证完成' });
            return;
        }
        // 直接将 token 写入框架表单，由 login 提交时统一验证（single-use）
        captchaCheckSuccsss(true, hcaptchaAdminToken, hcaptchaAdminToken);
        resolve({ success: true });
    });
};
</script>
<script src='/plugins/captcha/collection_captcha/captcha-watchdog.js'></script>
";
    }

    /**
     * 构建前台验证码HTML
     *
     * 关键设计：callback 成功后直接调用 captchaCheckSuccsss 将 token 写入表单，
     * 不再提前 AJAX 调用 verify 接口消费 token。
     * Token 的唯一消费点在框架 check_captcha() → verify() 中。
     *
     * @param string $sitekey hCAPTCHA 站点密钥
     * @return string HTML+JS
     */
    protected function buildClientHtml($sitekey)
    {
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
        <div id='hcaptcha-client-container' class='h-captcha'
             data-sitekey='" . $sitekey . "'
             data-theme='" . $this->getTheme() . "'
             data-callback='onHcaptchaClientSuccess'></div>
        <div class='captcha-footer' style='display:flex;justify-content:flex-end;margin-top:15px;gap:10px;'>
            <button id='cancel-btn' style='padding:8px 24px;border:none;background:#E7E7E7;color:#1E2736;border-radius:4px;cursor:pointer;'>取消</button>
        </div>
        <p id='captcha-error-text' style='color:red;font-size:14px;margin-top:10px;'></p>
    </div>
</div>

<script src='https://js.hcaptcha.com/1/api.js' async defer></script>
<script>
var hcaptchaClientToken = null;

function onHcaptchaClientSuccess(token) {
    hcaptchaClientToken = token;
    console.log('[hCAPTCHA] client 验证完成');
    // 验证通过后直接交给框架表单，不提前 AJAX 验证（避免 single-use token 双重消费）
    captchaCheckSuccsss(true, hcaptchaClientToken, hcaptchaClientToken);
    // 短暂延迟后关闭弹窗
    setTimeout(function(){
        var outer = document.getElementById('captcha-outer');
        if (outer) outer.style.display = 'none';
    }, 500);
}

document.getElementById('cancel-btn').onclick = function() {
    var errEl = document.getElementById('captcha-error-text');
    if (errEl) errEl.textContent = '';
    captchaCheckCancel();
};
</script>
<script src='/plugins/captcha/collection_captcha/captcha-watchdog.js'></script>
";
    }

    /**
     * 获取客户端IP
     * @return string IP 地址
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
     * @return string 主题值（light/dark/auto）
     */
    protected function getTheme()
    {
        return 'auto';
    }

    /**
     * 写入调试日志
     * @param string $action 操作描述
     * @param array $data 附加数据
     */
    protected function writeLog($action, $data = [])
    {
        if (!self::DEBUG_LOG) {
            return;
        }
        try {
            $logFile = __DIR__ . '/../hcaptcha_debug.log';
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
