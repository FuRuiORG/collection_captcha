<?php
namespace captcha\collection_captcha;

use app\common\lib\Plugin;
use captcha\collection_captcha\logic\CollectionCaptchaLogic;
use think\facade\Db;

/**
 * 验证码集合插件 - 支持 Geetest v4 / Turnstile / hCAPTCHA
 * @author RuiNexus
 * @version 1.0.0
 */
class CollectionCaptcha extends Plugin
{
    public $info = array(
        'name'        => 'CollectionCaptcha',
        'title'       => '验证码集合',
        'description' => '支持 Geetest v4、Turnstile、hCAPTCHA 三种验证码服务',
        'author'      => 'RuiNexus',
        'version'     => '1.2.0',
    );

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    /**
     * 获取前台验证码HTML
     */
    public function CollectionCaptchaDescribe()
    {
        $logic = new CollectionCaptchaLogic();
        return $logic->describe();
    }

    /**
     * 获取后台验证码HTML
     */
    public function CollectionCaptchaDescribeAdmin()
    {
        $logic = new CollectionCaptchaLogic();
        return $logic->describe(true);
    }

    /**
     * 验证用户输入
     */
    public function CollectionCaptchaVerify($param)
    {
        $logic = new CollectionCaptchaLogic();
        return $logic->verify($param);
    }

    /**
     * 获取插件配置（合并默认值）
     */
    public function Config()
    {
        $config = Db::name('plugin')->where('name', $this->info['name'])->value('config');
        if (!empty($config) && $config != "null") {
            $config = json_decode($config, true);
        } else {
            $config = [];
        }
        return $config;
    }
}
