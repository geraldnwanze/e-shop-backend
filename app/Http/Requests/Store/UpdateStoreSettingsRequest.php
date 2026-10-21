<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\Concerns\ValidatesSafeUrls;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreSettingsRequest extends FormRequest
{
    use ValidatesSafeUrls;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Keep only the digits of the WhatsApp number, so "+234 801-234 5678" is stored as 2348012345678 (the wa.me format).
     */
    protected function prepareForValidation(): void
    {
        $number = $this->input('whatsapp.number');

        if (is_string($number)) {
            $digits = preg_replace('/\D+/', '', $number);
            $this->merge(['whatsapp' => array_merge((array) $this->input('whatsapp'), ['number' => $digits === '' ? null : $digits])]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contact' => ['sometimes', 'array:email,phone,address'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:30'],
            'contact.address' => ['nullable', 'string', 'max:500'],
            'seo' => ['sometimes', 'array:title,description,og_image'],
            'seo.title' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:300'],
            'seo.og_image' => ['nullable', 'string', 'max:2048', $this->httpsUrl()],
            'checkout' => ['sometimes', 'array:allow_guest_checkout,require_phone,shipping_flat_rate'],
            'checkout.shipping_flat_rate' => ['integer', 'min:0', 'max:100000000'],
            'checkout.allow_guest_checkout' => ['boolean'],
            'checkout.require_phone' => ['boolean'],
            'reviews' => ['sometimes', 'array:enabled,require_approval'],
            'reviews.enabled' => ['boolean'],
            'reviews.require_approval' => ['boolean'],
            'inventory' => ['sometimes', 'array:low_stock_notice'],
            'inventory.low_stock_notice' => ['integer', 'min:0', 'max:100'],
            'whatsapp' => ['sometimes', 'array:enabled,number,message'],
            'whatsapp.enabled' => ['boolean'],
            'whatsapp.number' => ['nullable', 'required_if_accepted:whatsapp.enabled', 'regex:/^\d{8,15}$/'],
            'whatsapp.message' => ['nullable', 'string', 'max:300'],
            'notifications' => ['sometimes', 'array:customer_order_confirmation,customer_order_updates,customer_payment_receipt,customer_refund,merchant_new_order,merchant_payment_failed,merchant_low_stock,merchant_new_review,merchant_review_digest,merchant_campaign_failure,campaign_tracking,low_stock_threshold,merchant_email,abandoned_cart_reminder,abandoned_cart_delay_hours,abandoned_cart_second_reminder,abandoned_cart_second_delay_hours,abandoned_cart_discount_percent,review_request,review_request_delay_days'],
            'notifications.customer_order_confirmation' => ['boolean'],
            'notifications.customer_order_updates' => ['boolean'],
            'notifications.customer_payment_receipt' => ['boolean'],
            'notifications.customer_refund' => ['boolean'],
            'notifications.merchant_new_order' => ['boolean'],
            'notifications.merchant_payment_failed' => ['boolean'],
            'notifications.merchant_low_stock' => ['boolean'],
            'notifications.merchant_new_review' => ['boolean'],
            'notifications.merchant_review_digest' => ['boolean'],
            'notifications.merchant_campaign_failure' => ['boolean'],
            'notifications.campaign_tracking' => ['boolean'],
            'notifications.low_stock_threshold' => ['integer', 'min:0', 'max:100000'],
            'notifications.abandoned_cart_reminder' => ['boolean'],
            'notifications.abandoned_cart_delay_hours' => ['integer', 'min:1', 'max:72'],
            'notifications.abandoned_cart_second_reminder' => ['boolean'],
            'notifications.abandoned_cart_second_delay_hours' => ['integer', 'min:1', 'max:168'],
            'notifications.abandoned_cart_discount_percent' => ['integer', 'min:0', 'max:50'],
            'notifications.review_request' => ['boolean'],
            'notifications.review_request_delay_days' => ['integer', 'min:1', 'max:30'],
            'notifications.merchant_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
