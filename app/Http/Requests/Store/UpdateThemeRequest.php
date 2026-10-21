<?php

namespace App\Http\Requests\Store;

use App\Enums\HomepageSectionType;
use App\Enums\ThemeStatus;
use App\Http\Requests\Concerns\ValidatesSafeUrls;
use App\Models\Theme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateThemeRequest extends FormRequest
{
    use ValidatesSafeUrls;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $font = ['nullable', 'string', Rule::in(config('themes.fonts'))];
        $link = ['nullable', 'string', 'max:2048', $this->pathOrHttpsUrl()];

        return [
            'theme_id' => ['sometimes', 'uuid', Rule::exists('themes', 'id')->where('status', ThemeStatus::Active->value)],

            'configuration' => ['sometimes', 'array:colors,fonts,hero,navigation,footer,social,homepage'],

            'configuration.colors' => ['array:primary,secondary,accent,background,text'],
            'configuration.colors.*' => $hex,
            'configuration.fonts' => ['array:heading,body'],
            'configuration.fonts.*' => $font,

            'configuration.hero' => ['array:title,subtitle,image_url,cta_label,cta_url'],
            'configuration.hero.title' => ['nullable', 'string', 'max:150'],
            'configuration.hero.subtitle' => ['nullable', 'string', 'max:300'],
            'configuration.hero.image_url' => ['nullable', 'string', 'max:2048', $this->httpsUrl()],
            'configuration.hero.cta_label' => ['nullable', 'string', 'max:40'],
            'configuration.hero.cta_url' => $link,

            'configuration.navigation' => ['array', 'max:10'],
            'configuration.navigation.*' => ['array:label,url'],
            'configuration.navigation.*.label' => ['required', 'string', 'max:40'],
            'configuration.navigation.*.url' => ['required', 'string', 'max:2048', $this->pathOrHttpsUrl()],

            'configuration.footer' => ['array:text,links'],
            'configuration.footer.text' => ['nullable', 'string', 'max:500'],
            'configuration.footer.links' => ['array', 'max:12'],
            'configuration.footer.links.*' => ['array:label,url'],
            'configuration.footer.links.*.label' => ['required', 'string', 'max:40'],
            'configuration.footer.links.*.url' => ['required', 'string', 'max:2048', $this->pathOrHttpsUrl()],

            'configuration.social' => ['array:facebook,instagram,twitter,tiktok,youtube'],
            'configuration.social.*' => ['nullable', 'string', 'max:2048', $this->httpsUrl()],

            'configuration.homepage' => ['array:sections'],
            'configuration.homepage.sections' => ['array', 'max:20'],
            'configuration.homepage.sections.*' => ['array:type,enabled,config'],
            'configuration.homepage.sections.*.type' => ['required', Rule::enum(HomepageSectionType::class)],
            'configuration.homepage.sections.*.enabled' => ['required', 'boolean'],
            'configuration.homepage.sections.*.config' => ['present', 'array:limit,title,items'],
            'configuration.homepage.sections.*.config.items' => ['nullable', 'array', 'max:6'],
            'configuration.homepage.sections.*.config.items.*' => ['array:quote,author'],
            'configuration.homepage.sections.*.config.items.*.quote' => ['required', 'string', 'max:300'],
            'configuration.homepage.sections.*.config.items.*.author' => ['required', 'string', 'max:80'],
            'configuration.homepage.sections.*.config.limit' => ['nullable', 'integer', 'min:1', 'max:24'],
            'configuration.homepage.sections.*.config.title' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function theme(): ?Theme
    {
        $uuid = $this->validated('theme_id');

        return $uuid === null ? null : Theme::query()->where('id', $uuid)->firstOrFail();
    }
}
