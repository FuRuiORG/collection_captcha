# CollectionCaptcha - 验证码集合插件

> 支持 Geetest v4、Cloudflare Turnstile、hCAPTCHA 三种验证码服务的统一接入插件

**作者**：RuiNexus  
**版本**：1.2.0  
**框架**：魔方业务系统（ThinkPHP）  
**目录**：`public/plugins/captcha/collection_captcha/`

---

## 功能特性

- **三合一验证码**：Geetest v4（极验）、Turnstile（Cloudflare）、hCAPTCHA 一键切换
- **Turnstile 高级配置**：支持托管/非交互式/不可见三种 Widget 模式，以及 always/execute/interaction-only 三种外观模式
- **Single-use Token 设计**：所有验证码 token 仅在登录提交时消费一次，避免双重验证失败
- **调试日志**：三种验证码均内置可开关的调试日志，便于排查问题
- **前台/后台双端支持**：后台管理登录 + 前台用户登录统一适配

## 文件结构

```
collection_captcha/
├── CollectionCaptcha.php          # 插件入口（注册钩子、版本信息）
├── config.php                     # 后台配置表单定义
├── captcha-watchdog.js            # 前端超时/异常监控脚本
├── controller/
│   └── IndexController.php        # HTTP 接口（verify / refresh）
├── logic/
│   ├── CollectionCaptchaLogic.php # 核心调度器（按类型分发）
│   ├── GeetestLogic.php           # Geetest v4 逻辑
│   ├── TurnstileLogic.php         # Turnstile 逻辑
│   └── HcaptchaLogic.php          # hCAPTCHA 逻辑
└── lang/
    ├── zh-cn.php                  # 简体中文语言包
    ├── en-us.php                  # 英文语言包
    └── zh-hk.php                  # 繁体中文语言包
```

## 安装与配置

### 1. 安装

将 `collection_captcha` 目录放入 `public/plugins/captcha/` 下，在后台插件管理中安装并启用。

### 2. 后台配置

进入 **插件设置** → **CollectionCaptcha**：

| 配置项 | 说明 |
|--------|------|
| 验证码类型 | 选择 `geetest` / `turnstile` / `hcaptcha`，默认 Geetest |
| 各服务密钥 | 填写对应平台的 Site Key / Secret Key / Captcha ID 等 |

#### Turnstile 专属选项

| 配置项 | 可选值 | 说明 |
|--------|--------|------|
| Widget 模式 | managed / non-interactive / invisible | 托管模式推荐，自动选择交互策略 |
| 外观模式 | always / execute / interaction-only | interaction-only 已验证访客跳过质询 |
| Widget 尺寸 | normal / flexible / compact | 标准 / 自适应 / 紧凑 |

## 验证流程

```
用户操作 → 前端 Widget 验证 → 获取 Token → captchaCheckSuccsss() 写入表单
                                                    ↓
                              用户提交表单 → 框架 check_captcha()
                                                    ↓
                          插件 verify() → 对应平台 API 二次验证（唯一消费点）
                                                    ↓
                                          返回 status:200/400
```

**关键设计**：Token 不在前端提前 AJAX 验证，而是直接随表单提交给后端，由框架在登录时统一做唯一一次服务端校验。这避免了 single-use token 的双重消费问题。

## 支持的验证码服务

### Geetest v4（极验）

- **文档**：https://docs.geetest.com/gt4/apirefer/api/web
- **渲染方式**：`initGeetest4()` API 初始化
- **二次验证**：HMAC-SHA256 签名 + validate 接口
- **前台检测**：轮询 `setInterval` 检测验证结果
- **所需配置**：Captcha ID、Captcha Key

### Cloudflare Turnstile

- **文档**：https://developers.cloudflare.com/turnstile/
- **渲染方式**：显式渲染（`render=explicit` + `turnstile.render()`）
- **二次验证**：siteverify API（POST JSON）
- **Widget 模式**：managed / non-interactive / invisible
- **外观模式**：always / execute / interaction-only
- **所需配置**：Site Key、Secret Key

### hCAPTCHA

- **文档**：https://docs.hcaptcha.com/
- **渲染方式**：隐式渲染（`data-*` 属性自动渲染）
- **二次验证**：siteverify API（POST form-urlencoded）
- **所需配置**：Site Key、Secret Key

## 调试日志

各 Logic 类顶部均有 `DEBUG_LOG` 常量，设为 `true` 即可启用：

```php
// GeetestLogic.php / HcaptchaLogic.php / TurnstileLogic.php
const DEBUG_LOG = false;  // 改为 true 启用
```

日志文件位置：
- Geetest：`collection_captcha/geetest_debug.log`
- Turnstile：`collection_captcha/turnstile_debug.log`
- hCAPTCHA：`collection_captcha/hcaptcha_debug.log`

日志记录内容：请求参数、API 返回结果、错误详情等。

## API 接口

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/captcha/collection_captcha/index/verify` | 验证码校验 |
| GET | `/captcha/collection_captcha/index/refresh` | 刷新验证码（预留） |

## 版本历史

| 版本 | 变更说明 |
|------|---------|
| 1.2.0 | Turnstile 支持托管/非交互式/不可见切换及 appearance 模式；修复 single-use token 双重消费问题；统一三个插件的代码风格和调试日志 |
| 1.1.0 | 新增 Turnstile 支持 |
| 1.0.0 | 初始版本，支持 Geetest v4 和 hCAPTCHA |
