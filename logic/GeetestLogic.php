<?php
namespace captcha\collection_captcha\logic;

/**
 * Geetest v4（极验第四代）验证码逻辑
 *
 * 文档: https://docs.geetest.com/gt4/apirefer/api/web
 *
 * 流程：
 * 1. 前端加载 gt4.js，调用 initGeetest4() 初始化
 * 2. 用户完成验证后，onSuccess 回调返回 captchaObj
 * 3. 通过 captchaObj.getValidate() 获取验证结果（含 pass_token，single-use）
 * 4. 前端将结果直接传给表单 → 框架 check_captcha() 调用 verify()
 * 5. 后端生成 HMAC-SHA256 签名，调用 validate 接口二次验证（唯一消费点）
 */
class GeetestLogic
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
        $captchaId = $config['geetest_captcha_id'] ?? '';

        if (empty($captchaId)) {
            return "<div style='color:red;padding:10px;'>请先在后台配置 Geetest Captcha ID</div>";
        }

        // 语言映射
        $language = $this->getLanguage();

        if ($is_admin) {
            return $this->buildAdminHtml($captchaId, $language);
        } else {
            return $this->buildClientHtml($captchaId, $language);
        }
    }

    /**
     * 服务端二次验证
     *
     * 注意：Geetest pass_token 是单次使用的，
     * 此方法由框架 check_captcha() 在登录等操作时统一调用，
     * 是 token 的唯一消费点。
     *
     * @param array $config 插件配置
     * @param array $param 前端提交参数
     * @return array ['status'=>200|400, 'msg'=>'...']
     */
    public function verify($config, $param)
    {
        $captchaKey = $config['geetest_captcha_key'] ?? '';
        $captchaId  = $config['geetest_captcha_id'] ?? '';

        $this->writeLog('verify 开始', [
            'has_key'   => !empty($captchaKey),
            'has_id'    => !empty($captchaId),
            'param_keys' => array_keys($param),
            'ip'        => $this->getClientIp(),
        ]);

        if (empty($captchaKey) || empty($captchaId)) {
            return ['status' => 400, 'msg' => 'Geetest 配置不完整'];
        }

        // 兼容两种参数格式：
        // 方式1（框架 check_captcha 调用）: $param = ['captcha'=>'lot_number', 'token'=>'{JSON}', 'base'=>false]
        //   框架 common.php 中 check_captcha() 将 captchaCheckSuccsss 的两个参数包装为 captcha/token
        // 方式2（直接 AJAX 调用）: $param = {lot_number, pass_token, gen_time, captcha_output}
        if (!empty($param['token']) && is_string($param['token']) && isset($param['captcha'])) {
            $decoded = json_decode($param['token'], true);
            if ($decoded && is_array($decoded)) {
                // token 是 JSON 字符串，解析后合并；captcha 字段作为 lot_number
                $param = array_merge($decoded, ['lot_number' => $param['captcha']]);
            } else {
                // token 不是有效 JSON，尝试直接用 captcha 作为 lot_number
                $param['lot_number'] = $param['captcha'];
            }
        }

        // 提取验证结果字段
        $lotNumber     = $param['lot_number'] ?? '';
        $passToken     = $param['pass_token'] ?? '';
        $genTime       = $param['gen_time'] ?? '';
        $captchaOutput = $param['captcha_output'] ?? '';

        if (empty($lotNumber) || empty($passToken) || empty($genTime) || empty($captchaOutput)) {
            $this->writeLog('verify 失败', ['reason' => '参数不完整', 'param' => $param]);
            return ['status' => 400, 'msg' => '验证参数不完整'];
        }

        // Step 1: 本地生成签名 token（HMAC-SHA256，message=lot_number, key=captcha_key）
        // 参考: http://docs.geetest.com/gt4/deploy/server/
        $signToken = hash_hmac('sha256', $lotNumber, $captchaKey);

        // Step 2: 直接调用 validate 接口二次验证（form-urlencoded 格式，captcha_id 放 URL query）
        $result = $this->validateCaptcha([
            'lot_number'     => $lotNumber,
            'captcha_output' => $captchaOutput,
            'pass_token'     => $passToken,
            'gen_time'       => $genTime,
            'sign_token'     => $signToken,
        ], $captchaId);

        $this->writeLog('Geetest validate 返回', [
            'result'     => $result['result'] ?? null,
            'reason'     => $result['reason'] ?? '',
            'error_code' => $result['error_code'] ?? '',
        ]);

        if ($result && isset($result['result']) && $result['result'] === 'success') {
            $this->writeLog('verify 成功');
            return ['status' => 200, 'msg' => '验证成功'];
        }

        $reason = ($result['reason'] ?? '') . ' - ' . ($result['error_code'] ?? '');
        $this->writeLog('verify 失败', ['reason' => trim($reason . ' - ')]);
        return ['status' => 400, 'msg' => '验证失败: ' . trim($reason . ' - ')];
    }

    /**
     * 调用 Geetest validate 接口进行二次验证
     * @param array $params 验证参数（不含 captcha_id）
     * @param string $captchaId 验证 ID（放在 URL query 中，方便异常排查）
     * @return array|null
     */
    protected function validateCaptcha($params, $captchaId)
    {
        $url = 'https://gcaptcha4.geetest.com/validate?captcha_id=' . urlencode($captchaId);

        // 官方要求 application/x-www-form-urlencoded 格式
        $response = $this->httpPost($url, http_build_query($params), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        return json_decode($response, true);
    }

    /**
     * HTTP POST 请求
     * @param string $url 请求地址
     * @param string $data POST 数据
     * @param array $headers 请求头
     * @return string 响应体
     */
    protected function httpPost($url, $data, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->writeLog('CURL 错误', ['url' => $url, 'error' => $error]);
            return json_encode(['result' => 'fail', 'reason' => 'curl_error: ' . $error]);
        }
        curl_close($ch);
        return $result;
    }

    /**
     * 构建后台验证码HTML
     *
     * 关键设计：checkCaptchaFn 不再提前 AJAX 调用 verify 接口，
     * 而是直接将验证结果通过 captchaCheckSuccsss 写入表单，
     * 由框架在提交 login 时统一做唯一一次二次验证。
     *
     * @param string $captchaId 极验 Captcha ID
     * @param string $language 语言代码
     * @return string HTML+JS
     */
    protected function buildAdminHtml($captchaId, $language)
    {
        return "
<style>
    #admin-captcha .geetest-wrapper{ display:flex;align-items:center;width:100%; }
</style>
<div id='admin-captcha'>
    <div class='geetest-wrapper'>
        <div id='geetest-admin-container'></div>
    </div>
</div>

<script src='https://static.geetest.com/v4/gt4.js'></script>
<script>
var geetestResult = null;

(function tryAdminInit(retryCount) {
    if (typeof initGeetest4 !== 'undefined') {
        doAdminInit();
    } else if (retryCount < 50) {
        setTimeout(function() { tryAdminInit(retryCount + 1); }, 100);
    }
})(0);

function doAdminInit() {
    initGeetest4({
        captchaId: '" . $captchaId . "',
        product: 'bind',
        language: '" . $language . "'
    }, function(captchaObj){
        var container = document.getElementById('geetest-admin-container');
        if (container && !container.hasChildNodes()) {
            captchaObj.appendTo('#geetest-admin-container');
        }
        captchaObj.onSuccess(function(){
            geetestResult = captchaObj.getValidate();
            console.log('[Geetest] admin 验证完成, lot_number:', geetestResult.lot_number);
        });
        captchaObj.onError(function(e){
            console.warn('[Geetest] admin error:', e);
            geetestResult = null;
        });
    });
}

// 框架调用的验证入口 —— 直接将结果交给表单，不提前消费
window.checkCaptchaFn = async () => {
    return new Promise((resolve, reject) => {
        if (!geetestResult) {
            resolve({ success: false, msg: '请先完成验证' });
            return;
        }
        // 直接将验证结果交给框架表单，由 login 提交时统一二次验证（避免 pass_token 双重消费）
        captchaCheckSuccsss(true, geetestResult.lot_number, JSON.stringify(geetestResult));
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
     * 关键设计：验证完成后通过轮询检测到结果，直接调用 captchaCheckSuccsss 写入表单，
     * 不再提前调 verify 接口。Pass_token 的唯一消费点在框架 check_captcha() → verify()。
     *
     * @param string $captchaId 极验 Captcha ID
     * @param string $language 语言代码
     * @return string HTML+JS
     */
    protected function buildClientHtml($captchaId, $language)
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
        <div id='geetest-client-container'></div>
        <div class='captcha-footer' style='display:flex;justify-content:flex-end;margin-top:15px;gap:10px;'>
            <button id='cancel-btn' style='padding:8px 24px;border:none;background:#E7E7E7;color:#1E2736;border-radius:4px;cursor:pointer;'>取消</button>
        </div>
        <p id='captcha-error-text' style='color:red;font-size:14px;margin-top:10px;'></p>
    </div>
</div>

<script src='https://static.geetest.com/v4/gt4.js'></script>
<script>
var geetestClientResult = null;

// 策略：不依赖 script 标签的 onload（动态注入时不可靠）
// 直接检测 initGeetest4 是否已就绪，支持 gt4.js 已缓存/预加载的场景
(function tryInitGeetest(retryCount) {
    if (typeof initGeetest4 !== 'undefined') {
        doInit();
    } else if (retryCount < 50) {
        setTimeout(function() { tryInitGeetest(retryCount + 1); }, 100);
    }
})(0);

function doInit() {
    initGeetest4({
        captchaId: '" . $captchaId . "',
        product: 'popup',
        language: '" . $language . "'
    }, function(captchaObj){
        var container = document.getElementById('geetest-client-container');
        if (container && !container.hasChildNodes()) {
            captchaObj.appendTo('#geetest-client-container');
        }
        captchaObj.onSuccess(function(){
            geetestClientResult = captchaObj.getValidate();
            console.log('[Geetest] client 验证完成, lot_number:', geetestClientResult.lot_number);
        });
        captchaObj.onError(function(e){
            console.warn('[Geetest] client error:', e);
            var errEl = document.getElementById('captcha-error-text');
            if (errEl) errEl.textContent = '验证出错，请重试';
            geetestClientResult = null;
        });
    });
}

document.getElementById('cancel-btn').onclick = function() {
    var errEl = document.getElementById('captcha-error-text');
    if (errEl) errEl.textContent = '';
    captchaCheckCancel();
};

// 验证完成后直接通知框架（不自行调 verify，避免 pass_token 被双重消费）
// 框架会在用户提交登录时通过 check_captcha() 统一调用一次 verify
var _checkInterval = setInterval(function(){
    if(geetestClientResult){
        clearInterval(_checkInterval);
        // 直接回调框架，将 lot_number 和完整结果 JSON 传给 captchaDialog
        captchaCheckSuccsss(true, geetestClientResult.lot_number, JSON.stringify(geetestClientResult));
    }
}, 500);
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
     * 获取语言代码
     * @return string 语言代码（zho/eng 等）
     */
    protected function getLanguage()
    {
        return 'zho';
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
            $logFile = __DIR__ . '/../geetest_debug.log';
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
