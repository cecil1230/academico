<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class AzureAuthController extends Controller
{
    /**
     * 重定向到微软 Entra ID (Azure AD) 进行 OAuth2 认证
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('azure')
            ->scopes(['openid', 'profile', 'email', 'offline_access'])
            ->redirect();
    }

    /**
     * 处理微软 Entra ID 认证后的回调
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            Log::warning('Azure SSO Auth Cancelled or Failed', [
                'error' => $request->get('error'),
                'description' => $request->get('error_description'),
            ]);

            return redirect()->route('login')->withErrors([
                'email' => __('auth.sso_failed', ['reason' => $request->get('error_description', '认证已取消')]),
            ]);
        }

        try {
            $azureUser = Socialite::driver('azure')->user();
        } catch (\Exception $e) {
            Log::error('Azure SSO Token Exchange Error: ' . $e->getMessage());

            return redirect()->route('login')->withErrors([
                'email' => __('auth.sso_token_error', ['default' => '无法从微软身份服务获取认证令牌，请联系系统管理员。']),
            ]);
        }

        // 提取微软用户的主邮箱 (优先 user.mail，其次 userPrincipalName)
        $rawEmail = $azureUser->getEmail() ?? $azureUser->user['mail'] ?? $azureUser->user['userPrincipalName'] ?? null;

        if (empty($rawEmail)) {
            Log::error('Azure SSO: No email returned in claims', ['azure_id' => $azureUser->getId()]);

            return redirect()->route('login')->withErrors([
                'email' => __('auth.sso_no_email', ['default' => '微软账号未返回有效邮箱，无法匹配教务系统账号。']),
            ]);
        }

        $normalizedEmail = strtolower(trim($rawEmail));

        // 在教务系统中查找对应用户 (白名单匹配原则，防止外部未知微软账号登录)
        $user = User::where('email', $normalizedEmail)->first();

        if (! $user) {
            Log::warning("Azure SSO: User not registered in Academico: {$normalizedEmail}");

            return redirect()->route('login')->withErrors([
                'email' => __('auth.sso_user_not_found', [
                    'email' => $normalizedEmail,
                    'default' => "邮箱 [{$normalizedEmail}] 未在教务系统中登记。请联系教学管理办公室开通账号。",
                ]),
            ]);
        }

        // 检查账号状态 (是否被禁用或软删除)
        if ($user->trashed()) {
            return redirect()->route('login')->withErrors([
                'email' => __('auth.account_disabled', ['default' => '您的教务系统账号已被停用，请联系管理员。']),
            ]);
        }

        // 执行本地登录认证
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // 存储 Entra ID 的 id_token (若存在，供单点登出 SLO 使用)
        if (isset($azureUser->token)) {
            $request->session()->put('azure_access_token', $azureUser->token);
        }

        Log::info("User successfully logged in via Azure SSO: {$user->email} (ID: {$user->id})");

        // 智能角色分流路由
        return $this->smartRedirect($user);
    }

    /**
     * 单点登出 (SLO: Single Logout)
     * 先销毁本地 Session，然后重定向至微软 Entra ID 注销会话
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $postLogoutRedirectUri = urlencode(url('/'));
        $tenant = config('services.azure.tenant', 'common');

        $azureLogoutUrl = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/logout?post_logout_redirect_uri={$postLogoutRedirectUri}";

        return redirect()->away($azureLogoutUrl);
    }

    /**
     * 根据 Academico 真实角色模型进行页面分流
     */
    protected function smartRedirect(User $user): RedirectResponse
    {
        // 1. 如果是学生，重定向至学生前台 Livewire 仪表盘
        if ($user->isStudent()) {
            return redirect()->intended(route('student.dashboard'));
        }

        // 2. 如果是管理员、秘书、阅览员或教师，重定向至 Filament 管理后台
        $adminPanel = Filament::getPanel('admin');
        if ($adminPanel && $user->canAccessPanel($adminPanel)) {
            return redirect()->intended('/admin');
        }

        // 3. 兜底回首页
        return redirect()->intended('/');
    }
}
