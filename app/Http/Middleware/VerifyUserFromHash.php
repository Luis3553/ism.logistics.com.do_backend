<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ProGpsApiService;
use Closure;
use Illuminate\Http\Request;

class VerifyUserFromHash
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $userHash = $request->header('X-Hash-Token');
        if (!$userHash) return response()->json(['message' => 'Missing X-Hash-Token header'], 400);

        $user = User::where('hash', $userHash)->first();

        if (!$user) {
            $apiService = new ProGpsApiService($userHash);
            $userInfo = $apiService->getUserInfo();

            if (!$userInfo || !isset($userInfo['user_info']['id'])) return response()->json(['message' => 'User id of the hash provided doesnt exist on the platform.'], 401);

            $user = User::firstOrCreate([
                'user_id' => $userInfo['user_info']['id'],
                'hash' => $userHash,
            ]);
        }

        $request->attributes->set('user', $user);

        return $next($request);
    }
}
