<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Согласие с правилами пользования, cookie и обработкой персональных данных.
 *
 * Браузер помнит согласие в cookie (окно больше не показывается), а у вошедшего
 * пользователя оно ещё и записывается в базу: редакция правил, время и IP.
 */
class ConsentController extends Controller
{
    /** Нужно ли показать окно согласия на этой странице. */
    public static function required(Request $request): bool
    {
        $version = config('legal.version');

        if ($request->cookie(config('legal.cookie')) !== $version) {
            return true;
        }

        $user = $request->user();

        return $user instanceof User && $user->consent_version !== $version;
    }

    /**
     * Записать согласие пользователя в базу.
     * Согласие, данное в этом браузере до входа, на учётку не переносится: за общим
     * компьютером его мог дать другой человек, поэтому после входа окно покажется ещё раз.
     */
    public static function record(User $user, Request $request): void
    {
        $user->forceFill([
            'consent_version' => config('legal.version'),
            'consent_at'      => now(),
            'consent_ip'      => $request->ip(),
        ])->saveQuietly();
    }

    public function rules()
    {
        return view('legal.rules');
    }

    public function accept(Request $request)
    {
        $request->validate(['accept' => ['accepted']], ['accept.accepted' => 'Отметьте согласие с правилами.']);

        if ($user = $request->user()) {
            self::record($user, $request);
        }

        // Возврат только на свой сайт
        $back = url()->previous();
        if (! str_starts_with($back, url('/'))) {
            $back = url('/');
        }

        return redirect($back)->withCookie(cookie(
            config('legal.cookie'),
            config('legal.version'),
            config('legal.cookie_days') * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }
}
