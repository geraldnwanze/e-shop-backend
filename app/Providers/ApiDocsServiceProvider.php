<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Docs\ApiResponseTypeExtension;
use App\Support\Docs\RouteSummary;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Interactive API documentation (Scramble) at /docs/api and /docs/api.json.
 * Visible in local development; in production only when API_DOCS_ENABLED=true.
 */
class ApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewApiDocs', fn (?User $user = null): bool => app()->isLocal() || (bool) config('app.api_docs_enabled'));

        // ApiResponse resolves resources itself (no implicit {"data": …} wrapper), so document them unwrapped.
        JsonResource::withoutWrapping();
        Scramble::registerExtension(ApiResponseTypeExtension::class);

        Scramble::configure()->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo): void {
            $summary = RouteSummary::for($routeInfo->route->getName());

            if ($summary !== null) {
                // The curated one-line summary wins; any longer controller docblock text becomes the description.
                $existing = $operation->summary;
                $operation->summary($summary);

                if (filled($existing) && $existing !== $summary && blank($operation->description)) {
                    $operation->description($existing);
                }
            }
        })->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }
}
