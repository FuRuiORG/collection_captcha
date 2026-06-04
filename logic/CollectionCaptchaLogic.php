<?php
namespace captcha\collection_captcha\logic;

use captcha\collection_captcha\CollectionCaptcha;
use captcha\collection_captcha\logic\GeetestLogic;
use captcha\collection_captcha\logic\TurnstileLogic;
use captcha\collection_captcha\logic\HcaptchaLogic;

/**
 * 验证码集合 - 核心调度逻辑
 * 根据配置的 captcha_type 分发到对应的验证码服务
 */
class CollectionCaptchaLogic
{
    /**
     * 获取当前启用的验证码类型
     */
    protected function getType()
    {
        $config = (new CollectionCaptcha())->Config();
        return $config['captcha_type'] ?? '';
    }

    /**
     * 获取完整配置
     */
    public function getConfig()
    {
        return (new CollectionCaptcha())->Config();
    }

    /**
     * 获取验证码HTML（前台/后台）
     * @param bool $is_admin 是否后台模式
     * @return string HTML片段
     */
    public function describe($is_admin = false)
    {
        $type = $this->getType();
        $config = $this->getConfig();

        if (empty($type)) {
            return $this->errorHtml('未配置验证码类型，请在后台插件设置中选择');
        }

        switch ($type) {
            case 'geetest':
                $logic = new GeetestLogic();
                return $logic->describe($config, $is_admin);
            case 'turnstile':
                $logic = new TurnstileLogic();
                return $logic->describe($config, $is_admin);
            case 'hcaptcha':
                $logic = new HcaptchaLogic();
                return $logic->describe($config, $is_admin);
            default:
                return $this->errorHtml('不支持的验证码类型: ' . $type);
        }
    }

    /**
     * 验证用户提交的验证码
     * @param array $param 用户提交参数（含 token/response 等）
     * @return array ['status'=>200|400, 'msg'=>'...']
     */
    public function verify($param)
    {
        $type = $this->getType();
        $config = $this->getConfig();

        if (empty($type)) {
            return ['status' => 400, 'msg' => '未配置验证码类型'];
        }

        switch ($type) {
            case 'geetest':
                $logic = new GeetestLogic();
                return $logic->verify($config, $param);
            case 'turnstile':
                $logic = new TurnstileLogic();
                return $logic->verify($config, $param);
            case 'hcaptcha':
                $logic = new HcaptchaLogic();
                return $logic->verify($config, $param);
            default:
                return ['status' => 400, 'msg' => '不支持的验证码类型'];
        }
    }

    /**
     * 错误提示HTML
     */
    protected function errorHtml($message)
    {
        return "<div style='color:red;padding:10px;'>{$message}</div>";
    }
}
