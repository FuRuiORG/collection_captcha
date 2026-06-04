/**
 * Captcha Watchdog - 验证码插件自动检测与修复脚本
 *
 * 作者：RuiNexus
 * 功能：
 *   1. 检测验证码容器空白，自动重新初始化
 *   2. 处理 Geetest popup 模式弹窗冲突
 *   3. 确保验证完成后回调链正确闭合
 *   4. 通用容错：超时、网络错误、重复初始化防护
 *
 * 使用方式：在验证码 HTML 中通过 <script src='captcha-watchdog.js'></script> 引入
 * 依赖：jQuery（页面全局）、框架定义的 captchaCheckSuccsss / captchaCancel 全局函数
 */
(function () {
    'use strict';

    // ============================================================
    // 配置项
    // ============================================================
    var CONFIG = {
        // 容器检测间隔（ms）
        POLL_INTERVAL: 300,
        // 容器空白后等待多久开始重试（ms）—— 给原始初始化留时间
        RETRY_DELAY: 2000,
        // 最大重试次数
        MAX_RETRIES: 5,
        // 重试间隔（ms）
        RETRY_INTERVAL: 1000,
        // 验证结果轮询间隔（ms）
        VERIFY_POLL_INTERVAL: 500,
        // 验证超时（ms）—— 超过此时间未完成验证则报错
        VERIFY_TIMEOUT: 120000,
        // MutationObserver 检测 DOM 变化
        USE_MUTATION_OBSERVER: true,
        // 日志前缀
        LOG_PREFIX: '[CaptchaWatchdog]',
    };

    // ============================================================
    // 状态管理
    // ============================================================
    var _state = {
        initialized: false,
        retryCount: 0,
        verifyPollTimer: null,
        verifyStartTime: 0,
        retryTimer: null,
        observer: null,
        lastContainerHtml: '',
        // 记录是否已经成功回调过，防止重复调用
        successCalled: false,
        // 记录当前模式
        productMode: 'unknown',
    };

    // ============================================================
    // 日志工具
    // ============================================================
    function log(msg) {
        console.log(CONFIG.LOG_PREFIX + ' ' + msg);
    }
    function warn(msg) {
        console.warn(CONFIG.LOG_PREFIX + ' ' + msg);
    }
    function error(msg) {
        console.error(CONFIG.LOG_PREFIX + ' ' + msg);
    }

    // ============================================================
    // 工具函数
    // ============================================================

    /**
     * 安全获取 DOM 元素
     */
    function $(selector) {
        return document.querySelector(selector);
    }

    /**
     * 判断元素是否存在且可见（非 display:none）
     */
    function isElementVisible(el) {
        if (!el) return false;
        var style = window.getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    }

    /**
     * 检测容器是否有实际内容（非空白）
     */
    function hasContent(container) {
        if (!container) return false;
        // 有子节点
        if (container.hasChildNodes && container.hasChildNodes()) {
            // 进一步检查子节点是否有实际尺寸（排除隐藏的）
            var children = container.children;
            for (var i = 0; i < children.length; i++) {
                var child = children[i];
                if (child.offsetWidth > 0 || child.offsetHeight > 0) {
                    return true;
                }
            }
            // 即使没有尺寸但有子节点也算有内容（可能在渲染中）
            return children.length > 0;
        }
        // innerHTML 有内容也算
        return (container.innerHTML && container.innerHTML.trim().length > 0);
    }

    /**
     * 检测全局函数是否存在
     */
    function globalExists(name) {
        return typeof window[name] !== 'undefined';
    }

    /**
     * 延迟执行
     */
    function delay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    // ============================================================
    // 核心检测模块
    // ============================================================

    /**
     * 获取当前页面的验证码容器信息
     * 返回 { container, containerId, context: 'client'|'admin', resultVar, cancelBtnId }
     */
    function detectContext() {
        // 前台客户端
        var clientContainer = $('#geetest-client-container');
        if (clientContainer) {
            return {
                container: clientContainer,
                containerId: 'geetest-client-container',
                context: 'client',
                resultVar: 'geetestClientResult',
                outerId: 'captcha-outer',
                errorTextId: 'captcha-error-text',
                cancelBtnId: 'cancel-btn',
            };
        }
        // 后台管理
        var adminContainer = $('#geetest-admin-container');
        if (adminContainer) {
            return {
                container: adminContainer,
                containerId: 'geetest-admin-container',
                context: 'admin',
                resultVar: 'geetestResult',
                outerId: null,
                errorTextId: null,
                cancelBtnId: null,
            };
        }
        return null;
    }

    /**
     * 检测当前使用的验证码类型
     * 返回 'geetest' | 'turnstile' | 'hcaptcha' | 'unknown'
     */
    function detectCaptchaType() {
        if (globalExists('initGeetest4')) return 'geetest';
        if ($('.cf-turnstile')) return 'turnstile';
        if ($('[data-sitekey][data-captcha="hcaptcha"]') || $('.h-captcha')) return 'hcaptcha';
        // 通过容器 ID 推断
        if ($('#geetest-client-container') || $('#geetest-admin-container')) return 'geetest';
        return 'unknown';
    }

    /**
     * 检测 Geetest 当前 product 模式
     */
    function detectProductMode() {
        // 检查 DOM 中是否有 geetest_bind 或 geetest_popup class 的元素
        var bindEl = document.querySelector('.geetest_bind');
        var popupEl = document.querySelector('.geetest_popup');
        if (bindEl) return 'bind';
        if (popupEl) return 'popup';
        // 默认根据上下文判断：前台用 popup，后台用 bind
        var ctx = detectContext();
        if (ctx && ctx.context === 'client') return 'popup';
        if (ctx && ctx.context === 'admin') return 'bind';
        return 'unknown';
    }

    // ============================================================
    // 修复动作
    // ============================================================

    /**
     * 修复 #1：容器空白 → 重新初始化 Geetest
     * 当检测到容器存在但长时间无内容时触发
     */
    function fixEmptyContainer(ctx) {
        if (_state.retryCount >= CONFIG.MAX_RETRIES) {
            warn('已达最大重试次数 (' + CONFIG.MAX_RETRIES + ')，停止重试');
            showError(ctx, '验证码加载失败，请刷新页面重试');
            return;
        }

        _state.retryCount++;
        log('检测到容器空白，第 ' + _state.retryCount + ' 次尝试重新初始化...');

        var captchaType = detectCaptchaType();

        if (captchaType === 'geetest') {
            fixGeetestEmpty(ctx);
        } else {
            warn('未知的验证码类型，无法自动修复: ' + captchaType);
        }
    }

    /**
     * Geetest 特殊修复逻辑
     */
    function fixGeetestEmpty(ctx) {
        // 步骤 1：确认 gt4.js 和 initGeetest4 是否就绪
        if (!globalExists('initGeetest4')) {
            log('initGeetest4 尚未就绪，等待 gt4.js 加载...');
            // gt4.js 可能没加载成功，尝试重新加载
            reloadGt4Script(ctx);
            return;
        }

        // 步骤 2：检查是否已有 Geetest 渲染痕迹但被隐藏
        var hiddenWidget = findHiddenGeetestWidget();
        if (hiddenWidget) {
            log('发现被隐藏的 Geetest widget，尝试恢复显示');
            recoverHiddenWidget(hiddenWidget);
            return;
        }

        // 步骤 3：尝试直接重新调用 doInit（如果存在）
        if (globalExists('doInit')) {
            log('调用 doInit() 重新初始化...');
            try {
                window.doInit();
            } catch (e) {
                error('doInit() 调用失败: ' + e.message);
            }
            return;
        }

        // 步骤 4：手动构造 initGeetest4 调用
        log('doInit 不存在，尝试手动重新初始化...');
        manualReinitGeetest(ctx);
    }

    /**
     * 重新加载 gt4.js
     */
    function reloadGt4Script(ctx) {
        var existingScript = document.querySelector('script[src*="gt4.js"]');
        if (existingScript) {
            // 已有 script 标签但可能加载失败，加时间戳强制重新加载
            var newScript = document.createElement('script');
            var src = existingScript.src;
            // 加时间戳破缓存
            if (src.indexOf('?') === -1) {
                src += '?_t=' + Date.now();
            } else {
                src += '&_t=' + Date.now();
            }
            newScript.src = src;
            newScript.onload = function () {
                log('gt4.js 重新加载成功');
                // 等一小段让 initGeetest4 注册完成
                setTimeout(function () {
                    if (globalExists('doInit')) {
                        window.doInit();
                    } else if (globalExists('initGeetest4')) {
                        manualReinitGeetest(ctx);
                    }
                }, 200);
            };
            newScript.onerror = function () {
                error('gt4.js 重新加载失败');
                showError(ctx, '验证码脚本加载失败，请检查网络');
            };
            existingScript.parentNode.replaceChild(newScript, existingScript);
        } else {
            // 没有 script 标签，创建新的
            var script = document.createElement('script');
            script.src = 'https://static.geetest.com/v4/gt4.js?_t=' + Date.now();
            script.onload = function () {
                log('gt4.js 加载成功');
                setTimeout(function () {
                    if (globalExists('doInit')) window.doInit();
                    else if (globalExists('initGeetest4')) manualReinitGeetest(ctx);
                }, 200);
            };
            document.head.appendChild(script);
        }
    }

    /**
     * 查找被隐藏的 Geetest widget
     */
    function findHiddenGeetestWidget() {
        // Geetest v4 会创建 id 为 geetest_captcha_xxx 的元素
        var widgets = document.querySelectorAll('[id^="geetest_captcha_"]');
        for (var i = 0; i < widgets.length; i++) {
            var w = widgets[i];
            if (!isElementVisible(w)) {
                return w;
            }
        }
        return null;
    }

    /**
     * 恢复被隐藏的 widget 显示
     */
    function recoverHiddenWidget(widget) {
        // 移除 display:none
        widget.style.display = '';
        widget.style.visibility = 'visible';
        widget.style.opacity = '1';

        // 如果是 bind 模式且在 body 下，尝试移入正确的容器
        var ctx = detectContext();
        if (ctx && ctx.container) {
            var isInContainer = ctx.container.contains(widget) ||
                                ctx.container.querySelector('[id^="geetest_captcha_"]');
            if (!isInContainer && widget.parentNode) {
                log('将 Geetest widget 从 ' + widget.parentNode.id + ' 移入 ' + ctx.containerId);
                try {
                    ctx.container.appendChild(widget);
                } catch (e) {
                    warn('移动 widget 失败: ' + e.message);
                }
            }
        }
    }

    /**
     * 手动重新初始化 Geetest（当 doInit 不存在时）
     * 需要从页面中提取 captchaId 和 language
     */
    function manualReinitGeetest(ctx) {
        // 尝试从现有 script 内容中提取配置
        var scripts = document.querySelectorAll('script');
        var captchaId = '';
        var language = 'zho';

        for (var i = 0; i < scripts.length; i++) {
            var text = scripts[i].textContent || '';
            var match = text.match(/captchaId:\s*['"]([^'"]+)['"]/);
            if (match) {
                captchaId = match[1];
            }
            var langMatch = text.match(/language:\s*['"]([^'"]+)['"]/);
            if (langMatch) {
                language = langMatch[1];
            }
        }

        if (!captchaId) {
            error('无法提取 captchaId，无法手动重新初始化');
            return;
        }

        log('手动重新初始化 Geetest (captchaId: ' + captchaId + ', language: ' + language + ')');

        try {
            window.initGeetest4({
                captchaId: captchaId,
                product: ctx.context === 'client' ? 'popup' : 'bind',
                language: language,
            }, function (captchaObj) {
                var container = document.getElementById(ctx.containerId);
                if (container) {
                    // 清空旧内容再 append
                    if (container.hasChildNodes()) {
                        container.innerHTML = '';
                    }
                    captchaObj.appendTo('#' + ctx.containerId);
                    log('Geetest 手动重新初始化 - widget 已渲染到容器');
                }

                // 注册 onSuccess 回调
                captchaObj.onSuccess(function () {
                    var result = captchaObj.getValidate();
                    if (ctx.context === 'client') {
                        window.geetestClientResult = result;
                    } else {
                        window.geetestResult = result;
                    }
                    log('Geetest 验证成功（watchdog 拦截）');
                });
            });
        } catch (e) {
            error('手动重新初始化失败: ' + e.message);
        }
    }

    /**
     * 修复 #2：popup 模式弹窗冲突处理
     * popup 模式下 Geetest 创建自己的全屏遮罩，验证完后关闭自己的遮罩，
     * 但我们的 captcha-outer 还残留着
     */
    function fixPopupConflict(ctx) {
        if (ctx.context !== 'client') return; // 仅前台需要

        var outer = $('#' + ctx.outerId);
        if (!outer) return;

        // 监听 Geetest 弹窗关闭 —— 通过检测 Geetest 的遮罩元素消失来判断
        startPopupWatcher(ctx);
    }

    /**
     * 启动 popup 模式 watcher
     */
    function startPopupWatcher(ctx) {
        // Geetest popup 模式会创建 class 包含 geetest_popup 的遮罩层
        // 当它消失时说明用户完成了验证或关闭了验证
        var checkPopup = setInterval(function () {
            var geetestOverlay = document.querySelector('.geetest_popup');
            var geetestBox = document.querySelector('.geetest_box_wrap');

            // Geetest 弹窗已关闭 + 我们的外层弹窗还在 + 有验证结果
            if (!geetestOverlay && !geetestBox &&
                $('#' + ctx.outerId) &&
                (window.geetestClientResult || window[ctx.resultVar])) {

                clearInterval(checkPopup);
                log('检测到 Geetest popup 已关闭且有验证结果，触发验证流程');

                handleVerifySuccess(ctx);
            }
        }, CONFIG.VERIFY_POLL_INTERVAL);

        // 存储定时器以便清理
        _state.verifyPollTimer = checkPopup;
        _state.verifyStartTime = Date.now();

        // 超时保护
        setTimeout(function () {
            if (_state.verifyPollTimer === checkPopup) {
                clearInterval(checkPopup);
                _state.verifyPollTimer = null;
                warn('验证流程超时 (' + (CONFIG.VERIFY_TIMEOUT / 1000) + '秒)');
            }
        }, CONFIG.VERIFY_TIMEOUT);
    }

    /**
     * 修复 #3：确保验证完成后回调链闭合
     * 统一入口处理验证成功后的所有逻辑
     */
    function handleVerifySuccess(ctx) {
        if (_state.successCalled) {
            log('验证成功回调已执行过，跳过重复调用');
            return;
        }

        var result = window[ctx.resultVar];
        if (!result) {
            warn('验证结果为空，无法提交');
            return;
        }

        _state.successCalled = true;
        log('验证完成，通知框架（不自行调 verify，避免 pass_token 双重消费）');

        // 清除错误提示
        if (ctx.errorTextId) {
            var errEl = document.getElementById(ctx.errorTextId);
            if (errEl) errEl.textContent = '';
        }
        // 直接通知框架，让框架在提交登录时统一调用一次 verify
        if (globalExists('captchaCheckSuccsss')) {
            window.captchaCheckSuccsss(true, result.lot_number, JSON.stringify(result));
        }
    }

    /**
     * 显示错误信息
     */
    function showError(ctx, msg) {
        if (ctx.errorTextId) {
            var el = document.getElementById(ctx.errorTextId);
            if (el) el.textContent = msg;
        }
        // 同时 console 输出
        error(msg);
    }

    // ============================================================
    // 监控引擎
    // ============================================================

    /**
     * 主监控循环
     * 定期检查容器状态，发现问题则触发修复
     */
    function startMonitoring() {
        var ctx = detectContext();
        if (!ctx) {
            // 容器还没出现，延迟重试
            setTimeout(startMonitoring, CONFIG.POLL_INTERVAL);
            return;
        }

        log('监控启动 - 容器: #' + ctx.containerId + ', 上下文: ' + ctx.context);
        _state.initialized = true;

        // 记录初始状态
        _state.lastContainerHtml = ctx.container ? ctx.container.innerHTML : '';

        // 方案 A：MutationObserver（更精准）
        if (CONFIG.USE_MUTATION_OBSERVER && typeof MutationObserver !== 'undefined') {
            startObserver(ctx);
        }

        // 方案 B：定时轮询（兜底）
        startPolling(ctx);

        // popup 模式特殊处理
        _state.productMode = detectProductMode();
        if (_state.productMode === 'popup' && ctx.context === 'client') {
            fixPopupConflict(ctx);
        }
    }

    /**
     * MutationObserver 监控 DOM 变化
     */
    function startObserver(ctx) {
        _state.observer = new MutationObserver(function (mutations) {
            // 只关注子树变化
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === 'childList' || m.type === 'characterData') {
                    onDomChanged(ctx);
                    break; // 一个变化就够了
                }
            }
        });

        // 监控容器本身和其父级（因为 innerHTML 替换可能导致容器重建）
        var target = ctx.container;
        if (target) {
            _state.observer.observe(target, {
                childList: true,
                subtree: true,
                characterData: true,
            });
            log('MutationObserver 已挂载到 #' + ctx.containerId);
        }
    }

    /**
     * DOM 变化时的回调
     */
    function onDomChanged(ctx) {
        if (!ctx.container) return;

        var currentHtml = ctx.container.innerHTML;

        // HTML 发生了变化
        if (currentHtml !== _state.lastContainerHtml) {
            _state.lastContainerHtml = currentHtml;
            log('DOM 变化检测: 容器内容已更新 (长度: ' + currentHtml.length + ')');

            // 检查更新后是否仍然为空
            setTimeout(function () {
                if (!hasContent(ctx.container)) {
                    warn('DOM 更新后容器仍为空，准备重试...');
                    scheduleRetry(ctx);
                } else {
                    log('容器已有内容，渲染正常');
                    _state.retryCount = 0; // 重置重试计数
                }
            }, CONFIG.RETRY_DELAY);
        }
    }

    /**
     * 定时轮询兜底方案
     * MutationObserver 可能遗漏某些场景（如 jQuery .html() 整体替换）
     */
    function startPolling(ctx) {
        var pollCount = 0;
        var maxPolls = Math.ceil(CONFIG.RETRY_DELAY / CONFIG.POLL_INTERVAL) + CONFIG.MAX_RETRIES + 5;

        var timer = setInterval(function () {
            pollCount++;

            if (!ctx.container || !document.body.contains(ctx.container)) {
                // 容器已被销毁（如弹窗关闭），停止监控
                clearInterval(timer);
                log('容器已移除，停止监控');
                cleanup();
                return;
            }

            // 检查内容
            if (hasContent(ctx.container)) {
                // 有内容了，看看是不是真的渲染好了
                if (pollCount > 3) {
                    // 连续多次检测都有内容，认为稳定
                    _state.retryCount = 0;
                }
            } else {
                // 空白
                if (pollCount * CONFIG.POLL_INTERVAL >= CONFIG.RETRY_DELAY) {
                    // 已经等了足够长的时间
                    warn('轮询检测: 容器持续空白 (第 ' + pollCount + ' 次)');
                    scheduleRetry(ctx);
                }
            }

            if (pollCount >= maxPolls) {
                clearInterval(timer);
                log('轮询达到上限，停止定时检测（MutationObserver 仍在运行）');
            }
        }, CONFIG.POLL_INTERVAL);
    }

    /**
     * 安排重试（带防抖）
     */
    function scheduleRetry(ctx) {
        if (_state.retryTimer) return; // 防抖：已经在等待重试中

        _state.retryTimer = setTimeout(function () {
            _state.retryTimer = null;
            fixEmptyContainer(ctx);
        }, CONFIG.RETRY_INTERVAL);
    }

    // ============================================================
    // 事件拦截：增强原有回调
    // ============================================================

    /**
     * 拦截并增强 captchaCheckSuccsss
     * 确保即使原代码调用了这个函数，watchdog 也能感知
     */
    function hookGlobalCallbacks() {
        // 拦截 captchaCheckSuccsss —— 不覆盖，只是监听
        if (globalExists('captchaCheckSuccsss')) {
            var original = window.captchaCheckSuccsss;
            window.captchaCheckSuccsss = function (bol, captcha, token) {
                log('captchaCheckSuccsss 被调用 (bol=' + bol + ')');
                _state.successCalled = !!bol;
                // 调用原始函数
                return original.apply(window, arguments);
            };
            log('已挂钩 captchaCheckSuccsss');
        }

        // 拦截 captchaCancel
        if (globalExists('captchaCheckCancel')) {
            var origCancel = window.captchaCheckCancel;
            window.captchaCheckCancel = function () {
                log('captchaCheckCancel 被调用，清理 watchdog 状态');
                _state.successCalled = false;
                cleanup();
                return origCancel.apply(window, arguments);
            };
            log('已挂钩 captchaCheckCancel');
        }
    }

    // ============================================================
    // 清理
    // ============================================================
    function cleanup() {
        if (_state.verifyPollTimer) {
            clearInterval(_state.verifyPollTimer);
            _state.verifyPollTimer = null;
        }
        if (_state.retryTimer) {
            clearTimeout(_state.retryTimer);
            _state.retryTimer = null;
        }
        if (_state.observer) {
            _state.observer.disconnect();
            _state.observer = null;
        }
        _state.successCalled = false;
        log('Watchdog 已清理');
    }

    // ============================================================
    // 初始化入口
    // ============================================================
    function init() {
        log('Captcha Watchdog 初始化 v1.0.0 (by RuiNexus)');

        // 挂钩全局回调
        hookGlobalCallbacks();

        // 延迟一点启动监控，给原始脚本足够的初始化时间
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(startMonitoring, 500);
            });
        } else {
            setTimeout(startMonitoring, 500);
        }
    }

    // 启动
    init();

    // 暴露接口供外部调用（调试用）
    window.CaptchaWatchdog = {
        getState: function () { return _state; },
        detectContext: detectContext,
        detectCaptchaType: detectCaptchaType,
        fixEmptyContainer: function () { var ctx = detectContext(); if (ctx) fixEmptyContainer(ctx); },
        handleVerifySuccess: function () { var ctx = detectContext(); if (ctx) handleVerifySuccess(ctx); },
        cleanup: cleanup,
        VERSION: '1.0.0',
    };

})();
