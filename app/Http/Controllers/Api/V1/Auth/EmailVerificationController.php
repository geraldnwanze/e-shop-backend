<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

#[Group('Authentication', weight: 1)]
class EmailVerificationController extends Controller
{
    /**
     * Target of the signed link in the verification email.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->where('id', $id)->first();

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new BaseApiException(ApiErrorCodeEnum::InvalidSignature, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->away(rtrim(config('tenancy.frontend_url'), '/').'/email-verified');
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return ApiResponse::noContent('Verification email sent.');
    }
}
