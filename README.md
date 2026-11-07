# collection_captcha

魔方业务系统的验证码聚合插件，可在极验v4、Cloudflare Turnstile、hCAPTCHA之间切换。

## 安装

把`collection_captcha`目录放到：

```
public/plugins/captcha/collection_captcha/
```

在后台插件管理中安装并启用，然后进入插件设置填写对应平台的密钥。

## 配置

| 配置项 | 说明 |
| --- | --- |
| 验证码类型 | `geetest`、`turnstile`、`hcaptcha` |
| Site Key/Captcha ID | 各平台公开标识 |
| Secret Key/Captcha Key | 各平台服务端密钥 |

Turnstile额外支持managed、non-interactive、invisible三种Widget模式，以及always、execute、interaction-only三种外观模式。

## 流程

Token随表单提交，由框架的`check_captcha()`进入插件`verify()`，服务端只消费一次，避免single-use token被重复校验。

## 接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/captcha/collection_captcha/index/verify` | 验证码校验 |
| GET | `/captcha/collection_captcha/index/refresh` | 刷新验证码 |

## 调试

各Logic类顶部的`DEBUG_LOG`改为`true`后会输出请求参数与返回结果。

## License

MIT，见`LICENSE`。
