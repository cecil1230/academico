@php
    $localeLabels = [
        'zh_CN' => '中文',
        'en' => 'EN',
        'es' => 'ES',
        'fr' => 'FR',
        'pt' => 'PT',
    ];
@endphp
<div class="flex items-center gap-x-1">
    @foreach ($locales as $locale)
        <button
            wire:click="switchLocale('{{ $locale }}')"
            @class([
                'px-2 py-1 text-sm font-medium rounded-md transition-colors',
                'bg-primary-500 text-black underline' => $currentLocale === $locale,
                'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' => $currentLocale !== $locale,
            ])
        >
            {{ $localeLabels[$locale] ?? strtoupper($locale) }}
        </button>
    @endforeach
</div>
