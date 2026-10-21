<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailSuppression;
use App\Models\Tenant;
use App\Support\Notifications\StoreMailContext;
use App\Support\Notifications\UnsubscribeLink;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

#[Group('Email unsubscribe', weight: 95)]
class UnsubscribeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Confirmation page opened from the email. Opening the link changes nothing (mail scanners
     * open links); the shopper has to press the button.
     */
    public function show(Request $request, Tenant $tenant): Response
    {
        $email = (string) $request->query('email');
        $token = (string) $request->query('token');

        if (! UnsubscribeLink::valid($tenant->getKey(), $email, $token)) {
            return $this->invalid($tenant);
        }

        return $this->page($tenant, 'Unsubscribe from reminder emails?', "Stop {$this->storeName($tenant)} sending cart reminders and other promotional emails to ".$this->mask($email).'. You will still get emails about orders you place.', [
            'action' => route('api.v1.unsubscribe.store', ['tenant' => $tenant->getKey()]),
            'email' => $email,
            'token' => $token,
        ]);
    }

    /**
     * Performs the unsubscribe: from the confirmation button, or straight from a mail app's
     * one-click "Unsubscribe" (RFC 8058), which posts to the same link.
     */
    public function store(Request $request, Tenant $tenant): Response
    {
        $email = mb_strtolower((string) ($request->input('email') ?? $request->query('email')));
        $token = (string) ($request->input('token') ?? $request->query('token'));

        if (! UnsubscribeLink::valid($tenant->getKey(), $email, $token)) {
            return $this->invalid($tenant);
        }

        $this->context->runAs($tenant, fn () => EmailSuppression::query()->firstOrCreate(['email' => $email, 'scope' => EmailSuppression::SCOPE_MARKETING]));

        return $this->page($tenant, 'You\'re unsubscribed', "{$this->storeName($tenant)} won't send promotional emails to ".$this->mask($email).' any more.');
    }

    private function invalid(Tenant $tenant): Response
    {
        return $this->page($tenant, 'This link isn\'t valid', 'The unsubscribe link is incomplete or was changed. Open it again from the original email.', status: 403);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function page(Tenant $tenant, string $title, string $message, array $extra = [], int $status = 200): Response
    {
        $mail = $this->context->runAs($tenant, fn () => StoreMailContext::current());

        return response()->view('unsubscribe.page', ['title' => $title, 'message' => $message, 'store' => $mail['store'], 'accent' => $mail['accent']] + $extra, $status)
            ->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    private function storeName(Tenant $tenant): string
    {
        return $this->context->runAs($tenant, fn () => StoreMailContext::current()['store']);
    }

    /** Shows enough of the address to recognise it, without echoing it in full. */
    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }
}
