<?php

namespace App\Support\Docs;

use Illuminate\Support\Str;

/**
 * Human summaries for OpenAPI operations, derived from route names
 * (e.g. "api.v1.products.index" -> "List products").
 */
final class RouteSummary
{
    private const OVERRIDES = [
        'plans' => 'List the active plans and whether registration is open (public)',
        'auth.register' => 'Register a merchant and create their store',
        'auth.login' => 'Log in',
        'auth.logout' => 'Log out (revokes the current token)',
        'auth.me' => 'Get the authenticated user',
        'auth.password.forgot' => 'Request a password reset email',
        'auth.password.reset' => 'Reset the password with a token',
        'auth.verification.verify' => 'Verify an email address (signed link)',
        'auth.verification.resend' => 'Resend the verification email',
        'dashboard.stats' => 'Dashboard statistics',
        'store.show' => 'Get the store',
        'store.update' => 'Update the store',
        'store.settings.show' => 'Get store settings',
        'store.settings.update' => 'Update store settings',
        'store.theme.show' => 'Get the selected theme and its configuration',
        'store.theme.update' => 'Select a theme and update its configuration',
        'store.logo.upload' => 'Upload the store logo',
        'store.logo.destroy' => 'Remove the store logo',
        'store.favicon.upload' => 'Upload the store favicon',
        'store.favicon.destroy' => 'Remove the store favicon',
        'media.replace' => 'Replace an image file',
        'domains.verify' => 'Run the DNS verification for a domain',
        'domains.primary' => 'Make a domain the primary domain',
        'orders.status' => 'Change an order\'s fulfilment status',
        'orders.refund' => 'Refund a payment (full or partial)',
        'products.inventory.index' => 'List stock movements for a product',
        'products.inventory.store' => 'Adjust stock for a product or variant',
        'payment-gateways.index' => 'List payment gateways',
        'payment-gateways.update' => 'Save payment gateway credentials',
        'payment-gateways.destroy' => 'Remove a payment gateway',
        'storefront.store' => 'Get the store resolved from the hostname (store, theme, configuration, public settings)',
        'storefront.categories' => 'List categories',
        'storefront.products.index' => 'List products',
        'storefront.products.show' => 'Get a product by slug',
        'storefront.cart.store' => 'Create a cart',
        'storefront.cart.show' => 'Get a cart (prices are recomputed from the catalogue)',
        'storefront.cart.items.store' => 'Add an item to a cart',
        'storefront.cart.items.update' => 'Change an item quantity',
        'storefront.cart.items.destroy' => 'Remove an item from a cart',
        'storefront.checkout' => 'Place an order from a cart',
        'storefront.payments.initialize' => 'Start (or resume) a payment for an order',
        'storefront.payments.verify' => 'Confirm a payment with the provider after the customer returns',
        'storefront.orders.track' => 'Track a guest order by order number and email',
        'storefront.newsletter' => 'Subscribe an email to the newsletter',
        'storefront.auth.register' => 'Register a customer account',
        'storefront.auth.login' => 'Log a customer in',
        'storefront.auth.logout' => 'Log a customer out',
        'storefront.auth.me' => 'Get the authenticated customer',
        'storefront.account.addresses' => 'List the customer\'s saved addresses',
        'storefront.account.addresses.store' => 'Save an address',
        'storefront.account.addresses.destroy' => 'Delete a saved address',
        'storefront.account.orders' => 'List the customer\'s orders',
        'storefront.account.orders.show' => 'Get one of the customer\'s orders',
        'webhooks.payments' => 'Receive a payment provider webhook',
        'admin.overview' => 'Platform overview statistics',
        'admin.tenants.suspend' => 'Suspend a tenant',
        'admin.tenants.activate' => 'Activate a tenant',
        'admin.tenants.subscription' => 'Assign a plan to a tenant',
        'admin.users.suspend' => 'Suspend a user',
        'admin.users.activate' => 'Reactivate a user',
        'admin.users.revoke' => 'Revoke all of a user\'s sessions',
        'admin.users.password-reset' => 'Send a password reset email to a user',
        'admin.domains.verify' => 'Re-run the DNS check for a domain',
        'admin.settings.show' => 'Get platform settings',
        'admin.settings.update' => 'Update platform settings',
    ];

    private const VERBS = ['index' => 'List', 'store' => 'Create', 'show' => 'Get', 'update' => 'Update', 'destroy' => 'Delete'];

    public static function for(?string $routeName): ?string
    {
        if ($routeName === null || ! str_starts_with($routeName, 'api.v1.')) {
            return null;
        }

        $name = substr($routeName, 7);

        if (isset(self::OVERRIDES[$name])) {
            return self::OVERRIDES[$name];
        }

        $parts = explode('.', $name);
        $verb = self::VERBS[array_pop($parts)] ?? null;
        $resource = array_pop($parts);

        if ($verb === null || $resource === null) {
            return null;
        }

        $label = str_replace('-', ' ', $resource);

        return $verb === 'List' ? "List {$label}" : $verb.' '.Str::singular($label);
    }
}
