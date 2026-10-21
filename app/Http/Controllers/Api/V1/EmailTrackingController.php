<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\EmailEngagementService;
use App\Support\Notifications\EmailTracking;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

#[Group('Email tracking', weight: 96)]
class EmailTrackingController extends Controller
{
    /** A transparent 1×1 GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private readonly EmailEngagementService $engagement) {}

    /**
     * The hidden image in a campaign email. It always answers with the picture, whether or not the
     * link was valid, so it reveals nothing about which recipients exist.
     */
    public function open(Request $request, string $token): Response
    {
        $id = EmailTracking::recipientId($token);

        if ($id !== null && ! $this->engagement->looksAutomated($request->userAgent()) && EmailCampaignRecipient::withoutTenantScope()->whereKey($id)->exists()) {
            $this->engagement->recordOpen($id);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * The button in a campaign email: count the click, then go to the address saved on the campaign.
     */
    public function click(Request $request, string $token): RedirectResponse
    {
        $id = EmailTracking::recipientId($token);
        $recipient = $id ? EmailCampaignRecipient::withoutTenantScope()->with(['campaign' => fn ($q) => $q->withoutGlobalScopes()])->find($id) : null;
        $destination = $recipient?->campaign?->button_url;

        abort_unless(is_string($destination) && preg_match('#^https://#i', $destination) === 1, 404);

        if (! $this->engagement->looksAutomated($request->userAgent())) {
            $this->engagement->recordClick($id);
        }

        return redirect()->away($destination, 302, ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
    }
}
