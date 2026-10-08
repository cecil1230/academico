<?php

return [
    'failed' => '用户名或密码错误。',
    'password' => '提供的密码不正确。',
    'throttle' => '登录尝试过于频繁，请在 :seconds 秒后再试。',

    // 微软 Entra ID SSO 专属提示
    'login_with_microsoft' => '使用 Microsoft 365 统一身份账号登录',
    'sso_failed' => '微软身份认证未通过: :reason',
    'sso_token_error' => '无法与微软身份服务完成令牌交换，请联系系统管理员。',
    'sso_no_email' => '微软账号未返回有效邮箱，无法匹配教务系统。',
    'sso_user_not_found' => '您的微软账号 (:email) 未在教务系统中登记。请联系教学管理办公室开通权限。',
    'account_disabled' => '您的教务系统账号已被停用，请联系管理员。',
    'or' => '或者使用本地账号',
];
