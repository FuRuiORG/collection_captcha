<?php
namespace captcha\collection_captcha\controller;

use app\home\controller\BaseController;
use captcha\collection_captcha\logic\CollectionCaptchaLogic;

/**
 * 验证码集合 - HTTP 接口控制器
 * 提供 verify 和 refresh 接口供前端调用
 */
class IndexController extends BaseController
{
    /**
     * 验证码验证接口
     * POST /captcha/collection_captcha/index/verify
     */
    public function verify()
    {
        // 校验当前启用的验证码插件是否为自身
        if (configuration('captcha_plugin') !== 'CollectionCaptcha') {
            return json([
                'status' => 400,
                'msg'    => '请求错误',
            ]);
        }

        $param = $_POST;

        $logic = new CollectionCaptchaLogic();
        $result = $logic->verify($param);

        return json($result);
    }

    /**
     * 刷新验证码接口（预留，用于 Geetest 重置等场景）
     * GET /captcha/collection_captcha/index/refresh
     */
    public function refresh()
    {
        if (configuration('captcha_plugin') !== 'CollectionCaptcha') {
            return json([
                'status' => 400,
                'msg'    => '请求错误',
            ]);
        }

        // 对于 Turnstile/hCAPTCHA，刷新由前端 widget 自行处理
        // Geetest 可通过此接口通知前端重新 init
        return json([
            'status'  => 200,
            'msg'     => '请重新加载验证码组件',
            'action'  => 'reload',
        ]);
    }
}
