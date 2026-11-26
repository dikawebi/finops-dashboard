<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $throttleKey = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $message = 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($throttleKey).' detik.';
            return $request->expectsJson()
                ? response()->json(['message' => $message], 429)
                : back()->withErrors(['email' => $message])->onlyInput('email');
        }

        if (! Auth::attempt([...$credentials, 'is_active' => true], (bool) $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);
            return $request->expectsJson()
                ? response()->json(['message' => 'Email atau kata sandi tidak benar.'], 422)
                : back()->withErrors(['email' => 'Email atau kata sandi tidak benar.'])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $dashboardUrl = route('dashboard', [
            'period' => now('Asia/Jakarta')->format('Y-m'),
            'mode' => 'mtd',
        ]);
        return $request->expectsJson()
            ? response()->json(['success' => true, 'user' => $request->user(), 'redirect' => $dashboardUrl])
            : redirect()->to($dashboardUrl);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return $request->expectsJson()
            ? response()->json(['success' => true])
            : redirect()->route('login')->with('status', 'Anda sudah keluar dari dashboard.');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'different:current_password'],
        ]);

        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();

        return response()->json(['success' => true, 'message' => 'Kata sandi berhasil diperbarui.']);
    }
}
